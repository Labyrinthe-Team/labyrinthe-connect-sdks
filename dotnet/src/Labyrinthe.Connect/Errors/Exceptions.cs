using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using System.Text.Json;

namespace Labyrinthe.Connect;

/// <summary>Base class of every error raised by the SDK: catch it to handle any failure in one place.</summary>
/// <remarks>Switch on <see cref="Code"/> or on the type, never on <see cref="Exception.Message"/> (API messages are in
/// French, for humans). Exceptions never contain the API key, OTP codes or full phone numbers.</remarks>
public class LabyrintheException : Exception
{
    private static readonly IReadOnlyDictionary<string, JsonElement> NoDetails =
        new Dictionary<string, JsonElement>(StringComparer.Ordinal);

    /// <summary>Creates an error.</summary>
    /// <param name="message">Human-readable message.</param>
    /// <param name="code">Stable machine-readable code.</param>
    /// <param name="statusCode">HTTP status, or <see langword="null"/> without a response.</param>
    /// <param name="details">Error details.</param>
    public LabyrintheException(string message, string code, int? statusCode = null, IReadOnlyDictionary<string, JsonElement>? details = null)
        : base(message)
    {
        Code = code;
        StatusCode = statusCode;
        Details = details ?? NoDetails;
    }

    /// <summary>Stable machine-readable code, e.g. <c>INSUFFICIENT_CREDITS</c> or <c>TIMEOUT</c>.</summary>
    public string Code { get; }

    /// <summary>HTTP status of the response; <see langword="null"/> without a response (network, timeout…).</summary>
    public int? StatusCode { get; }

    /// <summary>Error details, depending on <see cref="Code"/> (empty when there are none).</summary>
    public IReadOnlyDictionary<string, JsonElement> Details { get; }

    /// <summary>An integer detail, or <see langword="null"/>.</summary>
    /// <param name="key">Detail name.</param>
    protected int? DetailInt(string key) => Details.TryGetValue(key, out var value) ? Internal.Json.ToInt(value) : null;

    /// <summary>A string detail, or <see langword="null"/>.</summary>
    /// <param name="key">Detail name.</param>
    protected string? DetailString(string key) =>
        Details.TryGetValue(key, out var value) && value.ValueKind == JsonValueKind.String ? value.GetString() : null;
}

/// <summary>The API answered with an error (<c>{"error": {"code", "message", "details"}}</c>).</summary>
/// <remarks>Subclasses exist for the codes an integration usually handles; any other code (including codes added to
/// the API later) is raised as a plain <see cref="ApiException"/>.</remarks>
public class ApiException : LabyrintheException
{
    private static readonly IReadOnlyDictionary<string, string> NoHeaders =
        new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);

    /// <summary>Creates an API error.</summary>
    /// <param name="message">Message of the API.</param>
    /// <param name="code">Error code of the API.</param>
    /// <param name="statusCode">HTTP status.</param>
    /// <param name="details">Error details.</param>
    /// <param name="headers">Response headers.</param>
    public ApiException(
        string message,
        string code,
        int statusCode,
        IReadOnlyDictionary<string, JsonElement>? details = null,
        IReadOnlyDictionary<string, string>? headers = null)
        : base(message, code, statusCode, details)
    {
        Headers = headers ?? NoHeaders;
    }

    /// <summary>Response headers (case-insensitive names).</summary>
    public IReadOnlyDictionary<string, string> Headers { get; }

    /// <summary>Request identifier for support (<c>X-Request-Id</c>), when the API provides one.</summary>
    public string? RequestId => Headers.TryGetValue("X-Request-Id", out var id) ? id : null;
}

/// <summary>401 <c>UNAUTHENTICATED</c>: missing, invalid or revoked API key.</summary>
public class AuthenticationException : ApiException
{
    /// <inheritdoc cref="ApiException(string, string, int, IReadOnlyDictionary{string, JsonElement}?, IReadOnlyDictionary{string, string}?)"/>
    public AuthenticationException(string message, string code, int statusCode, IReadOnlyDictionary<string, JsonElement>? details = null, IReadOnlyDictionary<string, string>? headers = null)
        : base(message, code, statusCode, details, headers)
    {
    }
}

