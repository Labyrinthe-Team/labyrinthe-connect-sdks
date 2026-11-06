using System;
using System.Collections.Generic;
using System.Globalization;

namespace Labyrinthe.Connect;

/// <summary>One SMS to one recipient (<see cref="Resources.SmsResource.SendAsync(SendSmsRequest, System.Threading.CancellationToken)"/>).</summary>
public sealed record SendSmsRequest
{
    /// <summary>The recipient, in any format (<c>+243…</c>, <c>00243…</c>, <c>243…</c>, <c>0812…</c>, <c>812…</c>).</summary>
    public string To { get; init; } = string.Empty;

    /// <summary>The text, 1 to 1600 characters.</summary>
    public string Body { get; init; } = string.Empty;

    /// <summary>An approved Sender ID (11 characters max); defaults to <see cref="LabyrintheClientOptions.DefaultSender"/>, then the platform sender.</summary>
    public string? Sender { get; init; }

    /// <summary>Your own reference (order number…), 64 characters max; filterable with <see cref="ListSmsOptions.Reference"/>.</summary>
    public string? Reference { get; init; }

    /// <summary>Idempotency key (1 to 255 characters). A <see cref="Guid"/> is generated when you give none.</summary>
    /// <remarks>Derive it from your business event (e.g. <c>"order-1042-ready"</c>) to also protect against your own retries.</remarks>
    public string? IdempotencyKey { get; init; }

    /// <summary>Replace accents and typographic characters with their ASCII equivalent before sending: the message
    /// becomes GSM-7, often half the price. The transliteration is done by the server (API 1.2.0).</summary>
    public bool StripAccents { get; init; }
}

/// <summary>The same SMS to up to 1000 recipients, all or nothing (<see cref="Resources.SmsResource.SendManyAsync"/>).</summary>
public sealed record SendManySmsRequest
{
    /// <summary>1 to 1000 recipients, in any format. Duplicates are merged by the API.</summary>
    public IReadOnlyList<string> Recipients { get; init; } = Array.Empty<string>();

    /// <summary>The text, 1 to 1600 characters.</summary>
    public string Body { get; init; } = string.Empty;

    /// <summary>An approved Sender ID (11 characters max).</summary>
    public string? Sender { get; init; }

    /// <summary>Your own reference, 64 characters max, shared by every message of the send.</summary>
    public string? Reference { get; init; }

    /// <summary>Idempotency key (1 to 255 characters). A <see cref="Guid"/> is generated when you give none.</summary>
    public string? IdempotencyKey { get; init; }

    /// <inheritdoc cref="SendSmsRequest.StripAccents"/>
    public bool StripAccents { get; init; }
}

/// <summary>Cost estimate of a send (<see cref="Resources.SmsResource.EstimateAsync"/>).</summary>
public sealed record EstimateSmsRequest
{
    /// <summary>The text, 1 to 1600 characters.</summary>
    public string Body { get; init; } = string.Empty;

    /// <summary>At least one recipient. Normalised and deduplicated like a send; invalid numbers are ignored by the API, not rejected.</summary>
    public IReadOnlyList<string> Recipients { get; init; } = Array.Empty<string>();

    /// <summary>Estimate the text with accents stripped, as <see cref="SendSmsRequest.StripAccents"/> would send it.</summary>
    public bool StripAccents { get; init; }
}

/// <summary>Filters of <see cref="Resources.SmsResource.ListAsync"/>. Every filter is optional.</summary>
public sealed record ListSmsOptions
{
    /// <summary>Only messages with this status.</summary>
    public MessageStatus? Status { get; init; }

    /// <summary>Only messages to this number (any format).</summary>
    public string? Recipient { get; init; }

    /// <summary>Only messages sent with this reference (exact match).</summary>
    public string? Reference { get; init; }

    /// <summary>Inclusive start: a <see cref="DateTimeOffset"/>, or <see cref="DateFilter.Day(int, int, int)"/> for a whole day.</summary>
    public DateFilter? From { get; init; }

    /// <summary>Inclusive end: a <see cref="DateTimeOffset"/>, or <see cref="DateFilter.Day(int, int, int)"/> for a whole day.</summary>
    public DateFilter? To { get; init; }

