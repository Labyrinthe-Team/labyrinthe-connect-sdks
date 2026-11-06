using System;
using System.Collections.Generic;

namespace Labyrinthe.Connect;

/// <summary>Conversions between the SDK enums and the values used by the API (<c>"queued"</c>, <c>"gsm7"</c>…).</summary>
public static class ApiValues
{
    private static readonly EnumMap<MessageStatus> MessageStatuses = new(
        (MessageStatus.Queued, "queued"),
        (MessageStatus.Submitted, "submitted"),
        (MessageStatus.Sent, "sent"),
        (MessageStatus.Delivered, "delivered"),
        (MessageStatus.Failed, "failed"),
        (MessageStatus.Rejected, "rejected"),
        (MessageStatus.Expired, "expired"),
        (MessageStatus.Undeliverable, "undeliverable"));

    private static readonly EnumMap<SmsEncoding> Encodings = new((SmsEncoding.Gsm7, "gsm7"), (SmsEncoding.Ucs2, "ucs2"));

    private static readonly EnumMap<SenderIdStatus> SenderIdStatuses = new(
        (SenderIdStatus.Pending, "pending"),
        (SenderIdStatus.PendingProvider, "pending_provider"),
        (SenderIdStatus.Approved, "approved"),
        (SenderIdStatus.Rejected, "rejected"),
        (SenderIdStatus.Suspended, "suspended"));

    private static readonly EnumMap<RejectionReason> RejectionReasons = new(
        (RejectionReason.InvalidPhone, "invalid_phone"),
        (RejectionReason.Blacklisted, "blacklisted"),
        (RejectionReason.OptedOut, "opted_out"));

    private static readonly EnumMap<OtpFailureReason> OtpFailureReasons = new(
        (OtpFailureReason.InvalidCode, "invalid_code"),
        (OtpFailureReason.Expired, "expired"),
        (OtpFailureReason.AlreadyUsed, "already_used"),
        (OtpFailureReason.MaxAttemptsReached, "max_attempts_reached"));

    private static readonly EnumMap<OtpRateLimitScope> Scopes = new((OtpRateLimitScope.Phone, "phone"), (OtpRateLimitScope.Account, "account"));

    private static readonly EnumMap<OtpLocale> Locales = new((OtpLocale.Fr, "fr"), (OtpLocale.En, "en"));

    private static readonly EnumMap<WebhookEventType> EventTypes = new(
        (WebhookEventType.MessageStatusChanged, "message.status_changed"),
        (WebhookEventType.CampaignCompleted, "campaign.completed"),
        (WebhookEventType.BalanceLow, "balance.low"),
        (WebhookEventType.TestPing, "test.ping"),
        (WebhookEventType.OtpVerified, "otp.verified"),
        (WebhookEventType.OtpFailed, "otp.failed"));

    private static readonly EnumMap<OtpCodeType> CodeTypes = new((OtpCodeType.Numeric, "numeric"), (OtpCodeType.Alphanumeric, "alphanumeric"));

    private static readonly EnumMap<OtpWebhookStatus> OtpWebhookStatuses = new(
        (OtpWebhookStatus.Verified, "verified"),
        (OtpWebhookStatus.Failed, "failed"),
        (OtpWebhookStatus.Expired, "expired"));

    /// <summary>The API value: <c>"numeric"</c> or <c>"alphanumeric"</c>.</summary>
    /// <param name="value">The enum value.</param>
    public static string ToApiString(this OtpCodeType value) => CodeTypes.ToApi(value);

    /// <summary>The API value: <c>"verified"</c>, <c>"failed"</c> or <c>"expired"</c>.</summary>
    /// <param name="value">The enum value.</param>
    public static string ToApiString(this OtpWebhookStatus value) => OtpWebhookStatuses.ToApi(value);

    /// <summary>The API value, e.g. <c>"delivered"</c>; <c>"unknown"</c> for <see cref="MessageStatus.Unknown"/>.</summary>
    /// <param name="value">The enum value.</param>
    public static string ToApiString(this MessageStatus value) => MessageStatuses.ToApi(value);

    /// <summary>The API value: <c>"gsm7"</c> or <c>"ucs2"</c>.</summary>
    /// <param name="value">The enum value.</param>
    public static string ToApiString(this SmsEncoding value) => Encodings.ToApi(value);

    /// <summary>The API value, e.g. <c>"pending_provider"</c>.</summary>
    /// <param name="value">The enum value.</param>
    public static string ToApiString(this SenderIdStatus value) => SenderIdStatuses.ToApi(value);

    /// <summary>The API value, e.g. <c>"opted_out"</c>.</summary>
    /// <param name="value">The enum value.</param>
    public static string ToApiString(this RejectionReason value) => RejectionReasons.ToApi(value);

