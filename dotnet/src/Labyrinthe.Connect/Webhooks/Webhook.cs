using System;
using System.Collections.Generic;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Labyrinthe.Connect.Internal;

namespace Labyrinthe.Connect;

/// <summary>Webhook verification and typed events.</summary>
/// <example>
/// <code>
/// var evt = Webhook.ConstructEvent(rawBody, request.Headers["X-Signature"], secret);
/// if (evt is MessageStatusChanged changed) { UpdateMessage(changed.Data.MessageId, changed.Data.Status); }
/// </code>
/// </example>
/// <remarks>The signature (<c>X-Signature: sha256=&lt;hex HMAC-SHA256 of the raw body&gt;</c>) is checked in constant time
/// on the RAW body, before any JSON parsing; then the signed <c>timestamp</c> must be within the tolerance (replay
/// protection). Deduplicate on <see cref="WebhookEvent.Id"/>: deliveries are retried up to 5 times and may arrive out
/// of order.</remarks>
public static class Webhook
{
    /// <summary>Name of the signature header.</summary>
    public const string SignatureHeader = "X-Signature";

    /// <summary>Default maximum age of a delivery: 5 minutes.</summary>
    public static readonly TimeSpan DefaultTolerance = TimeSpan.FromMinutes(5);

    private const string Prefix = "sha256=";

    private static readonly Json Reader = new("Invalid webhook payload", message => new InvalidWebhookPayloadException(message));

    /// <summary>Verifies a delivery and returns the typed event.</summary>
    /// <param name="rawBody">The exact request body, decoded as UTF-8 — never a re-serialised object.</param>
    /// <param name="signatureHeader">Value of the <c>X-Signature</c> header (<see langword="null"/> when missing).</param>
    /// <param name="secret">The endpoint secret (client area &gt; Developers &gt; Webhooks).</param>
    /// <param name="tolerance">Max age of the signed timestamp (default 5 minutes); <see cref="TimeSpan.Zero"/> disables the check (not recommended).</param>
    /// <exception cref="SignatureVerificationException">Wrong or missing signature, or timestamp out of tolerance — answer 400.</exception>
    /// <exception cref="InvalidWebhookPayloadException">Signed body that is not a valid envelope — answer 400.</exception>
    public static WebhookEvent ConstructEvent(string rawBody, string? signatureHeader, string secret, TimeSpan? tolerance = null)
    {
        Guard.NotNull(rawBody, nameof(rawBody));

        return ConstructEvent(Encoding.UTF8.GetBytes(rawBody), signatureHeader, secret, tolerance);
    }

    /// <summary>Verifies a delivery and returns the typed event (raw bytes of the body, the safest input).</summary>
    /// <param name="rawBody">The exact request body.</param>
    /// <param name="signatureHeader">Value of the <c>X-Signature</c> header.</param>
    /// <param name="secret">The endpoint secret.</param>
    /// <param name="tolerance">Max age of the signed timestamp (default 5 minutes); <see cref="TimeSpan.Zero"/> disables the check.</param>
    /// <exception cref="SignatureVerificationException">Wrong or missing signature, or timestamp out of tolerance.</exception>
    /// <exception cref="InvalidWebhookPayloadException">Signed body that is not a valid envelope.</exception>
    public static WebhookEvent ConstructEvent(byte[] rawBody, string? signatureHeader, string secret, TimeSpan? tolerance = null) =>
        ConstructEvent(rawBody, signatureHeader, secret, tolerance, DateTimeOffset.UtcNow);

    /// <summary>Constant-time check of the <c>X-Signature</c> header against the raw body.</summary>
    /// <param name="rawBody">The exact request body.</param>
    /// <param name="signatureHeader">Value of the <c>X-Signature</c> header.</param>
    /// <param name="secret">The endpoint secret.</param>
    public static bool VerifySignature(byte[] rawBody, string? signatureHeader, string secret)
    {
        Guard.NotNull(rawBody, nameof(rawBody));

        var expected = Digest(rawBody, secret);
        var signature = (signatureHeader ?? string.Empty).Trim();
        if (!signature.StartsWith(Prefix, StringComparison.OrdinalIgnoreCase) || signature.Length != Prefix.Length + 64)
        {
            return false;
        }

        var provided = new byte[32];
        for (var i = 0; i < 32; i++)
        {
            var high = HexValue(signature[Prefix.Length + (2 * i)]);
            var low = HexValue(signature[Prefix.Length + (2 * i) + 1]);
            if (high < 0 || low < 0)
            {
                return false;
            }

            provided[i] = (byte)((high << 4) | low);
        }

        return FixedTimeEquals(provided, expected);
    }

    /// <summary>The <c>X-Signature</c> header value for a body: <c>sha256=&lt;hex&gt;</c>. Useful to test your endpoint.</summary>
    /// <param name="rawBody">The body.</param>
    /// <param name="secret">The endpoint secret.</param>
    public static string Sign(byte[] rawBody, string secret)
    {
        Guard.NotNull(rawBody, nameof(rawBody));

        var digest = Digest(rawBody, secret);
        var builder = new StringBuilder(Prefix, Prefix.Length + 64);
        foreach (var b in digest)
        {
            builder.Append(b.ToString("x2", System.Globalization.CultureInfo.InvariantCulture));
        }

        return builder.ToString();
    }

    /// <summary>The <c>X-Signature</c> header value for a body (encoded as UTF-8).</summary>
    /// <param name="rawBody">The body.</param>
    /// <param name="secret">The endpoint secret.</param>
    public static string Sign(string rawBody, string secret)
    {
        Guard.NotNull(rawBody, nameof(rawBody));

        return Sign(Encoding.UTF8.GetBytes(rawBody), secret);
    }

