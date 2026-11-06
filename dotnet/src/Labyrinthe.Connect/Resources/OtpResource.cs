using System;
using System.Collections.Generic;
using System.Net.Http;
using System.Text.RegularExpressions;
using System.Threading;
using System.Threading.Tasks;
using Labyrinthe.Connect.Internal;

namespace Labyrinthe.Connect.Resources;

/// <summary>One-time passwords by SMS (<c>client.Otp</c>).</summary>
/// <remarks>
/// <list type="number">
/// <item><see cref="SendAsync(string, CancellationToken)"/> and keep <see cref="OtpSendResult.OtpId"/> server-side (session);</item>
/// <item>the user types the code received by SMS;</item>
/// <item><see cref="VerifyAsync"/>: log the user in when <see cref="OtpVerifyResult.Verified"/> is true.</item>
/// </list>
/// Code length, validity, attempts, sender and template come from the account's OTP settings.
/// </remarks>
public sealed class OtpResource
{
    /// <summary>Longest template.</summary>
    public const int MaxTemplateLength = 1600;

    /// <summary>Longest code accepted by <see cref="VerifyAsync"/>.</summary>
    public const int MaxCodeLength = 20;

    /// <summary>Longest <see cref="SendOtpRequest.WebOtpDomain"/>.</summary>
    public const int MaxWebOtpDomainLength = 253;

    /// <summary>Longest idempotency key.</summary>
    public const int MaxIdempotencyKeyLength = 255;

    /// <summary>Nom d'hôte d'au moins deux libellés, sans schéma, port ni chemin (motif de la spec, longueur vérifiée à part).</summary>
    private static readonly Regex HostName = new(
        @"^([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$",
        RegexOptions.CultureInvariant,
        TimeSpan.FromSeconds(1));

    private readonly Transport transport;
    private readonly string defaultCountryCode;

    internal OtpResource(Transport transport, string defaultCountryCode)
    {
        this.transport = transport;
        this.defaultCountryCode = defaultCountryCode;
    }

