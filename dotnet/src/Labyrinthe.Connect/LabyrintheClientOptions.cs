using System;

namespace Labyrinthe.Connect;

/// <summary>Options of <see cref="LabyrintheClient"/>. Bindable from configuration (section <c>Labyrinthe</c>).</summary>
public sealed class LabyrintheClientOptions
{
    /// <summary>Production API root.</summary>
    public const string DefaultBaseUrl = "https://api.connect.labyrinthe-rdc.com/api/public/v1";

    /// <summary><c>lsk_live_…</c> or <c>lsk_test_…</c>; defaults to the <c>LABYRINTHE_API_KEY</c> environment variable. Keep it server-side.</summary>
    public string? ApiKey { get; set; }

    /// <summary>API root; defaults to <c>LABYRINTHE_BASE_URL</c>, then <see cref="DefaultBaseUrl"/>.
    /// A bare host (<c>http://localhost:8000</c>) is completed with <c>/api/public/v1</c>.</summary>
    public string? BaseUrl { get; set; }

    /// <summary>Timeout of each attempt (default 30 s).</summary>
    public TimeSpan Timeout { get; set; } = TimeSpan.FromSeconds(30);

    /// <summary>Automatic retries, only when they are safe (default 2; 0 disables them; max 10).</summary>
    public int MaxRetries { get; set; } = 2;

    /// <summary>Longest wait accepted before a retry (<c>Retry-After</c>), default 10 s. Beyond it, the error is thrown.</summary>
    public TimeSpan MaxRetryWait { get; set; } = TimeSpan.FromSeconds(10);

    /// <summary>Country code added to local numbers (<c>0812345678</c> → <c>243812345678</c>), 1 to 3 digits.</summary>
    public string DefaultCountryCode { get; set; } = PhoneNumber.DefaultCountryCode;

    /// <summary>Sender ID used when a send gets none.</summary>
    public string? DefaultSender { get; set; }

    /// <summary>Optional log sink (never receives the key, a code, a body or a full phone number).</summary>
    public ILabyrintheLogger? Logger { get; set; }

    /// <summary>A copy of these options (the logger instance is shared).</summary>
    public LabyrintheClientOptions Clone() => (LabyrintheClientOptions)MemberwiseClone();

    /// <inheritdoc/>
    public override string ToString() =>
        $"LabyrintheClientOptions {{ ApiKey = {(ApiKey is null ? "null" : Internal.Redact.ApiKey(ApiKey))}, BaseUrl = {BaseUrl}, " +
        $"Timeout = {Timeout}, MaxRetries = {MaxRetries}, MaxRetryWait = {MaxRetryWait}, DefaultCountryCode = {DefaultCountryCode} }}";
}
