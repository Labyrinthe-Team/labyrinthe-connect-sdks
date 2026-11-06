using System;
using System.Collections.Generic;
using System.Linq;
using System.Net.Http;
using System.Runtime.CompilerServices;
using System.Threading;
using System.Threading.Tasks;
using Labyrinthe.Connect.Internal;

namespace Labyrinthe.Connect.Resources;

/// <summary>Send and track SMS (<c>client.Sms</c>).</summary>
public sealed class SmsResource
{
    /// <summary>Longest body, in characters.</summary>
    public const int MaxBodyLength = 1600;

    /// <summary>Most recipients per send.</summary>
    public const int MaxRecipients = 1000;

    /// <summary>Longest Sender ID.</summary>
    public const int MaxSenderLength = 11;

    /// <summary>Longest reference.</summary>
    public const int MaxReferenceLength = 64;

    /// <summary>Longest idempotency key.</summary>
    public const int MaxIdempotencyKeyLength = 255;

    /// <summary>Most items per page.</summary>
    public const int MaxPerPage = 100;

    private readonly Transport transport;
    private readonly string defaultCountryCode;
    private readonly string? defaultSender;

    internal SmsResource(Transport transport, string defaultCountryCode, string? defaultSender)
    {
        this.transport = transport;
        this.defaultCountryCode = defaultCountryCode;
        this.defaultSender = string.IsNullOrEmpty(defaultSender) ? null : defaultSender;
    }

    /// <summary>Sends one SMS. Asynchronous: the returned message is <c>queued</c>.</summary>
    /// <remarks>Follow its status with webhooks (<c>message.status_changed</c>) or <see cref="GetAsync"/>. An
    /// <c>Idempotency-Key</c> is always sent (a <see cref="Guid"/> when you give none), so network errors are retried
    /// safely: the SMS is never sent twice.</remarks>
    /// <param name="request">Recipient, body and options.</param>
    /// <param name="cancellationToken">Cancels the call.</param>
    /// <exception cref="ArgumentException">Invalid input, detected before any network call.</exception>
    /// <exception cref="LabyrintheException">API, network or timeout error.</exception>
    public async Task<Message> SendAsync(SendSmsRequest request, CancellationToken cancellationToken = default)
    {
        Guard.NotNull(request, nameof(request));

        var target = PhoneNumber.NormalizeOrThrow(request.To, defaultCountryCode, nameof(request.To));
        var batch = await DispatchAsync(
            new KeyValuePair<string, object?>("recipient", target),
            request.Body,
            request.Sender,
            request.Reference,
            request.IdempotencyKey,
            request.StripAccents,
            cancellationToken).ConfigureAwait(false);
        return batch.Messages.Count > 0 ? batch.Messages[0] : throw new UnexpectedResponseException("Unexpected API response: no message was returned.");
    }

    /// <summary>Sends one SMS (see <see cref="SendAsync(SendSmsRequest, CancellationToken)"/>).</summary>
    /// <param name="to">Recipient, in any format.</param>
    /// <param name="body">Text, 1 to 1600 characters.</param>
    /// <param name="cancellationToken">Cancels the call.</param>
    public Task<Message> SendAsync(string to, string body, CancellationToken cancellationToken = default) =>
        SendAsync(new SendSmsRequest { To = to, Body = body }, cancellationToken);

    /// <summary>Sends the same SMS to up to 1000 recipients, all or nothing.</summary>
    /// <remarks>If a single recipient is refused, nothing is sent and a <see cref="RecipientNotSendableException"/>
    /// lists every refused number. Duplicates are merged by the API (one message per distinct number).
    /// <see cref="MessageBatch.Replayed"/> tells whether the API replayed an earlier identical request.</remarks>
    /// <param name="request">Recipients, body and options.</param>
    /// <param name="cancellationToken">Cancels the call.</param>
    /// <exception cref="ArgumentException">Invalid input (numbers are reported by index only), before any network call.</exception>
    /// <exception cref="LabyrintheException">API, network or timeout error.</exception>
    public Task<MessageBatch> SendManyAsync(SendManySmsRequest request, CancellationToken cancellationToken = default)
    {
        Guard.NotNull(request, nameof(request));

        var recipients = request.Recipients ?? throw new ArgumentException("Recipients must be a list of phone numbers.", nameof(request));
        if (recipients.Count == 0)
        {
            throw new ArgumentException("At least one recipient is required.", nameof(request));
        }

        if (recipients.Count > MaxRecipients)
        {
            throw new ArgumentException($"At most {MaxRecipients} recipients per request.", nameof(request));
        }

        var normalized = new List<string>(recipients.Count);
        var invalid = new List<int>();
        for (var i = 0; i < recipients.Count; i++)
        {
            if (PhoneNumber.ToE164(recipients[i], defaultCountryCode) is { } number)
            {
                normalized.Add(number);
            }
            else
            {
                invalid.Add(i);
            }
        }

        if (invalid.Count > 0)
        {
            // Les numéros eux-mêmes ne figurent jamais dans le message (seulement leur position).
            var shown = string.Join(", ", invalid.Take(20)) + (invalid.Count > 20 ? "…" : string.Empty);
            throw new ArgumentException($"Invalid phone number(s) at Recipients index {shown}. Nothing was sent.", nameof(request));
        }

        return DispatchAsync(
            new KeyValuePair<string, object?>("recipients", normalized),
            request.Body,
            request.Sender,
            request.Reference,
            request.IdempotencyKey,
            request.StripAccents,
            cancellationToken);
    }

