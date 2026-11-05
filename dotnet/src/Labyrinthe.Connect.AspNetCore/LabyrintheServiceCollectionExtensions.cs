using System;
using System.Net.Http;
using System.Threading;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.DependencyInjection.Extensions;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace Labyrinthe.Connect.AspNetCore;

/// <summary>Registers <see cref="LabyrintheClient"/> in the dependency injection container.</summary>
public static class LabyrintheServiceCollectionExtensions
{
    /// <summary>Default configuration section: <c>Labyrinthe</c>.</summary>
    public const string DefaultSectionName = "Labyrinthe";

    /// <summary>Name of the <see cref="HttpClient"/> used by <see cref="LabyrintheClient"/> (<c>IHttpClientFactory</c>).</summary>
    public const string HttpClientName = "Labyrinthe.Connect";

    /// <summary>Registers <see cref="LabyrintheClient"/> (typed client of <c>IHttpClientFactory</c>) with options bound to a configuration section.</summary>
    /// <remarks>
    /// <code>
    /// // appsettings.json: { "Labyrinthe": { "ApiKey": "lsk_test_…", "WebhookSecret": "…" } }
    /// builder.Services.AddLabyrinthe(builder.Configuration);
    /// </code>
    /// The section binds <see cref="LabyrintheClientOptions"/> (<c>ApiKey</c>, <c>BaseUrl</c>, <c>Timeout</c>, <c>MaxRetries</c>,
    /// <c>MaxRetryWait</c>, <c>DefaultCountryCode</c>, <c>DefaultSender</c>) and <see cref="LabyrintheWebhookOptions"/>
    /// (<c>WebhookSecret</c>, <c>WebhookTolerance</c>, <c>WebhookMaxBodySize</c>). Without <c>ApiKey</c>, the client reads
    /// <c>LABYRINTHE_API_KEY</c>. SDK logs go to <c>ILogger&lt;LabyrintheClient&gt;</c>.
    /// </remarks>
    /// <param name="services">The services.</param>
    /// <param name="configuration">The application configuration (the root, or any parent of the section).</param>
    /// <param name="sectionName">Section name, <c>Labyrinthe</c> by default.</param>
    /// <returns>The HTTP client builder, to add handlers (proxy, resilience…). Do not add automatic retries: the SDK retries only when safe.</returns>
    public static IHttpClientBuilder AddLabyrinthe(this IServiceCollection services, IConfiguration configuration, string sectionName = DefaultSectionName)
    {
        ArgumentNullException.ThrowIfNull(services);
        ArgumentNullException.ThrowIfNull(configuration);
        ArgumentException.ThrowIfNullOrEmpty(sectionName);

        var section = configuration.GetSection(sectionName);
        services.AddOptions<LabyrintheClientOptions>().Bind(section);
        services.AddOptions<LabyrintheWebhookOptions>().Bind(section);
        return AddClient(services);
    }

    /// <summary>Registers <see cref="LabyrintheClient"/> with options set in code.</summary>
    /// <param name="services">The services.</param>
    /// <param name="configure">Sets the client options.</param>
    /// <returns>The HTTP client builder.</returns>
    public static IHttpClientBuilder AddLabyrinthe(this IServiceCollection services, Action<LabyrintheClientOptions> configure)
    {
        ArgumentNullException.ThrowIfNull(services);
        ArgumentNullException.ThrowIfNull(configure);

        services.AddOptions<LabyrintheClientOptions>().Configure(configure);
        services.AddOptions<LabyrintheWebhookOptions>();
        return AddClient(services);
    }

    /// <summary>Registers the handler called by <see cref="LabyrintheEndpointRouteBuilderExtensions.MapLabyrintheWebhooks(Microsoft.AspNetCore.Routing.IEndpointRouteBuilder, string)"/> (scoped).</summary>
    /// <typeparam name="THandler">Your handler.</typeparam>
    /// <param name="services">The services.</param>
    /// <returns>The services.</returns>
    public static IServiceCollection AddLabyrintheWebhookHandler<THandler>(this IServiceCollection services)
        where THandler : class, ILabyrintheWebhookHandler
    {
        ArgumentNullException.ThrowIfNull(services);
        services.TryAddScoped<ILabyrintheWebhookHandler, THandler>();
        return services;
    }

    private static IHttpClientBuilder AddClient(IServiceCollection services)
    {
        var builder = services.AddHttpClient(HttpClientName);
        builder.AddTypedClient<LabyrintheClient>((http, provider) =>
        {
            var options = provider.GetRequiredService<IOptions<LabyrintheClientOptions>>().Value.Clone();
            options.Logger ??= new MicrosoftLoggerAdapter(provider.GetRequiredService<ILoggerFactory>().CreateLogger<LabyrintheClient>());
            return new LabyrintheClient(options, http);
        });
        return builder
            // Le SDK gère le délai de chaque tentative ; aucune redirection n'est suivie.
            .ConfigureHttpClient(http => http.Timeout = Timeout.InfiniteTimeSpan)
            .ConfigurePrimaryHttpMessageHandler(() => new SocketsHttpHandler
            {
                AllowAutoRedirect = false,
                AutomaticDecompression = System.Net.DecompressionMethods.GZip | System.Net.DecompressionMethods.Deflate,
            });
    }
}