    /// <summary>Generates a code and sends it by SMS (billed like any SMS).</summary>
    /// <remarks>A new send to the same number invalidates the previous code. An <c>Idempotency-Key</c> is always sent (a
    /// <see cref="Guid"/> when you give none) and reused by every retry, so the send is retried safely after a network
    /// error, a 5xx, 429 <c>TOO_MANY_REQUESTS</c> or 409 <c>IDEMPOTENCY_REQUEST_IN_PROGRESS</c>: a replay returns the
    /// same <see cref="OtpSendResult.OtpId"/> (<see cref="OtpSendResult.Replayed"/>) without a second SMS.
    /// <c>OTP_RESEND_TOO_SOON</c> and <c>OTP_RATE_LIMITED</c> are never retried. 503 <c>FEATURE_DISABLED</c> /
    /// <c>OTP_SERVICE_DISABLED</c> (<see cref="FeatureDisabledException"/>) is retried only after its <c>Retry-After</c>,
    /// when it is no longer than <see cref="LabyrintheClientOptions.MaxRetryWait"/>.
    /// With a test key, <see cref="OtpSendResult.TestCode"/> holds the code.</remarks>
    /// <param name="request">Phone number and options.</param>
    /// <param name="cancellationToken">Cancels the call.</param>
    /// <exception cref="ArgumentException">Invalid input, detected before any network call.</exception>
    /// <exception cref="OtpResendTooSoonException">A code was sent to this number too recently.</exception>
    /// <exception cref="OtpRateLimitedException">Hourly cap reached.</exception>
    public async Task<OtpSendResult> SendAsync(SendOtpRequest request, CancellationToken cancellationToken = default)
    {
        Guard.NotNull(request, nameof(request));

        var phone = PhoneNumber.NormalizeOrThrow(request.Phone, defaultCountryCode, nameof(request.Phone));
        if (request.Template is not null)
        {
            if (request.Template.IndexOf("{code}", StringComparison.Ordinal) < 0)
            {
                throw new ArgumentException("The OTP template must contain the {code} token.", nameof(request));
            }

            if (request.Template.Length > MaxTemplateLength)
            {
                throw new ArgumentException($"The OTP template must be at most {MaxTemplateLength} characters.", nameof(request));
            }
        }

        if (request.Locale is { } locale && locale is not (OtpLocale.Fr or OtpLocale.En))
        {
            throw new ArgumentException("Locale must be Fr or En.", nameof(request));
        }

        var domain = request.WebOtpDomain?.Trim();
        if (domain is not null && (domain.Length is 0 or > MaxWebOtpDomainLength || !HostName.IsMatch(domain)))
        {
            throw new ArgumentException("WebOtpDomain must be a host name such as app.example.com (no scheme, port or path).", nameof(request));
        }

        var idempotencyKey = request.IdempotencyKey;
        if (idempotencyKey is not null && (idempotencyKey.Length == 0 || idempotencyKey.Length > MaxIdempotencyKeyLength))
        {
            throw new ArgumentException($"IdempotencyKey must be 1 to {MaxIdempotencyKeyLength} characters.", nameof(request));
        }

        var body = Json.Write(new[]
        {
            new KeyValuePair<string, object?>("phone", phone),
            new KeyValuePair<string, object?>("template", request.Template),
            new KeyValuePair<string, object?>("locale", request.Locale?.ToApiString()),
            new KeyValuePair<string, object?>("web_otp_domain", domain),
            new KeyValuePair<string, object?>("strip_accents", request.StripAccents ? true : null),
        });

        // Générée une fois et réutilisée par chaque relance : un rejeu ne renvoie jamais un second SMS.
        var key = idempotencyKey ?? Guid.NewGuid().ToString();
        var headers = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase) { ["Idempotency-Key"] = key };
        var response = await transport.SendAsync(HttpMethod.Post, "/otp/send", RetryMode.Safe, null, body, headers, cancellationToken).ConfigureAwait(false);
        return Mapping.ToOtpSendResult(Json.Api.Obj(response.Body, "data")) with
        {
            IdempotencyKey = key,
            Replayed = response.Replayed,
            RequestId = response.RequestId,
        };
    }

    /// <summary>Generates a code and sends it by SMS, with the account's template and language.</summary>
    /// <param name="phone">Phone number, in any format.</param>
    /// <param name="cancellationToken">Cancels the call.</param>
    public Task<OtpSendResult> SendAsync(string phone, CancellationToken cancellationToken = default) =>
        SendAsync(new SendOtpRequest { Phone = phone }, cancellationToken);

    /// <summary>Checks a code. A wrong code is NOT an exception: <see cref="OtpVerifyResult.Verified"/> is false with a reason.</summary>
    /// <remarks>One attempt is consumed per wrong code (<see cref="OtpVerifyResult.AttemptsRemaining"/>). Free. Retried
    /// only on 429 <c>TOO_MANY_REQUESTS</c> and on 503 <c>FEATURE_DISABLED</c> after its <c>Retry-After</c> (both refused
    /// before processing), never after a network error or another 5xx (an attempt may have been consumed).</remarks>
    /// <param name="otpId">The <see cref="OtpSendResult.OtpId"/> kept server-side.</param>
    /// <param name="code">The code typed by the user (spaces and case are ignored by the API).</param>
    /// <param name="cancellationToken">Cancels the call.</param>
    /// <exception cref="NotFoundException">Unknown <paramref name="otpId"/>.</exception>
    public async Task<OtpVerifyResult> VerifyAsync(string otpId, string code, CancellationToken cancellationToken = default)
    {
        if (string.IsNullOrWhiteSpace(otpId))
        {
            throw new ArgumentException("otpId cannot be empty.", nameof(otpId));
        }

        if (string.IsNullOrWhiteSpace(code) || code.Length > MaxCodeLength)
        {
            // Le code saisi n'apparaît jamais dans le message.
            throw new ArgumentException($"The code must be 1 to {MaxCodeLength} characters.", nameof(code));
        }

        var body = Json.Write(new[]
        {
            new KeyValuePair<string, object?>("otp_id", otpId),
            new KeyValuePair<string, object?>("code", code),
        });
        var response = await transport.SendAsync(HttpMethod.Post, "/otp/verify", RetryMode.ThrottledOnly, null, body, null, cancellationToken).ConfigureAwait(false);
        return Mapping.ToOtpVerifyResult(Json.Api.Obj(response.Body, "data")) with { RequestId = response.RequestId };
    }

    /// <inheritdoc/>
    public override string ToString() => $"OtpResource {{ {transport} }}";
}