    /// <summary>Page number, from 1.</summary>
    public int Page { get; init; } = 1;

    /// <summary>Items per page, 1 to 100 (default 15).</summary>
    public int PerPage { get; init; } = 15;
}

/// <summary>An OTP send (<see cref="Resources.OtpResource.SendAsync(SendOtpRequest, System.Threading.CancellationToken)"/>).</summary>
public sealed record SendOtpRequest
{
    /// <summary>The phone number to verify, in any format.</summary>
    public string Phone { get; init; } = string.Empty;

    /// <summary>Custom template, 1600 characters max. Must contain <c>{code}</c>; optional <c>{app_name}</c>, <c>{minutes}</c>.</summary>
    public string? Template { get; init; }

    /// <summary>Language of the standard template; defaults to the account's OTP settings.</summary>
    public OtpLocale? Locale { get; init; }

    /// <summary><see href="https://developer.mozilla.org/docs/Web/API/WebOTP_API">Web OTP API</see>: host name of the site
    /// that reads the code (e.g. <c>app.example.com</c>, at least two labels, no scheme, port or path). Adds the last line
    /// <c>@app.example.com #123456</c> to the SMS for automatic filling (Chrome, Android).</summary>
    public string? WebOtpDomain { get; init; }

    /// <inheritdoc cref="SendSmsRequest.StripAccents"/>
    public bool StripAccents { get; init; }

    /// <summary>Idempotency key (1 to 255 characters). A <see cref="Guid"/> is generated when you give none and reused by
    /// every retry: a retried send never sends a second SMS.</summary>
    public string? IdempotencyKey { get; init; }
}

/// <summary>A date filter: a whole day (<see cref="Day(int, int, int)"/>) or an exact instant (any <see cref="DateTimeOffset"/>).</summary>
public readonly struct DateFilter : IEquatable<DateFilter>
{
    private readonly string value;

    private DateFilter(string value) => this.value = value;

    /// <summary>The value sent to the API (<c>2026-11-01</c> or <c>2026-11-01T08:00:00Z</c>).</summary>
    public string Value => value ?? string.Empty;

    /// <summary>A whole day (inclusive).</summary>
    /// <param name="year">Year.</param>
    /// <param name="month">Month, 1 to 12.</param>
    /// <param name="day">Day of the month.</param>
    public static DateFilter Day(int year, int month, int day) =>
        new(new DateTime(year, month, day, 0, 0, 0, DateTimeKind.Unspecified).ToString("yyyy-MM-dd", CultureInfo.InvariantCulture));

    /// <summary>An exact instant, sent in UTC.</summary>
    /// <param name="instant">The instant.</param>
    public static DateFilter At(DateTimeOffset instant) =>
        new(instant.ToUniversalTime().ToString("yyyy-MM-dd'T'HH:mm:ss'Z'", CultureInfo.InvariantCulture));

#if NET8_0_OR_GREATER
    /// <summary>A whole day (inclusive).</summary>
    /// <param name="date">The day.</param>
    public static DateFilter Day(DateOnly date) => Day(date.Year, date.Month, date.Day);

    /// <summary>A whole day (inclusive).</summary>
    /// <param name="date">The day.</param>
    public static implicit operator DateFilter(DateOnly date) => Day(date);
#endif

    /// <summary>An exact instant, sent in UTC.</summary>
    /// <param name="instant">The instant.</param>
    public static implicit operator DateFilter(DateTimeOffset instant) => At(instant);

    /// <inheritdoc/>
    public static bool operator ==(DateFilter left, DateFilter right) => left.Equals(right);

    /// <inheritdoc/>
    public static bool operator !=(DateFilter left, DateFilter right) => !left.Equals(right);

    /// <inheritdoc/>
    public bool Equals(DateFilter other) => string.Equals(Value, other.Value, StringComparison.Ordinal);

    /// <inheritdoc/>
    public override bool Equals(object? obj) => obj is DateFilter other && Equals(other);

    /// <inheritdoc/>
    public override int GetHashCode() => StringComparer.Ordinal.GetHashCode(Value);

    /// <inheritdoc/>
    public override string ToString() => Value;
}
