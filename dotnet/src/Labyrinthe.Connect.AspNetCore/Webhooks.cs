using System;
using System.IO;
using System.Threading;
using System.Threading.Tasks;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Http.Features;
using Microsoft.AspNetCore.Routing;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace Labyrinthe.Connect.AspNetCore;

/// <summary>Handles the verified webhook events of <see cref="LabyrintheEndpointRouteBuilderExtensions.MapLabyrintheWebhooks(IEndpointRouteBuilder, string)"/>.</summary>
/// <remarks>Answer quickly (the API waits 10 s at most) and queue slow work. Deduplicate on <see cref="WebhookEvent.Id"/>.
/// Throwing gives a 500: the API delivers the event again later.</remarks>
public interface ILabyrintheWebhookHandler
{
    /// <summary>Handles one verified event.</summary>
    /// <param name="webhookEvent">The event; use pattern matching for the typed data.</param>
    /// <param name="cancellationToken">Aborted when the request is aborted.</param>
    Task HandleAsync(WebhookEvent webhookEvent, CancellationToken cancellationToken);
}

/// <summary>Options of the webhook endpoint, bound to the <c>Labyrinthe</c> section by <c>AddLabyrinthe(configuration)</c>.</summary>
public sealed class LabyrintheWebhookOptions
{
    /// <summary>Endpoint secret (client area &gt; Developers &gt; Webhooks); defaults to <c>LABYRINTHE_WEBHOOK_SECRET</c>.</summary>
    public string? WebhookSecret { get; set; }

    /// <summary>Max age of a delivery (default 5 minutes); <see cref="TimeSpan.Zero"/> disables the check (not recommended).</summary>
    public TimeSpan WebhookTolerance { get; set; } = Webhook.DefaultTolerance;

    /// <summary>Largest accepted body, in bytes (default 1 MiB); larger requests get 413.</summary>
    public long WebhookMaxBodySize { get; set; } = 1024 * 1024;
}

/// <summary>Maps the webhook endpoint (minimal API).</summary>
public static class LabyrintheEndpointRouteBuilderExtensions
{
    /// <summary>Default route of the webhook endpoint.</summary>
    public const string DefaultPattern = "/webhooks/labyrinthe";

    /// <summary>Maps <c>POST {pattern}</c>: reads the raw body, verifies the signature and calls the <see cref="ILabyrintheWebhookHandler"/> registered with <c>AddLabyrintheWebhookHandler&lt;T&gt;()</c>.</summary>
    /// <param name="endpoints">The application.</param>
    /// <param name="pattern">Route, <c>/webhooks/labyrinthe</c> by default.</param>
    /// <returns>The endpoint builder (add <c>RequireHost</c>, rate limiting…).</returns>
    public static RouteHandlerBuilder MapLabyrintheWebhooks(this IEndpointRouteBuilder endpoints, string pattern = DefaultPattern)
    {
        ArgumentNullException.ThrowIfNull(endpoints);
        return Map(endpoints, pattern, context => context.RequestServices.GetService<ILabyrintheWebhookHandler>()
            ?? throw new InvalidOperationException(
                "No ILabyrintheWebhookHandler is registered: call services.AddLabyrintheWebhookHandler<THandler>() or pass a handler to MapLabyrintheWebhooks."));
    }

    /// <summary>Maps <c>POST {pattern}</c> with a handler instance.</summary>
    /// <param name="endpoints">The application.</param>
    /// <param name="pattern">Route.</param>
    /// <param name="handler">Called for each verified event.</param>
    /// <returns>The endpoint builder.</returns>
    public static RouteHandlerBuilder MapLabyrintheWebhooks(this IEndpointRouteBuilder endpoints, string pattern, ILabyrintheWebhookHandler handler)
    {
        ArgumentNullException.ThrowIfNull(endpoints);
        ArgumentNullException.ThrowIfNull(handler);
        return Map(endpoints, pattern, _ => handler);
    }

    /// <summary>Maps <c>POST {pattern}</c> with a delegate.</summary>
    /// <param name="endpoints">The application.</param>
    /// <param name="pattern">Route.</param>
    /// <param name="handler">Called for each verified event.</param>
    /// <returns>The endpoint builder.</returns>
    public static RouteHandlerBuilder MapLabyrintheWebhooks(this IEndpointRouteBuilder endpoints, string pattern, Func<WebhookEvent, CancellationToken, Task> handler)
    {
        ArgumentNullException.ThrowIfNull(endpoints);
        ArgumentNullException.ThrowIfNull(handler);
        var wrapped = new DelegateHandler(handler);
        return Map(endpoints, pattern, _ => wrapped);
    }

