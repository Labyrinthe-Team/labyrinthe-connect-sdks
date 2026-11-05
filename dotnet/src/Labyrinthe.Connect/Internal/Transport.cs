using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Globalization;
using System.Linq;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;

namespace Labyrinthe.Connect.Internal;

/// <summary>
/// Quand une requête peut être renvoyée automatiquement.
/// <list type="bullet">
/// <item><see cref="Safe"/> : le rejeu ne peut pas produire deux fois un effet (GET, estimate, POST /sms avec
/// Idempotency-Key). Relancée sur erreur réseau, 5xx et 429 TOO_MANY_REQUESTS.</item>
/// <item><see cref="ThrottledOnly"/> : POST /otp/send et /otp/verify. Relancée seulement sur 429 TOO_MANY_REQUESTS
/// (refusée avant traitement), jamais sur erreur réseau ni 5xx (risque d'un second SMS ou d'une tentative consommée).</item>
/// </list>
/// </summary>
internal enum RetryMode
{
    Safe,
    ThrottledOnly,
}

internal sealed record ApiResponse(int Status, JsonElement Body, IReadOnlyDictionary<string, string> Headers);

/// <summary>
/// Envoi des requêtes, politique de relance et conversion des erreurs. Mêmes règles que les SDK PHP, Node et Python
/// (<c>python/src/labyrinthe_connect/_transport.py</c>). Usage interne.
/// </summary>
internal sealed class Transport
{
    public const string ApiPath = "/api/public/v1";
    public const int MaxRetriesLimit = 10;

    private static readonly TimeSpan BaseDelay = TimeSpan.FromMilliseconds(500);
    private static readonly TimeSpan MaxBackoff = TimeSpan.FromSeconds(8);

    private readonly string apiKey;
    private readonly HttpClient http;
    private readonly ILabyrintheLogger? logger;

    public Transport(string apiKey, string baseUrl, TimeSpan timeout, int maxRetries, TimeSpan maxRetryWait, HttpClient http, ILabyrintheLogger? logger)
    {
        this.apiKey = apiKey;
        BaseUrl = baseUrl;
        Timeout = timeout;
        MaxRetries = maxRetries;
        MaxRetryWait = maxRetryWait;
        this.http = http;
        this.logger = logger;
    }

    public static string UserAgent { get; } = BuildUserAgent();

    public string BaseUrl { get; }

    public TimeSpan Timeout { get; }

    public int MaxRetries { get; }

    public TimeSpan MaxRetryWait { get; }

    /// <summary>Attente entre deux tentatives (remplacée dans les tests).</summary>
    public Func<TimeSpan, CancellationToken, Task> Delay { get; set; } = Task.Delay;

    /// <summary>Source du jitter, entre 0 et 1 (remplacée dans les tests).</summary>
    public Func<double> Jitter { get; set; } = SharedRandom;

    public static string NormalizeBaseUrl(string baseUrl)
    {
        var trimmed = baseUrl.Trim().TrimEnd('/');
        if (!Uri.TryCreate(trimmed, UriKind.Absolute, out var uri)
            || (uri.Scheme != Uri.UriSchemeHttp && uri.Scheme != Uri.UriSchemeHttps)
            || string.IsNullOrEmpty(uri.Host))
        {
            throw new ArgumentException("BaseUrl must be an absolute http(s) URL, e.g. https://api.labyrinthe-rdc.com/api/public/v1.", nameof(baseUrl));
        }

        // Un hôte seul ("http://localhost:8000") désigne la racine du backend.
        return uri.AbsolutePath is "" or "/" ? trimmed + ApiPath : trimmed;
    }

    public static void ValidateOptions(TimeSpan timeout, int maxRetries, TimeSpan maxRetryWait)
    {
        if (timeout <= TimeSpan.Zero && timeout != System.Threading.Timeout.InfiniteTimeSpan)
        {
            throw new ArgumentOutOfRangeException(nameof(timeout), "Timeout must be greater than zero.");
        }

        if (maxRetries is < 0 or > MaxRetriesLimit)
        {
            throw new ArgumentOutOfRangeException(nameof(maxRetries), $"MaxRetries must be between 0 and {MaxRetriesLimit}.");
        }

        if (maxRetryWait < TimeSpan.Zero)
        {
            throw new ArgumentOutOfRangeException(nameof(maxRetryWait), "MaxRetryWait cannot be negative.");
        }
    }

