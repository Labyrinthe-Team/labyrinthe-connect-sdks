using System;
using System.Collections.Generic;
using System.Net;
using System.Net.Http;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using Labyrinthe.Connect.AspNetCore;
using Labyrinthe.Connect.Tests.Support;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.TestHost;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Http;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using Xunit;

namespace Labyrinthe.Connect.Tests;

public class AspNetCoreTests
{
    private const string Secret = "whsec_aspnet";

    [Fact]
    public async Task AddLabyrinthe_binds_the_configuration_section_and_uses_the_http_client_factory()
    {
        var mock = new MockHttp().Json(200, Fixtures.Balance);
        var services = new ServiceCollection();
        services.AddLogging(logging => logging.AddProvider(new ListLoggerProvider()).SetMinimumLevel(LogLevel.Debug));
        var configuration = new ConfigurationBuilder().AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["Labyrinthe:ApiKey"] = "lsk_test_from_config",
            ["Labyrinthe:BaseUrl"] = "http://localhost:8000",
            ["Labyrinthe:Timeout"] = "00:00:05",
            ["Labyrinthe:MaxRetries"] = "4",
            ["Labyrinthe:DefaultSender"] = "MYSHOP",
            ["Labyrinthe:WebhookSecret"] = Secret,
        }).Build();

        services.AddLabyrinthe(configuration).ConfigurePrimaryHttpMessageHandler(() => mock);
        using var provider = services.BuildServiceProvider();

        var options = provider.GetRequiredService<IOptions<LabyrintheClientOptions>>().Value;
        Assert.Equal(TimeSpan.FromSeconds(5), options.Timeout);
        Assert.Equal(4, options.MaxRetries);
        Assert.Equal(Secret, provider.GetRequiredService<IOptions<LabyrintheWebhookOptions>>().Value.WebhookSecret);

        var client = provider.GetRequiredService<LabyrintheClient>();
        Assert.True(client.IsTestMode);
        Assert.Equal("http://localhost:8000/api/public/v1", client.BaseUrl);
        Assert.Equal(5, (await client.Balance.GetAsync()).Available);
        Assert.Equal("Bearer lsk_test_from_config", mock.Requests[0].Header("Authorization"));

        // Les journaux du SDK passent par ILogger<LabyrintheClient>.
        Assert.Contains(ListLoggerProvider.Records, r => r.Category == typeof(LabyrintheClient).FullName && r.Message.Contains("/balance", StringComparison.Ordinal));
    }

    [Fact]
    public void The_factory_handler_does_not_follow_redirects()
    {
        var services = new ServiceCollection();
        services.AddLabyrinthe(o => o.ApiKey = "lsk_test_x");
        using var provider = services.BuildServiceProvider();
        var options = provider.GetRequiredService<IOptionsMonitor<HttpClientFactoryOptions>>().Get(LabyrintheServiceCollectionExtensions.HttpClientName);
        var builder = new TestHandlerBuilder(provider);
        foreach (var action in options.HttpMessageHandlerBuilderActions)
        {
            action(builder);
        }

        var handler = Assert.IsType<SocketsHttpHandler>(builder.PrimaryHandler);
        Assert.False(handler.AllowAutoRedirect);
        using var http = provider.GetRequiredService<IHttpClientFactory>().CreateClient(LabyrintheServiceCollectionExtensions.HttpClientName);
        Assert.Equal(Timeout.InfiniteTimeSpan, http.Timeout);
    }

    [Fact]
    public async Task Webhook_endpoint_accepts_a_signed_event_and_calls_the_handler()
    {
        var handler = new RecordingHandler();
        await using var app = await StartAsync(services => services.AddSingleton<ILabyrintheWebhookHandler>(handler), app => app.MapLabyrintheWebhooks());
        using var http = app.GetTestClient();

        var body = WebhookTests.Envelope("message.status_changed", """{"message_id":"m1","recipient":"243812345678","status":"delivered","provider_message_id":null,"campaign_id":null}""");
        using var response = await PostAsync(http, "/webhooks/labyrinthe", body, Webhook.Sign(body, Secret));

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Equal("""{"received":true,"error":null}""", await response.Content.ReadAsStringAsync());
        var evt = Assert.IsType<MessageStatusChanged>(Assert.Single(handler.Events));
        Assert.Equal("m1", evt.Data.MessageId);
    }

    [Fact]
    public async Task Webhook_endpoint_rejects_bad_signatures_without_calling_the_handler()
    {
        var handler = new RecordingHandler();
        await using var app = await StartAsync(_ => { }, app => app.MapLabyrintheWebhooks("/hooks", handler));
        using var http = app.GetTestClient();
        var body = WebhookTests.Envelope("test.ping", "{}");
        var expired = WebhookTests.Envelope("test.ping", "{}", DateTimeOffset.UtcNow.AddHours(-1).ToUnixTimeSeconds());

        using var wrong = await PostAsync(http, "/hooks", body, Webhook.Sign(body, "another-secret"));
        using var missing = await PostAsync(http, "/hooks", body, null);
        using var old = await PostAsync(http, "/hooks", expired, Webhook.Sign(expired, Secret));
        using var notJson = await PostAsync(http, "/hooks", "nope", Webhook.Sign("nope", Secret));
        using var get = await http.GetAsync(new Uri("/hooks", UriKind.Relative));

        Assert.Equal(HttpStatusCode.BadRequest, wrong.StatusCode);
        Assert.Equal(HttpStatusCode.BadRequest, missing.StatusCode);
        Assert.Equal(HttpStatusCode.BadRequest, old.StatusCode);
        Assert.Equal(HttpStatusCode.BadRequest, notJson.StatusCode);
        Assert.Contains("SIGNATURE_VERIFICATION_FAILED", await wrong.Content.ReadAsStringAsync(), StringComparison.Ordinal);
        Assert.Contains("INVALID_WEBHOOK_PAYLOAD", await notJson.Content.ReadAsStringAsync(), StringComparison.Ordinal);
        Assert.Equal(HttpStatusCode.MethodNotAllowed, get.StatusCode);
        Assert.Empty(handler.Events);
    }

    [Fact]
    public async Task Webhook_endpoint_with_a_delegate_and_an_unknown_event()
    {
        var events = new List<WebhookEvent>();
        await using var app = await StartAsync(_ => { }, app => app.MapLabyrintheWebhooks("/wh", (evt, _) =>
        {
            events.Add(evt);
            return Task.CompletedTask;
        }));
        using var http = app.GetTestClient();
        var body = WebhookTests.Envelope("sms.received", """{"sms_id":"s1"}""");
        using var response = await PostAsync(http, "/wh", body, Webhook.Sign(body, Secret));
        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.IsType<UnknownEvent>(Assert.Single(events));

        // Événements OTP de l'API 1.2.0 : typés, sans changement côté intégration.
        var otp = WebhookTests.Envelope("otp.failed", """{"otp_id":"o1","phone":"243812345678","status":"expired","reason":"expired","verified_at":null}""");
        using var otpResponse = await PostAsync(http, "/wh", otp, Webhook.Sign(otp, Secret));
        Assert.Equal(HttpStatusCode.OK, otpResponse.StatusCode);
        Assert.Equal(OtpWebhookStatus.Expired, Assert.IsType<OtpFailed>(events[1]).Data.Status);
    }

    [Fact]
    public async Task Webhook_endpoint_refuses_large_bodies_and_reports_handler_failures()
    {
        await using var app = await StartAsync(
            services => services.Configure<LabyrintheWebhookOptions>(o => o.WebhookMaxBodySize = 64),
            app => app.MapLabyrintheWebhooks("/wh", (_, _) => throw new InvalidOperationException("database down")));
        using var http = app.GetTestClient();

        var large = WebhookTests.Envelope("test.ping", "{\"padding\":\"" + new string('x', 200) + "\"}");
        using var tooLarge = await PostAsync(http, "/wh", large, Webhook.Sign(large, Secret));
        Assert.Equal(HttpStatusCode.RequestEntityTooLarge, tooLarge.StatusCode);

        await using var app2 = await StartAsync(_ => { }, a => a.MapLabyrintheWebhooks("/wh", (_, _) => throw new InvalidOperationException("database down")));
        using var http2 = app2.GetTestClient();
        var body = WebhookTests.Envelope("test.ping", "{}");
        using var failed = await PostAsync(http2, "/wh", body, Webhook.Sign(body, Secret));
        Assert.Equal(HttpStatusCode.InternalServerError, failed.StatusCode);
    }

    [Fact]
    public async Task Webhook_endpoint_without_handler_or_secret_fails_loudly()
    {
        await using var noHandler = await StartAsync(_ => { }, app => app.MapLabyrintheWebhooks());
        var body = WebhookTests.Envelope("test.ping", "{}");
        using (var http = noHandler.GetTestClient())
        using (var response = await PostAsync(http, "/webhooks/labyrinthe", body, Webhook.Sign(body, Secret)))
        {
            Assert.Equal(HttpStatusCode.InternalServerError, response.StatusCode);
        }

        await using var noSecret = await StartAsync(_ => { }, app => app.MapLabyrintheWebhooks("/wh", (_, _) => Task.CompletedTask), secret: null);
        using var http2 = noSecret.GetTestClient();
        using var response2 = await PostAsync(http2, "/wh", body, Webhook.Sign(body, Secret));
        Assert.Equal(HttpStatusCode.InternalServerError, response2.StatusCode);
    }

    [Fact]
    public void AddLabyrintheWebhookHandler_registers_a_scoped_handler()
    {
        var services = new ServiceCollection();
        services.AddLabyrintheWebhookHandler<RecordingHandler>();
        var descriptor = Assert.Single(services, d => d.ServiceType == typeof(ILabyrintheWebhookHandler));
        Assert.Equal(ServiceLifetime.Scoped, descriptor.Lifetime);
        Assert.Equal(typeof(RecordingHandler), descriptor.ImplementationType);
    }

    private static async Task<WebApplication> StartAsync(Action<IServiceCollection> services, Action<WebApplication> map, string? secret = Secret)
    {
        var builder = WebApplication.CreateSlimBuilder();
        builder.WebHost.UseTestServer();
        builder.Logging.ClearProviders();
        builder.Configuration.AddInMemoryCollection(new Dictionary<string, string?>
        {
            ["Labyrinthe:ApiKey"] = "lsk_test_x",
            ["Labyrinthe:WebhookSecret"] = secret,
        });
        builder.Services.AddLabyrinthe(builder.Configuration);
        services(builder.Services);
        var app = builder.Build();

        // Comme en production : une exception non gérée donne une 500 (l'API relivrera l'événement).
        app.Use(async (context, next) =>
        {
            try
            {
                await next(context);
            }
            catch (InvalidOperationException)
            {
                context.Response.StatusCode = StatusCodes.Status500InternalServerError;
            }
        });
        map(app);
        await app.StartAsync();
        return app;
    }

    private static async Task<HttpResponseMessage> PostAsync(HttpClient http, string path, string body, string? signature)
    {
        using var request = new HttpRequestMessage(HttpMethod.Post, path) { Content = new StringContent(body, Encoding.UTF8, "application/json") };
        if (signature is not null)
        {
            request.Headers.Add("X-Signature", signature);
        }

        return await http.SendAsync(request);
    }

    private sealed class RecordingHandler : ILabyrintheWebhookHandler
    {
        public List<WebhookEvent> Events { get; } = new();

        public Task HandleAsync(WebhookEvent webhookEvent, CancellationToken cancellationToken)
        {
            Events.Add(webhookEvent);
            return Task.CompletedTask;
        }
    }

    private sealed class TestHandlerBuilder : HttpMessageHandlerBuilder
    {
        public TestHandlerBuilder(IServiceProvider services) => Services = services;

        public override string? Name { get; set; }

        public override HttpMessageHandler PrimaryHandler { get; set; } = new HttpClientHandler();

        public override IList<DelegatingHandler> AdditionalHandlers { get; } = new List<DelegatingHandler>();

        public override IServiceProvider Services { get; }

        public override HttpMessageHandler Build() => PrimaryHandler;
    }

    private sealed class ListLoggerProvider : ILoggerProvider
    {
        public static List<(string Category, string Message)> Records { get; } = new();

        public ILogger CreateLogger(string categoryName) => new ListLogger(categoryName);

        public void Dispose()
        {
        }

        private sealed class ListLogger(string category) : ILogger
        {
            public IDisposable? BeginScope<TState>(TState state)
                where TState : notnull => null;

            public bool IsEnabled(LogLevel logLevel) => true;

            public void Log<TState>(LogLevel logLevel, EventId eventId, TState state, Exception? exception, Func<TState, Exception?, string> formatter)
            {
                lock (Records)
                {
                    Records.Add((category, formatter(state, exception)));
                }
            }
        }
    }
}
