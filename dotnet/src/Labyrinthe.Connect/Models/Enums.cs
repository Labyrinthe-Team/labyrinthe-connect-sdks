namespace Labyrinthe.Connect;

/// <summary>Lifecycle of a message.</summary>
/// <remarks><see cref="Unknown"/> is never sent by the API: the SDK uses it for a value this version does not know yet.</remarks>
public enum MessageStatus
{
    /// <summary>Status not known by this SDK version.</summary>
    Unknown = 0,

    /// <summary><c>queued</c>: queued at Labyrinthe Connect.</summary>
    Queued,

    /// <summary><c>submitted</c>: handed over to the SMS provider.</summary>
    Submitted,

    /// <summary><c>sent</c>: accepted by the mobile operator.</summary>
    Sent,

    /// <summary><c>delivered</c>: delivered to the handset (final).</summary>
    Delivered,

    /// <summary><c>failed</c>: failed (final).</summary>
    Failed,

    /// <summary><c>rejected</c>: rejected by the provider or the operator (final).</summary>
    Rejected,

    /// <summary><c>expired</c>: expired before delivery (final).</summary>
    Expired,

    /// <summary><c>undeliverable</c>: cannot be delivered to this number (final).</summary>
    Undeliverable,
}

/// <summary>Character encoding of an SMS: GSM-7 (160 characters) or UCS-2 (70 characters).</summary>
public enum SmsEncoding
{
    /// <summary>Encoding not known by this SDK version.</summary>
    Unknown = 0,

    /// <summary><c>gsm7</c>: pure ASCII, 160 characters (153 per segment beyond).</summary>
    Gsm7,

    /// <summary><c>ucs2</c>: any non-ASCII character, 70 characters (67 per segment beyond).</summary>
    Ucs2,
}

/// <summary>Review status of a Sender ID.</summary>
public enum SenderIdStatus
{
    /// <summary>Status not known by this SDK version.</summary>
    Unknown = 0,

    /// <summary><c>pending</c>.</summary>
    Pending,

    /// <summary><c>pending_provider</c>.</summary>
    PendingProvider,

    /// <summary><c>approved</c>: usable as <c>Sender</c>.</summary>
    Approved,

    /// <summary><c>rejected</c>.</summary>
    Rejected,

    /// <summary><c>suspended</c>.</summary>
    Suspended,
}

/// <summary>Why a recipient was refused (<see cref="RecipientNotSendableException"/>).</summary>
public enum RejectionReason
{
    /// <summary>Reason not known by this SDK version.</summary>
    Unknown = 0,

    /// <summary><c>invalid_phone</c>.</summary>
    InvalidPhone,

    /// <summary><c>blacklisted</c>.</summary>
    Blacklisted,

    /// <summary><c>opted_out</c>.</summary>
    OptedOut,
}

/// <summary>Why an OTP verification failed (<see cref="OtpVerifyResult.Verified"/> is false).</summary>
public enum OtpFailureReason
{
    /// <summary>Reason not known by this SDK version.</summary>
    Unknown = 0,

    /// <summary><c>invalid_code</c>: wrong code; one attempt was consumed, the user may try again.</summary>
    InvalidCode,

    /// <summary><c>expired</c>: the code expired; send a new one.</summary>
    Expired,

    /// <summary><c>already_used</c>: the code was already verified (each code is single-use).</summary>
    AlreadyUsed,

    /// <summary><c>max_attempts_reached</c>: too many wrong attempts; the code is permanently blocked.</summary>
    MaxAttemptsReached,
}

/// <summary>Scope of an <c>OTP_RATE_LIMITED</c> error: the phone number or the whole account.</summary>
public enum OtpRateLimitScope
{
    /// <summary>Scope not known by this SDK version.</summary>
    Unknown = 0,

    /// <summary><c>phone</c>: hourly cap of this phone number.</summary>
    Phone,

    /// <summary><c>account</c>: hourly cap of the whole account.</summary>
    Account,
}

/// <summary>Languages of the standard OTP templates.</summary>
public enum OtpLocale
{
    /// <summary><c>fr</c>: French.</summary>
    Fr = 1,

    /// <summary><c>en</c>: English.</summary>
    En,
}

/// <summary>Webhook event types emitted by the API.</summary>
public enum WebhookEventType
{
    /// <summary>An event type added to the API after this SDK version (<see cref="UnknownEvent"/>).</summary>
    Unknown = 0,

    /// <summary><c>message.status_changed</c>.</summary>
    MessageStatusChanged,

    /// <summary><c>campaign.completed</c>.</summary>
    CampaignCompleted,

    /// <summary><c>balance.low</c>.</summary>
    BalanceLow,

    /// <summary><c>test.ping</c>.</summary>
    TestPing,

    /// <summary><c>otp.verified</c> (API 1.2.0).</summary>
    OtpVerified,

    /// <summary><c>otp.failed</c> (API 1.2.0).</summary>
    OtpFailed,
}

/// <summary>Alphabet of an OTP code (<see cref="OtpSendResult.CodeType"/>).</summary>
public enum OtpCodeType
{
    /// <summary>Type not known by this SDK version (or not returned by an older API).</summary>
    Unknown = 0,

    /// <summary><c>numeric</c>: digits 0-9.</summary>
    Numeric,

    /// <summary><c>alphanumeric</c>: upper-case letters and digits without ambiguous characters; typing is case-insensitive.</summary>
    Alphanumeric,
}

/// <summary>Outcome of an OTP verification in the <c>otp.verified</c> / <c>otp.failed</c> webhooks.</summary>
public enum OtpWebhookStatus
{
    /// <summary>Status not known by this SDK version.</summary>
    Unknown = 0,

    /// <summary><c>verified</c>: the right code was given (<c>otp.verified</c>).</summary>
    Verified,

    /// <summary><c>failed</c>: attempts exhausted (<c>otp.failed</c>, reason <c>max_attempts_reached</c>).</summary>
    Failed,

    /// <summary><c>expired</c>: the code expired (<c>otp.failed</c>, reason <c>expired</c>).</summary>
    Expired,
}