    public async Task<ApiResponse> SendAsync(
        HttpMethod method,
        string path,
        RetryMode mode,
        IReadOnlyList<KeyValuePair<string, string>>? query,
        byte[]? body,
        IReadOnlyDictionary<string, string>? headers,
        CancellationToken cancellationToken)
    {
        query ??= Array.Empty<KeyValuePair<string, string>>();
        var url = BuildUrl(path, query);
        var logPath = Redact.Path(path, query);

        for (var attempt = 0; ; attempt++)
        {
            cancellationToken.ThrowIfCancellationRequested();
            Log(LabyrintheLogLevel.Debug, $"Labyrinthe Connect request {method} {logPath} (attempt {attempt + 1})");
            var started = Stopwatch.StartNew();

            int status = 0;
            byte[] content = Array.Empty<byte>();
            IReadOnlyDictionary<string, string> responseHeaders = EmptyHeaders;
            string? failure = null;
            var timedOut = false;

            using (var request = BuildRequest(method, url, body, headers))
            using (var attemptToken = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken))
            {
                if (Timeout != System.Threading.Timeout.InfiniteTimeSpan)
                {
                    attemptToken.CancelAfter(Timeout);
                }

                try
                {
                    using var response = await http.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, attemptToken.Token).ConfigureAwait(false);
                    status = (int)response.StatusCode;
                    responseHeaders = HeadersOf(response);
#if NET8_0_OR_GREATER
                    content = await response.Content.ReadAsByteArrayAsync(attemptToken.Token).ConfigureAwait(false);
#else
                    content = await response.Content.ReadAsByteArrayAsync().ConfigureAwait(false);
#endif
                }
                catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
                {
                    // Annulation demandée par l'appelant : jamais relancée, jamais convertie en timeout.
                    throw new OperationCanceledException("The Labyrinthe Connect request was cancelled.", null, cancellationToken);
                }
                catch (OperationCanceledException)
                {
                    // Délai de la tentative dépassé (ou HttpClient.Timeout d'un client fourni).
                    timedOut = true;
                    failure = "timeout";
                }
                catch (HttpRequestException exception)
                {
                    failure = Describe(exception);
                }
            }

            TimeSpan delay;
            if (failure is not null)
            {
                delay = AfterNetworkFailure(method, logPath, mode, attempt, timedOut, failure);
            }
            else
            {
                var outcome = AfterResponse(method, logPath, mode, attempt, status, content, responseHeaders, started.Elapsed);
                if (outcome.Response is not null)
                {
                    return outcome.Response;
                }

                delay = outcome.Delay;
            }