    private static RouteHandlerBuilder Map(IEndpointRouteBuilder endpoints, string pattern, Func<HttpContext, ILabyrintheWebhookHandler> resolve)
    {
        ArgumentException.ThrowIfNullOrEmpty(pattern);
        return endpoints
            .MapPost(pattern, (Delegate)(Func<HttpContext, Task<IResult>>)(context => HandleAsync(context, resolve)))
            .WithName("LabyrintheWebhooks:" + pattern)
            .ExcludeFromDescription();
    }

    private static async Task<IResult> HandleAsync(HttpContext context, Func<HttpContext, ILabyrintheWebhookHandler> resolve)
    {
        var options = context.RequestServices.GetService<IOptions<LabyrintheWebhookOptions>>()?.Value ?? new LabyrintheWebhookOptions();
        var logger = context.RequestServices.GetService<ILoggerFactory>()?.CreateLogger("Labyrinthe.Connect.AspNetCore.Webhooks");
        var secret = !string.IsNullOrEmpty(options.WebhookSecret) ? options.WebhookSecret : Environment.GetEnvironmentVariable("LABYRINTHE_WEBHOOK_SECRET");
        if (string.IsNullOrEmpty(secret))
        {
            throw new InvalidOperationException("The Labyrinthe webhook secret is not configured: set Labyrinthe:WebhookSecret or LABYRINTHE_WEBHOOK_SECRET.");
        }

        var body = await ReadBodyAsync(context, options.WebhookMaxBodySize).ConfigureAwait(false);
        if (body is null)
        {
            return Results.StatusCode(StatusCodes.Status413PayloadTooLarge);
        }

        WebhookEvent webhookEvent;
        try
        {
            webhookEvent = Webhook.ConstructEvent(body, context.Request.Headers[Webhook.SignatureHeader].ToString(), secret, options.WebhookTolerance);
        }
        catch (Exception exception) when (exception is SignatureVerificationException or InvalidWebhookPayloadException)
        {
            if (logger is not null)
            {
                Log.Rejected(logger, ((LabyrintheException)exception).Code, null);
            }

            return Results.Json(new WebhookReply(false, ((LabyrintheException)exception).Code), statusCode: StatusCodes.Status400BadRequest);
        }

        await resolve(context).HandleAsync(webhookEvent, context.RequestAborted).ConfigureAwait(false);
        return Results.Json(new WebhookReply(true, null));
    }

    /// <summary>Corps brut, ou <see langword="null"/> s'il dépasse la taille maximale.</summary>
    private static async Task<byte[]?> ReadBodyAsync(HttpContext context, long maxBodySize)
    {
        if (context.Request.ContentLength > maxBodySize)
        {
            return null;
        }

        var sizeFeature = context.Features.Get<IHttpMaxRequestBodySizeFeature>();
        if (sizeFeature is { IsReadOnly: false })
        {
            sizeFeature.MaxRequestBodySize = maxBodySize;
        }

        using var buffer = new MemoryStream();
        var chunk = new byte[16 * 1024];
        int read;
        while ((read = await context.Request.Body.ReadAsync(chunk, context.RequestAborted).ConfigureAwait(false)) > 0)
        {
            if (buffer.Length + read > maxBodySize)
            {
                return null;
            }

            buffer.Write(chunk, 0, read);
        }

        return buffer.ToArray();
    }

    private sealed record WebhookReply(bool Received, string? Error);

    private sealed class DelegateHandler : ILabyrintheWebhookHandler
    {
        private readonly Func<WebhookEvent, CancellationToken, Task> handler;

        public DelegateHandler(Func<WebhookEvent, CancellationToken, Task> handler) => this.handler = handler;

        public Task HandleAsync(WebhookEvent webhookEvent, CancellationToken cancellationToken) => handler(webhookEvent, cancellationToken);
    }

    private static class Log
    {
        public static readonly Action<ILogger, string, Exception?> Rejected =
            LoggerMessage.Define<string>(LogLevel.Warning, new EventId(10, "LabyrintheWebhookRejected"), "Labyrinthe webhook rejected: {Code}");
    }
}