    /// <summary>The API value, e.g. <c>"already_used"</c>.</summary>
    /// <param name="value">The enum value.</param>
    public static string ToApiString(this OtpFailureReason value) => OtpFailureReasons.ToApi(value);

    /// <summary>The API value: <c>"phone"</c> or <c>"account"</c>.</summary>
    /// <param name="value">The enum value.</param>
    public static string ToApiString(this OtpRateLimitScope value) => Scopes.ToApi(value);

    /// <summary>The API value: <c>"fr"</c> or <c>"en"</c>.</summary>
    /// <param name="value">The enum value.</param>
    public static string ToApiString(this OtpLocale value) => Locales.ToApi(value);

    /// <summary>The event name, e.g. <c>"message.status_changed"</c>.</summary>
    /// <param name="value">The enum value.</param>
    public static string ToApiString(this WebhookEventType value) => EventTypes.ToApi(value);

    /// <summary>True when the status will not change any more (delivered or failed).</summary>
    /// <param name="status">A message status.</param>
    public static bool IsFinal(this MessageStatus status) => status == MessageStatus.Delivered || status.IsFailed();

    /// <summary>True for <c>failed</c>, <c>rejected</c>, <c>expired</c> and <c>undeliverable</c>.</summary>
    /// <param name="status">A message status.</param>
    public static bool IsFailed(this MessageStatus status) =>
        status is MessageStatus.Failed or MessageStatus.Rejected or MessageStatus.Expired or MessageStatus.Undeliverable;

    /// <summary>True for <c>queued</c>, <c>submitted</c> and <c>sent</c>.</summary>
    /// <param name="status">A message status.</param>
    public static bool IsPending(this MessageStatus status) =>
        status is MessageStatus.Queued or MessageStatus.Submitted or MessageStatus.Sent;

    internal static MessageStatus ParseMessageStatus(string? value) => MessageStatuses.Parse(value);

    internal static bool TryParseMessageStatus(string value, out MessageStatus status) => MessageStatuses.TryParse(value, out status);

    internal static SmsEncoding ParseEncoding(string? value) => Encodings.Parse(value);

    internal static SenderIdStatus ParseSenderIdStatus(string? value) => SenderIdStatuses.Parse(value);

    internal static RejectionReason ParseRejectionReason(string? value) => RejectionReasons.Parse(value);

    internal static OtpFailureReason ParseOtpFailureReason(string? value) => OtpFailureReasons.Parse(value);

    internal static OtpRateLimitScope ParseScope(string? value) => Scopes.Parse(value);

    internal static WebhookEventType ParseEventType(string? value) => EventTypes.Parse(value);

    internal static OtpCodeType ParseOtpCodeType(string? value) => CodeTypes.Parse(value);

    internal static OtpWebhookStatus ParseOtpWebhookStatus(string? value) => OtpWebhookStatuses.Parse(value);

    /// <summary>Every known API value of an enum (contract tests).</summary>
    internal static IReadOnlyCollection<string> Known<TEnum>()
        where TEnum : struct, Enum
    {
        object map = typeof(TEnum) switch
        {
            var t when t == typeof(MessageStatus) => MessageStatuses,
            var t when t == typeof(SmsEncoding) => Encodings,
            var t when t == typeof(SenderIdStatus) => SenderIdStatuses,
            var t when t == typeof(RejectionReason) => RejectionReasons,
            var t when t == typeof(OtpFailureReason) => OtpFailureReasons,
            var t when t == typeof(OtpRateLimitScope) => Scopes,
            var t when t == typeof(OtpLocale) => Locales,
            var t when t == typeof(WebhookEventType) => EventTypes,
            var t when t == typeof(OtpCodeType) => CodeTypes,
            var t when t == typeof(OtpWebhookStatus) => OtpWebhookStatuses,
            _ => throw new ArgumentException("Not an API enum.", nameof(TEnum)),
        };
        return ((EnumMap<TEnum>)map).Values;
    }

    /// <summary>Table de correspondance énumération ↔ valeur API ; valeur inconnue → <c>default</c> (Unknown).</summary>
    private sealed class EnumMap<TEnum>
        where TEnum : struct, Enum
    {
        private readonly Dictionary<string, TEnum> byApi = new(StringComparer.Ordinal);
        private readonly Dictionary<TEnum, string> byEnum = new();

        public EnumMap(params (TEnum Value, string Api)[] entries)
        {
            foreach (var (value, api) in entries)
            {
                byApi[api] = value;
                byEnum[value] = api;
            }
        }

        public IReadOnlyCollection<string> Values => byApi.Keys;

        public TEnum Parse(string? value) => value is not null && byApi.TryGetValue(value, out var parsed) ? parsed : default;

        public bool TryParse(string value, out TEnum parsed) => byApi.TryGetValue(value, out parsed);

        public string ToApi(TEnum value) => byEnum.TryGetValue(value, out var api) ? api : "unknown";
    }
}