/// <summary>403: IP not allowed for this key (<c>FORBIDDEN</c>) or OTP disabled for the account (<c>OTP_DISABLED_FOR_ACCOUNT</c>).</summary>
public class PermissionException : ApiException
{
    /// <inheritdoc cref="ApiException(string, string, int, IReadOnlyDictionary{string, JsonElement}?, IReadOnlyDictionary{string, string}?)"/>
    public PermissionException(string message, string code, int statusCode, IReadOnlyDictionary<string, JsonElement>? details = null, IReadOnlyDictionary<string, string>? headers = null)
        : base(message, code, statusCode, details, headers)
    {
    }
}

/// <summary>404 <c>NOT_FOUND</c>: unknown message or <c>otp_id</c> (or one from another account).</summary>
public class NotFoundException : ApiException
{
    /// <inheritdoc cref="ApiException(string, string, int, IReadOnlyDictionary{string, JsonElement}?, IReadOnlyDictionary{string, string}?)"/>
    public NotFoundException(string message, string code, int statusCode, IReadOnlyDictionary<string, JsonElement>? details = null, IReadOnlyDictionary<string, string>? headers = null)
        : base(message, code, statusCode, details, headers)
    {
    }
}

/// <summary>422 <c>VALIDATION_ERROR</c>: one or more fields are missing or invalid.</summary>
public class ValidationException : ApiException
{
    /// <inheritdoc cref="ApiException(string, string, int, IReadOnlyDictionary{string, JsonElement}?, IReadOnlyDictionary{string, string}?)"/>
    public ValidationException(string message, string code, int statusCode, IReadOnlyDictionary<string, JsonElement>? details = null, IReadOnlyDictionary<string, string>? headers = null)
        : base(message, code, statusCode, details, headers)
    {
        FieldErrors = Details.ToDictionary(
            entry => entry.Key,
            entry => (IReadOnlyList<string>)(entry.Value.ValueKind == JsonValueKind.Array
                ? entry.Value.EnumerateArray().Select(Scalar).Where(m => m is not null).Select(m => m!).ToArray()
                : Scalar(entry.Value) is { } single ? new[] { single } : Array.Empty<string>()),
            StringComparer.Ordinal);
    }

    /// <summary>Error messages per field, e.g. <c>{"body": ["Le champ body est obligatoire."]}</c>.</summary>
    public IReadOnlyDictionary<string, IReadOnlyList<string>> FieldErrors { get; }

    private static string? Scalar(JsonElement value) => value.ValueKind switch
    {
        JsonValueKind.String => value.GetString(),
        JsonValueKind.Number or JsonValueKind.True or JsonValueKind.False => value.GetRawText(),
        _ => null,
    };
}

/// <summary>422 <c>RECIPIENT_NOT_SENDABLE</c>: at least one recipient is invalid, blacklisted or opted out.</summary>
/// <remarks>Sending is all-or-nothing: NOTHING was sent and no credit was used. Remove the <see cref="Rejected"/>
/// numbers and send again.</remarks>
public class RecipientNotSendableException : ApiException
{
    /// <inheritdoc cref="ApiException(string, string, int, IReadOnlyDictionary{string, JsonElement}?, IReadOnlyDictionary{string, string}?)"/>
    public RecipientNotSendableException(string message, string code, int statusCode, IReadOnlyDictionary<string, JsonElement>? details = null, IReadOnlyDictionary<string, string>? headers = null)
        : base(message, code, statusCode, details, headers)
    {
        var rejected = new List<RejectedRecipient>();
        if (Details.TryGetValue("rejected", out var items) && items.ValueKind == JsonValueKind.Array)
        {
            foreach (var item in items.EnumerateArray())
            {
                if (item.ValueKind == JsonValueKind.Object
                    && item.TryGetProperty("recipient", out var recipient)
                    && recipient.ValueKind == JsonValueKind.String)
                {
                    var reason = item.TryGetProperty("reason", out var r) && r.ValueKind == JsonValueKind.String ? r.GetString() : null;
                    rejected.Add(new RejectedRecipient { Recipient = recipient.GetString()!, Reason = ApiValues.ParseRejectionReason(reason) });
                }
            }
        }

        Rejected = rejected;
    }

    /// <summary>Reason of the first rejection.</summary>
    public RejectionReason Reason => ApiValues.ParseRejectionReason(DetailString("reason"));

    /// <summary>Every rejected recipient (SMS sends). Empty for OTP sends.</summary>
    public IReadOnlyList<RejectedRecipient> Rejected { get; }
}