    /// <summary>Computes encoding, segments and credits without sending anything.</summary>
    /// <param name="request">Body and recipients.</param>
    /// <param name="cancellationToken">Cancels the call.</param>
    public async Task<Estimate> EstimateAsync(EstimateSmsRequest request, CancellationToken cancellationToken = default)
    {
        Guard.NotNull(request, nameof(request));

        AssertBody(request.Body);
        var recipients = request.Recipients ?? Array.Empty<string>();
        if (recipients.Count == 0)
        {
            throw new ArgumentException("At least one recipient is required.", nameof(request));
        }

        // Les numéros invalides sont transmis tels quels : l'API les ignore dans le décompte.
        var body = Json.Write(new[]
        {
            new KeyValuePair<string, object?>("body", request.Body),
            new KeyValuePair<string, object?>("recipients", recipients.Where(r => r is not null).Select(r => PhoneNumber.ToE164(r, defaultCountryCode) ?? r).ToArray()),
            new KeyValuePair<string, object?>("strip_accents", request.StripAccents ? true : null),
        });
        var response = await transport.SendAsync(HttpMethod.Post, "/sms/estimate", RetryMode.Safe, null, body, null, cancellationToken).ConfigureAwait(false);
        return Mapping.ToEstimate(Json.Api.Obj(response.Body, "data")) with { RequestId = response.RequestId };
    }

    /// <summary>One message of the account, by id.</summary>
    /// <param name="id">Message identifier.</param>
    /// <param name="cancellationToken">Cancels the call.</param>
    /// <exception cref="NotFoundException">Unknown message.</exception>
    public async Task<Message> GetAsync(string id, CancellationToken cancellationToken = default)
    {
        if (string.IsNullOrWhiteSpace(id))
        {
            throw new ArgumentException("The message id cannot be empty.", nameof(id));
        }

        var response = await transport.SendAsync(HttpMethod.Get, "/sms/" + Uri.EscapeDataString(id), RetryMode.Safe, null, null, null, cancellationToken).ConfigureAwait(false);
        return Mapping.ToMessage(Json.Api.Obj(response.Body, "data"));
    }

    /// <summary>One page of the messages sent with this API key, newest first.</summary>
    /// <param name="options">Filters and page; every filter is optional.</param>
    /// <param name="cancellationToken">Cancels the call.</param>
    public Task<Page<Message>> ListAsync(ListSmsOptions? options = null, CancellationToken cancellationToken = default)
    {
        options ??= new ListSmsOptions();
        var query = ListQuery(options);
        return FetchPageAsync(query, options.Page, cancellationToken);
    }

    /// <summary>Every message matching the filters, from <see cref="ListSmsOptions.Page"/> to the last page (fetched lazily).</summary>
    /// <param name="options">Filters; every filter is optional.</param>
    /// <param name="cancellationToken">Cancels the enumeration.</param>
    public async IAsyncEnumerable<Message> ListAutoPagingAsync(ListSmsOptions? options = null, [EnumeratorCancellation] CancellationToken cancellationToken = default)
    {
        Page<Message>? page = await ListAsync(options, cancellationToken).ConfigureAwait(false);
        while (page is not null)
        {
            foreach (var message in page.Data)
            {
                yield return message;
            }

            page = await page.GetNextPageAsync(cancellationToken).ConfigureAwait(false);
        }
    }

    /// <inheritdoc/>
    public override string ToString() => $"SmsResource {{ {transport} }}";

