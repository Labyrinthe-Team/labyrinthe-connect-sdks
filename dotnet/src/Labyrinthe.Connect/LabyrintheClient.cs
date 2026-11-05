using System;
using System.Net.Http;
using Labyrinthe.Connect.Internal;
using Labyrinthe.Connect.Resources;

namespace Labyrinthe.Connect;

/// <summary>Labyrinthe Connect API client. Thread-safe: create one and reuse it.</summary>
/// <example>
/// <code>
/// using var client = new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_test_…" });
/// var message = await client.Sms.SendAsync("0812345678", "Hello!");
/// </code>
/// </example>
/// <remarks>Keys starting with <c>lsk_test_</c> use the sandbox: nothing is sent, no credit is used, responses have
/// the same shape as in production.</remarks>
public sealed class LabyrintheClient : IDisposable
{
    /// <summary>Version of this SDK (also in the <c>User-Agent</c> header).</summary>
    public const string Version = "1.0.0";

    private readonly string apiKey;
    private readonly HttpClient httpClient;
    private readonly bool ownsHttpClient;
    private bool disposed;

    /// <summary>Creates a client.</summary>
    /// <param name="options">Options; <see langword="null"/> reads <c>LABYRINTHE_API_KEY</c> and <c>LABYRINTHE_BASE_URL</c>.</param>
    /// <param name="httpClient">Your own <see cref="HttpClient"/> (proxy, <c>IHttpClientFactory</c>…); never disposed by the SDK.
    /// Configure its handler not to follow redirects. Without one, the SDK creates and owns its own.</param>
    /// <exception cref="ArgumentException">Missing API key or invalid option.</exception>
    public LabyrintheClient(LabyrintheClientOptions? options = null, HttpClient? httpClient = null)
    {
        options ??= new LabyrintheClientOptions();
        var key = (options.ApiKey ?? Environment.GetEnvironmentVariable("LABYRINTHE_API_KEY") ?? string.Empty).Trim();
        if (key.Length == 0)
        {
            throw new ArgumentException(
                "A Labyrinthe Connect API key is required (lsk_live_… or lsk_test_…): set LabyrintheClientOptions.ApiKey or LABYRINTHE_API_KEY.",
                nameof(options));
        }

        Transport.ValidateOptions(options.Timeout, options.MaxRetries, options.MaxRetryWait);
        PhoneNumber.AssertCountryCode(options.DefaultCountryCode, nameof(options));
        var envBaseUrl = Environment.GetEnvironmentVariable("LABYRINTHE_BASE_URL");
        var baseUrl = Transport.NormalizeBaseUrl(
            !string.IsNullOrWhiteSpace(options.BaseUrl) ? options.BaseUrl!
            : !string.IsNullOrWhiteSpace(envBaseUrl) ? envBaseUrl!
            : LabyrintheClientOptions.DefaultBaseUrl);

        apiKey = key;
        BaseUrl = baseUrl;
        ownsHttpClient = httpClient is null;
        this.httpClient = httpClient ?? CreateHttpClient();

        var transport = new Transport(key, baseUrl, options.Timeout, options.MaxRetries, options.MaxRetryWait, this.httpClient, options.Logger);
        Transport = transport;
        Sms = new SmsResource(transport, options.DefaultCountryCode, options.DefaultSender);
        Balance = new BalanceResource(transport);
        SenderIds = new SenderIdsResource(transport);
        Otp = new OtpResource(transport, options.DefaultCountryCode);
    }

    /// <summary>Send and track SMS.</summary>
    public SmsResource Sms { get; }

    /// <summary>Credit balance.</summary>
    public BalanceResource Balance { get; }

    /// <summary>Approved Sender IDs.</summary>
    public SenderIdsResource SenderIds { get; }

    /// <summary>One-time passwords by SMS.</summary>
    public OtpResource Otp { get; }

    /// <summary>True with a sandbox key (<c>lsk_test_…</c>): nothing is sent, no credit is used.</summary>
    public bool IsTestMode => apiKey.StartsWith("lsk_test_", StringComparison.Ordinal);

    /// <summary>True with a production key (<c>lsk_live_…</c>).</summary>
    public bool IsLiveMode => apiKey.StartsWith("lsk_live_", StringComparison.Ordinal);

    /// <summary>The API root used by this client.</summary>
    public string BaseUrl { get; }

    internal Transport Transport { get; }

    /// <summary>Closes the HTTP connections (not those of an <see cref="HttpClient"/> you passed).</summary>
    public void Dispose()
    {
        if (!disposed)
        {
            disposed = true;
            if (ownsHttpClient)
            {
                httpClient.Dispose();
            }
        }
    }

    /// <inheritdoc/>
    public override string ToString() =>
        $"LabyrintheClient {{ ApiKey = {Redact.ApiKey(apiKey)}, BaseUrl = {BaseUrl}, IsTestMode = {(IsTestMode ? "true" : "false")} }}";

    private static HttpClient CreateHttpClient()
    {
#if NET8_0_OR_GREATER
        var handler = new SocketsHttpHandler
        {
            AllowAutoRedirect = false,
            PooledConnectionLifetime = TimeSpan.FromMinutes(5),
            AutomaticDecompression = System.Net.DecompressionMethods.GZip | System.Net.DecompressionMethods.Deflate,
        };
#else
        var handler = new HttpClientHandler
        {
            AllowAutoRedirect = false,
            AutomaticDecompression = System.Net.DecompressionMethods.GZip | System.Net.DecompressionMethods.Deflate,
        };
#endif

        // Le délai est géré par tentative par le SDK (LabyrintheClientOptions.Timeout).
        return new HttpClient(handler, disposeHandler: true) { Timeout = System.Threading.Timeout.InfiniteTimeSpan };
    }
}