    internal static WebhookEvent ConstructEvent(byte[] rawBody, string? signatureHeader, string secret, TimeSpan? tolerance, DateTimeOffset now)
    {
        Guard.NotNull(rawBody, nameof(rawBody));

        var maxAge = tolerance ?? DefaultTolerance;
        if (maxAge < TimeSpan.Zero)
        {
            throw new ArgumentOutOfRangeException(nameof(tolerance), "The tolerance cannot be negative.");
        }

        if (!VerifySignature(rawBody, signatureHeader, secret))
        {
            throw new SignatureVerificationException("Invalid webhook signature: the body was not signed with this secret.");
        }

        var envelope = Json.TryParse(rawBody);
        if (envelope is not { ValueKind: JsonValueKind.Object } root)
        {
            throw new InvalidWebhookPayloadException("The webhook body is not a JSON object.");
        }

        var timestamp = Reader.Long(root, "timestamp");
        if (maxAge > TimeSpan.Zero && Math.Abs(now.ToUnixTimeSeconds() - timestamp) > maxAge.TotalSeconds)
        {
            throw new SignatureVerificationException($"Webhook timestamp is outside the {maxAge.TotalSeconds:0.###} s tolerance (possible replay).");
        }

        return Build(root, timestamp);
    }

    private static WebhookEvent Build(JsonElement envelope, long timestamp)
    {
        var id = Reader.String(envelope, "id");
        var name = Reader.String(envelope, "event");
        var data = Reader.OptionalObj(envelope, "data");
        DateTimeOffset sentAt;
        try
        {
            sentAt = DateTimeOffset.FromUnixTimeSeconds(timestamp);
        }
        catch (ArgumentOutOfRangeException)
        {
            throw new InvalidWebhookPayloadException("Invalid webhook payload: field \"timestamp\" is out of range.");
        }

        switch (ApiValues.ParseEventType(name))
        {
            case WebhookEventType.MessageStatusChanged:
                return new MessageStatusChanged
                {
                    Id = id,
                    Type = name,
                    Timestamp = sentAt,
                    Raw = envelope,
                    Data = new MessageStatusChangedData
                    {
                        MessageId = Reader.String(data, "message_id"),
                        Recipient = Reader.String(data, "recipient"),
                        Status = ApiValues.ParseMessageStatus(Reader.String(data, "status")),
                        ProviderMessageId = Reader.NullableString(data, "provider_message_id"),
                        CampaignId = Reader.NullableString(data, "campaign_id"),
                    },
                };
            case WebhookEventType.CampaignCompleted:
                var counts = new Dictionary<string, int>(StringComparer.Ordinal);
                var byStatus = Reader.OptionalObj(data, "messages_by_status");
                foreach (var entry in byStatus.EnumerateObject())
                {
                    counts[entry.Name] = Reader.Int(byStatus, entry.Name);
                }

                return new CampaignCompleted
                {
                    Id = id,
                    Type = name,
                    Timestamp = sentAt,
                    Raw = envelope,
                    Data = new CampaignCompletedData
                    {
                        CampaignId = Reader.String(data, "campaign_id"),
                        Name = Reader.NullableString(data, "name") ?? string.Empty,
                        RecipientsCount = Reader.NullableInt(data, "recipients_count") ?? 0,
                        MessagesByStatus = counts,
                        CreditsCharged = Reader.NullableInt(data, "credits_charged") ?? 0,
                        CompletedAt = Reader.NullableDate(data, "completed_at"),
                    },
                };
            case WebhookEventType.BalanceLow:
                return new BalanceLow
                {
                    Id = id,
                    Type = name,
                    Timestamp = sentAt,
                    Raw = envelope,
                    Data = new BalanceLowData { Balance = Reader.Int(data, "balance"), Threshold = Reader.Int(data, "threshold") },
                };
            case WebhookEventType.TestPing:
                return new TestPing { Id = id, Type = name, Timestamp = sentAt, Raw = envelope, Data = data };
            default:
                return new UnknownEvent { Id = id, Type = name, Timestamp = sentAt, Raw = envelope, Data = data };
        }
    }

    private static byte[] Digest(byte[] body, string secret)
    {
        if (string.IsNullOrEmpty(secret))
        {
            throw new ArgumentException("The webhook secret cannot be empty.", nameof(secret));
        }

        var key = Encoding.UTF8.GetBytes(secret);
#if NET8_0_OR_GREATER
        return HMACSHA256.HashData(key, body);
#else
        using var hmac = new HMACSHA256(key);
        return hmac.ComputeHash(body);
#endif
    }

    private static int HexValue(char c) => c switch
    {
        >= '0' and <= '9' => c - '0',
        >= 'a' and <= 'f' => c - 'a' + 10,
        >= 'A' and <= 'F' => c - 'A' + 10,
        _ => -1,
    };

#if NET8_0_OR_GREATER
    private static bool FixedTimeEquals(byte[] left, byte[] right) => CryptographicOperations.FixedTimeEquals(left, right);
#else
    /// <summary>Comparaison en temps constant (repli de <c>CryptographicOperations.FixedTimeEquals</c> pour netstandard2.0).</summary>
    [System.Runtime.CompilerServices.MethodImpl(System.Runtime.CompilerServices.MethodImplOptions.NoInlining | System.Runtime.CompilerServices.MethodImplOptions.NoOptimization)]
    private static bool FixedTimeEquals(byte[] left, byte[] right)
    {
        if (left.Length != right.Length)
        {
            return false;
        }

        var difference = 0;
        for (var i = 0; i < left.Length; i++)
        {
            difference |= left[i] ^ right[i];
        }

        return difference == 0;
    }
#endif
}
