using System;
using System.Collections.Generic;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;

namespace Labyrinthe.Connect.Tests.Support;

/// <summary>Requête reçue par le serveur simulé.</summary>
public sealed record RecordedRequest(HttpMethod Method, Uri Uri, IReadOnlyDictionary<string, string> Headers, string? Body)
{
    public string PathAndQuery => Uri.PathAndQuery;

    public string Path => Uri.AbsolutePath;

    public JsonElement Json => JsonDocument.Parse(Body ?? "null").RootElement.Clone();

    public string? Header(string name) => Headers.TryGetValue(name, out var value) ? value : null;

    public IReadOnlyDictionary<string, string> Query =>
        Uri.Query.TrimStart('?').Split('&', StringSplitOptions.RemoveEmptyEntries)
            .Select(part => part.Split('=', 2))
            .ToDictionary(p => Uri.UnescapeDataString(p[0]), p => Uri.UnescapeDataString(p.Length > 1 ? p[1] : string.Empty));
}

/// <summary>Serveur HTTP simulé : une file de réponses, chaque requête est enregistrée.</summary>
public sealed class MockHttp : HttpMessageHandler
{
    private readonly Queue<Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>>> responses = new();

    public List<RecordedRequest> Requests { get; } = new();

    public MockHttp Respond(Func<HttpResponseMessage> response)
    {
        responses.Enqueue((_, _) => Task.FromResult(response()));
        return this;
    }

    public MockHttp Respond(Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> response)
    {
        responses.Enqueue(response);
        return this;
    }

    public MockHttp Json(int status, string json, params (string Name, string Value)[] headers) => Respond(() => Responses.Json(status, json, headers));

    public MockHttp Error(int status, string code, string details = "{}", params (string Name, string Value)[] headers) =>
        Respond(() => Responses.Error(status, code, details, headers));

    public MockHttp NetworkError() => Respond((_, _) => throw new HttpRequestException("Connection refused (api.labyrinthe-rdc.com:443)"));

    /// <summary>Ne répond jamais : seul le délai (ou l'annulation) termine la requête.</summary>
    public MockHttp Hang() => Respond(async (_, token) =>
    {
        await Task.Delay(Timeout.Infinite, token);
        throw new InvalidOperationException("unreachable");
    });

    public MockHttp Repeat(int times, Action<MockHttp> add)
    {
        for (var i = 0; i < times; i++)
        {
            add(this);
        }

        return this;
    }

    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
    {
        var headers = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        foreach (var header in request.Headers)
        {
            // User-Agent est une suite de produits séparés par des espaces, pas une liste.
            headers[header.Key] = string.Join(header.Key == "User-Agent" ? " " : ", ", header.Value);
        }

        string? body = null;
        if (request.Content is not null)
        {
            foreach (var header in request.Content.Headers)
            {
                headers[header.Key] = string.Join(", ", header.Value);
            }

            body = await request.Content.ReadAsStringAsync(cancellationToken);
        }

        Requests.Add(new RecordedRequest(request.Method, request.RequestUri!, headers, body));
        if (responses.Count == 0)
        {
            throw new InvalidOperationException($"No response queued for {request.Method} {request.RequestUri}.");
        }

        return await responses.Dequeue()(request, cancellationToken);
    }
}

public static class Responses
{
    public static HttpResponseMessage Json(int status, string json, params (string Name, string Value)[] headers)
    {
        var response = new HttpResponseMessage((HttpStatusCode)status) { Content = new StringContent(json, Encoding.UTF8, "application/json") };
        foreach (var (name, value) in headers)
        {
            if (!response.Headers.TryAddWithoutValidation(name, value))
            {
                response.Content.Headers.TryAddWithoutValidation(name, value);
            }
        }

        return response;
    }

    public static HttpResponseMessage Error(int status, string code, string details = "{}", params (string Name, string Value)[] headers) =>
        Json(status, $"{{\"error\":{{\"code\":\"{code}\",\"message\":\"Erreur {code}.\",\"details\":{details}}}}}", headers);
}

/// <summary>Client branché sur le serveur simulé ; les attentes entre relances sont enregistrées, pas subies.</summary>
public sealed class Harness : IDisposable
{
    public Harness(Action<LabyrintheClientOptions>? configure = null, string apiKey = "lsk_test_unit_secret_123")
    {
        var options = new LabyrintheClientOptions { ApiKey = apiKey, BaseUrl = "https://api.test/api/public/v1", Logger = Logs };
        configure?.Invoke(options);
        Client = new LabyrintheClient(options, new HttpClient(Http));
        Client.Transport.Delay = (delay, _) =>
        {
            Sleeps.Add(delay);
            return Task.CompletedTask;
        };
        Client.Transport.Jitter = () => 1.0;
    }

    public MockHttp Http { get; } = new();

    public LabyrintheClient Client { get; }

    public List<TimeSpan> Sleeps { get; } = new();

    public MemoryLogger Logs { get; } = new();

    public IReadOnlyList<RecordedRequest> Requests => Http.Requests;

    public void Dispose() => Client.Dispose();
}

public sealed class MemoryLogger : ILabyrintheLogger
{
    public List<(LabyrintheLogLevel Level, string Message)> Records { get; } = new();

    public string All => string.Join("\n", Records.Select(r => r.Message));

    public bool IsEnabled(LabyrintheLogLevel level) => true;

    public void Log(LabyrintheLogLevel level, string message)
    {
        lock (Records)
        {
            Records.Add((level, message));
        }
    }
}