    private static void AssertBody(string? body)
    {
        if (string.IsNullOrEmpty(body))
        {
            throw new ArgumentException("The SMS body cannot be empty.", nameof(body));
        }

        if (body!.Length > MaxBodyLength)
        {
            throw new ArgumentException($"The SMS body must be at most {MaxBodyLength} characters.", nameof(body));
        }
    }

    private async Task<MessageBatch> DispatchAsync(
        KeyValuePair<string, object?> target,
        string body,
        string? sender,
        string? reference,
        string? idempotencyKey,
        bool stripAccents,
        CancellationToken cancellationToken)
    {
        AssertBody(body);
        sender ??= defaultSender;
        if (sender is not null && (sender.Length == 0 || sender.Length > MaxSenderLength))
        {
            throw new ArgumentException($"Sender must be 1 to {MaxSenderLength} characters.", nameof(sender));
        }

        if (reference is not null && reference.Length > MaxReferenceLength)
        {
            throw new ArgumentException($"Reference must be at most {MaxReferenceLength} characters.", nameof(reference));
        }

        if (idempotencyKey is not null && (idempotencyKey.Length == 0 || idempotencyKey.Length > MaxIdempotencyKeyLength))
        {
            throw new ArgumentException($"IdempotencyKey must be 1 to {MaxIdempotencyKeyLength} characters.", nameof(idempotencyKey));
        }

        // Générée une fois et réutilisée par chaque relance de cet appel.
        var key = idempotencyKey ?? Guid.NewGuid().ToString();
        var payload = Json.Write(new[]
        {
            target,
            new KeyValuePair<string, object?>("body", body),
            new KeyValuePair<string, object?>("sender", sender),
            new KeyValuePair<string, object?>("reference", string.IsNullOrEmpty(reference) ? null : reference),
            new KeyValuePair<string, object?>("strip_accents", stripAccents ? true : null),
        });
        var headers = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase) { ["Idempotency-Key"] = key };
        var response = await transport.SendAsync(HttpMethod.Post, "/sms", RetryMode.Safe, null, payload, headers, cancellationToken).ConfigureAwait(false);
        return new MessageBatch
        {
            Messages = Mapping.ToMessages(response.Body),
            IdempotencyKey = key,
            Replayed = response.Replayed,
            RequestId = response.RequestId,
        };
    }

    private List<KeyValuePair<string, string>> ListQuery(ListSmsOptions options)
    {
        if (options.Page < 1)
        {
            throw new ArgumentOutOfRangeException(nameof(options), "Page must be >= 1.");
        }

        if (options.PerPage is < 1 or > MaxPerPage)
        {
            throw new ArgumentOutOfRangeException(nameof(options), $"PerPage must be between 1 and {MaxPerPage}.");
        }

        if (options.Status == MessageStatus.Unknown)
        {
            throw new ArgumentException("MessageStatus.Unknown cannot be used as a filter.", nameof(options));
        }

        var query = new List<KeyValuePair<string, string>>();
        void Add(string name, string? value)
        {
            if (value is not null)
            {
                query.Add(new KeyValuePair<string, string>(name, value));
            }
        }

        Add("status", options.Status?.ToApiString());
        Add("recipient", options.Recipient is null ? null : PhoneNumber.ToE164(options.Recipient, defaultCountryCode) ?? options.Recipient);
        Add("reference", options.Reference);
        Add("from", options.From?.Value);
        Add("to", options.To?.Value);
        Add("per_page", options.PerPage.ToString(System.Globalization.CultureInfo.InvariantCulture));
        return query;
    }

    private async Task<Page<Message>> FetchPageAsync(List<KeyValuePair<string, string>> query, int page, CancellationToken cancellationToken)
    {
        var withPage = new List<KeyValuePair<string, string>>(query)
        {
            new("page", page.ToString(System.Globalization.CultureInfo.InvariantCulture)),
        };
        var response = await transport.SendAsync(HttpMethod.Get, "/sms", RetryMode.Safe, withPage, null, null, cancellationToken).ConfigureAwait(false);
        var data = Mapping.ToMessages(response.Body);
        var meta = Json.Api.OptionalObj(response.Body, "meta");
        return new Page<Message>(
            data,
            Json.Api.NullableInt(meta, "current_page") ?? page,
            Json.Api.NullableInt(meta, "last_page") ?? page,
            Json.Api.NullableInt(meta, "per_page") ?? data.Count,
            Json.Api.NullableInt(meta, "total") ?? data.Count,
            (next, token) => FetchPageAsync(query, next, token),
            response.RequestId);
    }
}