/// <summary>422 <c>SENDER_ID_NOT_APPROVED</c>: the sender is not an approved Sender ID of the account.</summary>
public class SenderIdNotApprovedException : ApiException
{
    /// <inheritdoc cref="ApiException(string, string, int, IReadOnlyDictionary{string, JsonElement}?, IReadOnlyDictionary{string, string}?)"/>
    public SenderIdNotApprovedException(string message, string code, int statusCode, IReadOnlyDictionary<string, JsonElement>? details = null, IReadOnlyDictionary<string, string>? headers = null)
        : base(message, code, statusCode, details, headers)
    {
    }
}

/// <summary>422 <c>INVALID_PHONE</c>: the phone number cannot be used (OTP).</summary>
public class InvalidPhoneException : ApiException
{
    /// <inheritdoc cref="ApiException(string, string, int, IReadOnlyDictionary{string, JsonElement}?, IReadOnlyDictionary{string, string}?)"/>
    public InvalidPhoneException(string message, string code, int statusCode, IReadOnlyDictionary<string, JsonElement>? details = null, IReadOnlyDictionary<string, string>? headers = null)
        : base(message, code, statusCode, details, headers)
    {
    }
}

/// <summary>409 <c>INSUFFICIENT_CREDITS</c>: the balance does not cover the whole send. Nothing was sent.</summary>
public class InsufficientCreditsException : ApiException
{
    /// <inheritdoc cref="ApiException(string, string, int, IReadOnlyDictionary{string, JsonElement}?, IReadOnlyDictionary{string, string}?)"/>
    public InsufficientCreditsException(string message, string code, int statusCode, IReadOnlyDictionary<string, JsonElement>? details = null, IReadOnlyDictionary<string, string>? headers = null)
        : base(message, code, statusCode, details, headers)
    {
    }

    /// <summary>Credits available on the account.</summary>
    public int? Available => DetailInt("available");

    /// <summary>Credits needed for this send.</summary>
    public int? Required => DetailInt("required");
}

/// <summary>409 <c>IDEMPOTENCY_KEY_REUSED</c>: the same <c>Idempotency-Key</c> was used for a different request.</summary>
public class IdempotencyKeyReusedException : ApiException
{
    /// <inheritdoc cref="ApiException(string, string, int, IReadOnlyDictionary{string, JsonElement}?, IReadOnlyDictionary{string, string}?)"/>
    public IdempotencyKeyReusedException(string message, string code, int statusCode, IReadOnlyDictionary<string, JsonElement>? details = null, IReadOnlyDictionary<string, string>? headers = null)
        : base(message, code, statusCode, details, headers)
    {
    }
}

/// <summary>429: too many requests.</summary>
/// <remarks>The SDK already retried <c>TOO_MANY_REQUESTS</c> when it was safe to do so; when you get this error,
/// wait <see cref="RetryAfter"/>.</remarks>
public class RateLimitException : ApiException
{
    /// <inheritdoc cref="ApiException(string, string, int, IReadOnlyDictionary{string, JsonElement}?, IReadOnlyDictionary{string, string}?)"/>
    public RateLimitException(string message, string code, int statusCode, IReadOnlyDictionary<string, JsonElement>? details = null, IReadOnlyDictionary<string, string>? headers = null)
        : base(message, code, statusCode, details, headers)
    {
    }

    /// <summary>How long to wait, from <c>Retry-After</c> (seconds) or <c>details.retry_after_seconds</c>; <see langword="null"/> when unknown.</summary>
    public TimeSpan? RetryAfter
    {
        get
        {
            if (Headers.TryGetValue("Retry-After", out var header)
                && double.TryParse(header.Trim(), NumberStyles.Float, CultureInfo.InvariantCulture, out var seconds)
                && seconds >= 0)
            {
                return TimeSpan.FromSeconds(seconds);
            }

            return DetailInt("retry_after_seconds") is { } value ? TimeSpan.FromSeconds(value) : null;
        }
    }
}

/// <summary>429 <c>OTP_RESEND_TOO_SOON</c>: a code was sent to this number too recently. Never retried; show a countdown of <see cref="RateLimitException.RetryAfter"/>.</summary>
public class OtpResendTooSoonException : RateLimitException
{
    /// <inheritdoc cref="ApiException(string, string, int, IReadOnlyDictionary{string, JsonElement}?, IReadOnlyDictionary{string, string}?)"/>
    public OtpResendTooSoonException(string message, string code, int statusCode, IReadOnlyDictionary<string, JsonElement>? details = null, IReadOnlyDictionary<string, string>? headers = null)
        : base(message, code, statusCode, details, headers)
    {
    }
}

