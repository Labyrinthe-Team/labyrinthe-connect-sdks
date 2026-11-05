// Sends one SMS and follows its status.
//
//   LABYRINTHE_API_KEY=lsk_test_… dotnet run --project examples/SendSms -- 0812345678
//
// With a test key (lsk_test_…) nothing is really sent and no credit is used.
using System;
using System.Threading.Tasks;
using Labyrinthe.Connect;

var to = args.Length > 0 ? args[0] : "0812345678";

using var client = new LabyrintheClient(); // reads LABYRINTHE_API_KEY (and LABYRINTHE_BASE_URL)
Console.WriteLine(client.IsTestMode ? "Test mode: nothing is sent, nothing is billed." : "LIVE mode: this SMS is billed.");

try
{
    var estimate = await client.Sms.EstimateAsync(new EstimateSmsRequest { Body = "Your order 1042 is ready.", Recipients = new[] { to } });
    Console.WriteLine($"Cost: {estimate.TotalCredits} credit(s), {estimate.Encoding}, affordable: {estimate.IsAffordable}");

    var message = await client.Sms.SendAsync(new SendSmsRequest
    {
        To = to,
        Body = "Your order 1042 is ready.",
        Reference = "order-1042",
        IdempotencyKey = $"order-1042-ready-{to}", // running this program again replays: no second SMS
    });
    Console.WriteLine($"Message {message.Id}: {message.Status.ToApiString()}");

    // Webhooks (message.status_changed) are better than polling; this is only a demo.
    for (var i = 0; i < 5 && !message.Status.IsFinal(); i++)
    {
        await Task.Delay(TimeSpan.FromSeconds(2));
        message = await client.Sms.GetAsync(message.Id);
        Console.WriteLine($"  … {message.Status.ToApiString()}");
    }
}
catch (InsufficientCreditsException error)
{
    Console.Error.WriteLine($"Not enough credits: {error.Available} available, {error.Required} required.");
}
catch (LabyrintheException error)
{
    Console.Error.WriteLine($"Labyrinthe Connect error {error.Code} (HTTP {error.StatusCode}): {error.Message}");
}
