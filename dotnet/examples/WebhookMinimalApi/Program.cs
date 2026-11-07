// Minimal API that sends SMS and receives the signed Labyrinthe Connect webhooks.
//
//   Labyrinthe__ApiKey=lsk_test_… Labyrinthe__WebhookSecret=… dotnet run --project examples/WebhookMinimalApi
//
// Then set https://<your host>/webhooks/labyrinthe as the endpoint in the client area (Developers > Webhooks)
// and press "Test": the test.ping event is logged.
using System.Threading;
using System.Threading.Tasks;
using Labyrinthe.Connect;
using Labyrinthe.Connect.AspNetCore;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.Extensions.Logging;

var builder = WebApplication.CreateBuilder(args);

// LabyrintheClient + options from the "Labyrinthe" section (ApiKey, WebhookSecret…), IHttpClientFactory, ILogger.
builder.Services.AddLabyrinthe(builder.Configuration);
builder.Services.AddLabyrintheWebhookHandler<StatusHandler>();

var app = builder.Build();

// POST /webhooks/labyrinthe: raw body, signature and timestamp checked, then StatusHandler is called (400 otherwise).
app.MapLabyrintheWebhooks();

// Sends an SMS: POST /notify?to=0812345678
app.MapPost("/notify", async (string to, LabyrintheClient client, CancellationToken cancellationToken) =>
{
    var message = await client.Sms.SendAsync(to, "Your order 1042 is ready.", cancellationToken);
    return Results.Accepted(value: new { message.Id, Status = message.Status.ToApiString() });
});

app.Run();

/// <summary>Called for every verified event. Answer fast; queue slow work. Deduplicate on Id.</summary>
internal sealed partial class StatusHandler : ILabyrintheWebhookHandler
{
    private readonly ILogger<StatusHandler> logger;

    public StatusHandler(ILogger<StatusHandler> logger) => this.logger = logger;

    public Task HandleAsync(WebhookEvent webhookEvent, CancellationToken cancellationToken)
    {
        switch (webhookEvent)
        {
            case MessageStatusChanged changed:
                // ToApiString() only runs when the log is written (CA1873).
                if (logger.IsEnabled(LogLevel.Information))
                {
                    var status = changed.Data.Status.ToApiString();
                    LogStatus(changed.Data.MessageId, status);
                }
                break;
            case BalanceLow low:
                LogBalance(low.Data.Balance, low.Data.Threshold);
                break;
            default:
                LogOther(webhookEvent.Type, webhookEvent.Id);
                break;
        }

        return Task.CompletedTask;
    }

    [LoggerMessage(Level = LogLevel.Information, Message = "Message {MessageId} is now {Status}")]
    private partial void LogStatus(string messageId, string status);

    [LoggerMessage(Level = LogLevel.Warning, Message = "Low balance: {Balance} credits (threshold {Threshold})")]
    private partial void LogBalance(int balance, int threshold);

    [LoggerMessage(Level = LogLevel.Information, Message = "Webhook {Type} ({Id})")]
    private partial void LogOther(string type, string id);
}