/// <summary>429 <c>OTP_RATE_LIMITED</c>: hourly cap reached for this phone number or the whole account. Never retried.</summary>
public class OtpRateLimitedException : RateLimitException
{
    /// <inheritdoc cref="ApiException(string, string, int, IReadOnlyDictionary{string, JsonElement}?, IReadOnlyDictionary{string, string}?)"/>
    public OtpRateLimitedException(string message, string code, int statusCode, IReadOnlyDictionary<string, JsonElement>? details = null, IReadOnlyDictionary<string, string>? headers = null)
        : base(message, code, statusCode, details, headers)
    {
    }

    /// <summary>The phone number or the whole account.</summary>
    public OtpRateLimitScope Scope => ApiValues.ParseScope(DetailString("scope"));

    /// <summary>The hourly cap that was reached.</summary>
    public int? Limit => DetailInt("limit");
}

/// <summary>5xx, including 503 <c>OTP_SERVICE_DISABLED</c>.</summary>
public class ServiceUnavailableException : ApiException
{
    /// <inheritdoc cref="ApiException(string, string, int, IReadOnlyDictionary{string, JsonElement}?, IReadOnlyDictionary{string, string}?)"/>
    public ServiceUnavailableException(string message, string code, int statusCode, IReadOnlyDictionary<string, JsonElement>? details = null, IReadOnlyDictionary<string, string>? headers = null)
        : base(message, code, statusCode, details, headers)
    {
    }
}

/// <summary>The API could not be reached (DNS, TLS, connection refused…). Code <c>CONNECTION_ERROR</c>.</summary>
/// <remarks>For OTP sends and verifications the request may or may not have been processed: the SDK never retries
/// them after a network error.</remarks>
public class ApiConnectionException : LabyrintheException
{
    /// <summary>Creates a connection error.</summary>
    /// <param name="message">Message (without URL query, key or phone number).</param>
    public ApiConnectionException(string message)
        : this(message, "CONNECTION_ERROR")
    {
    }

    /// <summary>Creates a connection error with a specific code.</summary>
    /// <param name="message">Message.</param>
    /// <param name="code">Code.</param>
    protected ApiConnectionException(string message, string code)
        : base(message, code)
    {
    }
}

/// <summary>An attempt took longer than <see cref="LabyrintheClientOptions.Timeout"/>. Code <c>TIMEOUT</c>.</summary>
/// <remarks>Cancelling your own <see cref="System.Threading.CancellationToken"/> raises an
/// <see cref="OperationCanceledException"/> instead.</remarks>
public class ApiTimeoutException : ApiConnectionException
{
    /// <summary>Creates a timeout error.</summary>
    /// <param name="message">Message.</param>
    public ApiTimeoutException(string message)
        : base(message, "TIMEOUT")
    {
    }
}

/// <summary>A successful response that the SDK cannot read (not JSON, missing field…). Code <c>UNEXPECTED_RESPONSE</c>.</summary>
public class UnexpectedResponseException : LabyrintheException
{
    /// <summary>Creates the error.</summary>
    /// <param name="message">Message.</param>
    /// <param name="statusCode">HTTP status.</param>
    public UnexpectedResponseException(string message, int? statusCode = null)
        : base(message, "UNEXPECTED_RESPONSE", statusCode)
    {
    }
}

/// <summary>A webhook failed verification (missing or wrong signature, timestamp out of tolerance). Answer 400.</summary>
public class SignatureVerificationException : LabyrintheException
{
    /// <summary>Creates the error.</summary>
    /// <param name="message">Message.</param>
    public SignatureVerificationException(string message)
        : base(message, "SIGNATURE_VERIFICATION_FAILED")
    {
    }
}

/// <summary>A correctly signed webhook body is not a valid event envelope. Answer 400.</summary>
public class InvalidWebhookPayloadException : LabyrintheException
{
    /// <summary>Creates the error.</summary>
    /// <param name="message">Message.</param>
    public InvalidWebhookPayloadException(string message)
        : base(message, "INVALID_WEBHOOK_PAYLOAD")
    {
    }
}