            await Delay(delay, cancellationToken).ConfigureAwait(false);
        }
    }

    public override string ToString() => $"Transport {{ ApiKey = {Redact.ApiKey(apiKey)}, BaseUrl = {BaseUrl}, MaxRetries = {MaxRetries} }}";

    internal static TimeSpan? RetryAfter(ApiException error)
    {
        if (error.Headers.TryGetValue("Retry-After", out var header) && header.Trim().Length > 0)
        {
            var value = header.Trim();
            if (double.TryParse(value, NumberStyles.Float, CultureInfo.InvariantCulture, out var seconds))
            {
                if (seconds >= 0)
                {
                    return TimeSpan.FromSeconds(seconds);
                }
            }
            else if (DateTimeOffset.TryParseExact(value, "r", CultureInfo.InvariantCulture, DateTimeStyles.AssumeUniversal, out var at))
            {
                var wait = at - DateTimeOffset.UtcNow;
                return wait > TimeSpan.Zero ? wait : TimeSpan.Zero;
            }
        }

        return error.Details.TryGetValue("retry_after_seconds", out var detail) && Json.ToInt(detail) is { } s
            ? TimeSpan.FromSeconds(s)
            : null;
    }

    private static readonly IReadOnlyDictionary<string, string> EmptyHeaders = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);

    private static readonly Random RandomSource = new();

    private static double SharedRandom()
    {
        // Jitter, pas un secret : System.Random suffit (verrouillé : Random n'est pas thread-safe sous netstandard2.0).
        lock (RandomSource)
        {
#pragma warning disable CA5394 // Random n'est pas utilisé à des fins de sécurité.
            return RandomSource.NextDouble();
#pragma warning restore CA5394
        }
    }

    private static bool IsRetryable(RetryMode mode, ApiException error)
    {
        // Seulement la limite générique : OTP_RESEND_TOO_SOON / OTP_RATE_LIMITED sont des limites métier.
        if (error.StatusCode == 429)
        {
            return error.Code == "TOO_MANY_REQUESTS";
        }

        return mode == RetryMode.Safe && error.StatusCode >= 500;
    }

    private static string Describe(Exception exception)
    {
        // Message court et sans requête d'URL ; l'exception d'origine n'est jamais chaînée.
        var inner = exception;
        while (inner.InnerException is not null)
        {
            inner = inner.InnerException;
        }

        var message = Redact.StripQueryStrings(inner.Message).Trim();
        var name = inner.GetType().Name;
        return message.Length > 0 ? $"{name}: {message}" : name;
    }

    private static Dictionary<string, string> HeadersOf(HttpResponseMessage response)
    {
        var headers = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        foreach (var header in response.Headers.Concat(response.Content.Headers))
        {
            headers[header.Key] = string.Join(", ", header.Value);
        }

        return headers;
    }

    private static string BuildUserAgent()
    {
        // ".NET 8.0.10" → ".NET/8.0.10" ; ".NET Framework 4.8.9" → ".NETFramework/4.8.9".
        var description = RuntimeInformation.FrameworkDescription.Trim();
        var space = description.LastIndexOf(' ');
        var product = space > 0 ? description.Substring(0, space).Replace(" ", string.Empty) : ".NET";
        var version = space > 0 ? description.Substring(space + 1) : Environment.Version.ToString();
        return $"labyrinthe-dotnet/{LabyrintheClient.Version} {product}/{version}";
    }

    private string BuildUrl(string path, IReadOnlyList<KeyValuePair<string, string>> query)
    {
        if (query.Count == 0)
        {
            return BaseUrl + path;
        }

        var builder = new StringBuilder(BaseUrl).Append(path).Append('?');
        for (var i = 0; i < query.Count; i++)
        {
            if (i > 0)
            {
                builder.Append('&');
            }

            builder.Append(Uri.EscapeDataString(query[i].Key)).Append('=').Append(Uri.EscapeDataString(query[i].Value));
        }

        return builder.ToString();
    }

    private HttpRequestMessage BuildRequest(HttpMethod method, string url, byte[]? body, IReadOnlyDictionary<string, string>? headers)
    {
        var request = new HttpRequestMessage(method, url);
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", apiKey);
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        request.Headers.TryAddWithoutValidation("User-Agent", UserAgent);
        if (headers is not null)
        {
            foreach (var header in headers)
            {
                request.Headers.TryAddWithoutValidation(header.Key, header.Value);
            }
        }

        if (body is not null)
        {
            // Sérialisé une seule fois : chaque relance renvoie exactement les mêmes octets.
            request.Content = new ByteArrayContent(body);
            request.Content.Headers.ContentType = new MediaTypeHeaderValue("application/json") { CharSet = "utf-8" };
        }

        return request;
    }

    private TimeSpan AfterNetworkFailure(HttpMethod method, string logPath, RetryMode mode, int attempt, bool timedOut, string reason)
    {
        if (mode == RetryMode.Safe && attempt < MaxRetries)
        {
            var delay = Backoff(attempt);
            Log(LabyrintheLogLevel.Warning, $"Labyrinthe Connect network error, retrying {method} {logPath} (attempt {attempt + 1}, in {delay.TotalSeconds:0.##} s, timeout={timedOut})");
            return delay;
        }

        Log(LabyrintheLogLevel.Error, $"Labyrinthe Connect network error on {method} {logPath} (timeout={timedOut})");
        var suffix = mode == RetryMode.ThrottledOnly ? " The request may have been processed; it was not retried automatically." : string.Empty;
        if (timedOut)
        {
            throw new ApiTimeoutException($"The Labyrinthe Connect API did not answer within {Timeout.TotalSeconds:0.###} s ({method} {logPath}).{suffix}");
        }

        throw new ApiConnectionException($"Could not reach the Labyrinthe Connect API ({method} {logPath}): {reason}.{suffix}");
    }

    private (ApiResponse? Response, TimeSpan Delay) AfterResponse(
        HttpMethod method,
        string logPath,
        RetryMode mode,
        int attempt,
        int status,
        byte[] content,
        IReadOnlyDictionary<string, string> headers,
        TimeSpan elapsed)
    {
        var replayed = headers.TryGetValue("Idempotent-Replayed", out var r) && r == "true";
        Log(LabyrintheLogLevel.Debug, $"Labyrinthe Connect response {method} {logPath}: HTTP {status} in {(long)elapsed.TotalMilliseconds} ms{(replayed ? " (idempotent replay)" : string.Empty)}");

        if (status is >= 200 and < 300)
        {
            return (new ApiResponse(status, Decode(content, status), headers), TimeSpan.Zero);
        }

        var error = Mapping.ToError(status, content, headers);
        if (attempt < MaxRetries && IsRetryable(mode, error))
        {
            var delay = RetryDelay(error, attempt);
            if (delay is { } wait)
            {
                Log(LabyrintheLogLevel.Warning, $"Labyrinthe Connect request failed, retrying {method} {logPath}: HTTP {status} {error.Code} (attempt {attempt + 1}, in {wait.TotalSeconds:0.##} s)");
                return (null, wait);
            }
        }

        Log(LabyrintheLogLevel.Information, $"Labyrinthe Connect API error on {method} {logPath}: HTTP {status} {error.Code}");
        throw error;
    }

    /// <summary>Délai avant la prochaine tentative ; <see langword="null"/> pour abandonner (attente demandée trop longue).</summary>
    private TimeSpan? RetryDelay(ApiException error, int attempt)
    {
        if (error.StatusCode == 429 && RetryAfter(error) is { } retryAfter)
        {
            return retryAfter <= MaxRetryWait ? retryAfter : null;
        }

        return Backoff(attempt);
    }

    /// <summary>Backoff exponentiel avec jitter : 0,5 s, 1 s, 2 s… (50 à 100 %), plafonné.</summary>
    private TimeSpan Backoff(int attempt)
    {
        var delay = Math.Min(MaxBackoff.TotalMilliseconds, BaseDelay.TotalMilliseconds * Math.Pow(2, attempt));
        var jittered = TimeSpan.FromMilliseconds(delay * (0.5 + (Jitter() * 0.5)));
        return jittered < MaxRetryWait ? jittered : MaxRetryWait;
    }

    private static JsonElement Decode(byte[] content, int status)
    {
        if (content.All(b => b is (byte)' ' or (byte)'\n' or (byte)'\r' or (byte)'\t'))
        {
            return Json.TryParse(Encoding.UTF8.GetBytes("{}"))!.Value;
        }

        var decoded = Json.TryParse(content) ?? throw new UnexpectedResponseException("Unexpected API response: the body is not valid JSON.", status);
        return decoded.ValueKind == JsonValueKind.Object
            ? decoded
            : throw new UnexpectedResponseException("Unexpected API response: a JSON object was expected.", status);
    }

    private void Log(LabyrintheLogLevel level, string message)
    {
        if (logger is not null && logger.IsEnabled(level))
        {
            logger.Log(level, message);
        }
    }
}
