# Labyrinthe Connect — .NET SDK

[![CI](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/dotnet.yml/badge.svg)](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/dotnet.yml)
[![NuGet](https://img.shields.io/nuget/v/Labyrinthe.Connect.svg?label=Labyrinthe.Connect)](https://www.nuget.org/packages/Labyrinthe.Connect)
[![NuGet](https://img.shields.io/nuget/v/Labyrinthe.Connect.AspNetCore.svg?label=Labyrinthe.Connect.AspNetCore)](https://www.nuget.org/packages/Labyrinthe.Connect.AspNetCore)
![.NET](https://img.shields.io/badge/.NET-netstandard2.0%20%7C%20net8.0-512BD4)
![License](https://img.shields.io/badge/license-MIT-green)

Official .NET client for [Labyrinthe Connect](https://connect.labyrinthe-rdc.com): send SMS and verify phone numbers with
one-time codes (OTP), in the DR Congo and Central Africa.

*Version française : [README.fr.md](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/blob/main/dotnet/README.fr.md).*

| Package | Content |
|---|---|
| [`Labyrinthe.Connect`](https://www.nuget.org/packages/Labyrinthe.Connect) | The client: SMS, balance, Sender IDs, OTP, webhook verification. |
| [`Labyrinthe.Connect.AspNetCore`](https://www.nuget.org/packages/Labyrinthe.Connect.AspNetCore) | `services.AddLabyrinthe(configuration)` (`IHttpClientFactory`, `ILogger`) and `app.MapLabyrintheWebhooks()` (minimal API endpoint for signed webhooks). |

- `async` everywhere, with `CancellationToken`; `IAsyncEnumerable` pagination; nullable annotations; immutable records.
- Safe by default: automatic retries **only** when they cannot send an SMS or an OTP twice.
- Typed exceptions for every API error, including the maintenance error `FeatureDisabledException`.
- One dependency on `netstandard2.0` (`System.Text.Json`), none on `net8.0` (trimmable, AOT compatible).
- Never logs or prints your API key, OTP codes or full phone numbers.

**Contents:** [Requirements](#requirements) · [Installation](#installation) · [Quick start](#quick-start) ·
[Configuration](#configuration) · [Sending SMS](#sending-sms) · [Balance and Sender IDs](#balance-and-sender-ids) ·
[OTP](#otp-verify-a-phone-number) · [Webhooks](#webhooks) · [Errors](#errors) ·
[Retries and idempotency](#retries-and-idempotency) · [Test mode, logging and security](#test-mode-logging-and-security) ·
[Without the package](#without-the-package-raw-http) · [Upgrading](#upgrading) · [Contributing](#contributing)

## Requirements

| Package | Target frameworks | Runs on |
|---|---|---|
| `Labyrinthe.Connect` | `netstandard2.0`, `net8.0` | .NET 8+ (the `net8.0` build), .NET 6 / 7, .NET Framework 4.7.2+, Mono, Unity (the `netstandard2.0` build) |
| `Labyrinthe.Connect.AspNetCore` | `net8.0` | ASP.NET Core 8+ |

Tested on .NET 8 and .NET 9 (Linux and Windows). Building the repository needs the .NET 8 SDK or later.

## Installation

```bash
dotnet add package Labyrinthe.Connect
dotnet add package Labyrinthe.Connect.AspNetCore   # optional: ASP.NET Core integration (includes Labyrinthe.Connect)
```

Or in your `.csproj`:

```xml
<ItemGroup>
  <PackageReference Include="Labyrinthe.Connect" Version="1.2.1" />
  <!-- ASP.NET Core: this one is enough, it brings Labyrinthe.Connect -->
  <PackageReference Include="Labyrinthe.Connect.AspNetCore" Version="1.2.1" />
</ItemGroup>
```

## Quick start

```csharp
using Labyrinthe.Connect;

using var client = new LabyrintheClient(new LabyrintheClientOptions { ApiKey = "lsk_test_…" }); // or LABYRINTHE_API_KEY
var message = await client.Sms.SendAsync("0812345678", "Your order 1042 is ready.");
Console.WriteLine(message.Status);                                                              // Queued
```

Create your keys in the client area (*Developers > API keys*). **Keep them server-side**, never in a browser or a
mobile app.

| Key | Mode | Effect |
|---|---|---|
| `lsk_test_…` | Sandbox | Nothing is sent, no credit is used. Same responses as production. OTP sends return the code (`TestCode`). |
| `lsk_live_…` | Production | SMS are really sent and credits are used. |

`client.IsTestMode` / `client.IsLiveMode` tell you which one you are using. Every example below works with a test
key. A `LabyrintheClient` is thread-safe and holds a connection pool: create one (or register it with
`AddLabyrinthe`) and reuse it.

## Configuration

### Options

| `LabyrintheClientOptions` | Default | Meaning |
|---|---|---|
| `ApiKey` | `LABYRINTHE_API_KEY` | `lsk_live_…` or `lsk_test_…`. Required (an `ArgumentException` is thrown without one). |
| `BaseUrl` | `LABYRINTHE_BASE_URL`, then `https://api.connect.labyrinthe-rdc.com/api/public/v1` | API root. A bare host (`http://localhost:8000`) is completed with `/api/public/v1`. |
| `Timeout` | 30 s | Timeout of **each attempt**. |
| `MaxRetries` | 2 | Automatic retries when they are safe; `0` disables them (max 10). |
| `MaxRetryWait` | 10 s | Longest `Retry-After` the SDK accepts to wait; beyond it, the error is thrown. |
| `DefaultCountryCode` | `"243"` | Added to local numbers (`0812345678` → `243812345678`); 1 to 3 digits. |
| `DefaultSender` | `null` | Sender ID used when a send gets no `Sender`. |
| `Logger` | `null` (no logs) | An `ILabyrintheLogger` (see [Logging](#logging)). |

```csharp
using var client = new LabyrintheClient(new LabyrintheClientOptions
{
    ApiKey = Environment.GetEnvironmentVariable("LABYRINTHE_API_KEY"),
    BaseUrl = "https://api.connect.labyrinthe-rdc.com/api/public/v1",
    Timeout = TimeSpan.FromSeconds(30),
    MaxRetries = 2,
    MaxRetryWait = TimeSpan.FromSeconds(10),
    DefaultCountryCode = "243",
    DefaultSender = "MYSHOP",
    Logger = LabyrintheLogger.Create((level, message) => Console.Error.WriteLine($"[{level}] {message}")),
}, httpClient: myHttpClient);   // optional: your own HttpClient (proxy…), never disposed by the SDK
```

`new LabyrintheClient()` with no argument reads `LABYRINTHE_API_KEY` and `LABYRINTHE_BASE_URL`. If you pass your
own `HttpClient`, configure its handler with `AllowAutoRedirect = false` and leave its `Timeout` above the SDK's
(`Timeout.InfiniteTimeSpan` is fine: the SDK times out each attempt itself). Without one, the SDK creates and owns
its own (`Dispose()` closes it).

### Environment variables

| Variable | Used for |
|---|---|
| `LABYRINTHE_API_KEY` | API key, when `ApiKey` is not set |
| `LABYRINTHE_BASE_URL` | API root, when `BaseUrl` is not set (e.g. `http://localhost:8000` for a local backend) |
| `LABYRINTHE_WEBHOOK_SECRET` | Webhook secret of `MapLabyrintheWebhooks()`, when `WebhookSecret` is not set |

### ASP.NET Core and dependency injection

```json
// appsettings.json (keep real keys in user secrets or environment variables)
{
  "Labyrinthe": {
    "ApiKey": "lsk_test_…",
    "WebhookSecret": "…",
    "Timeout": "00:00:30",
    "MaxRetries": 2,
    "MaxRetryWait": "00:00:10",
    "DefaultCountryCode": "243",
    "DefaultSender": "MYSHOP",
    "WebhookTolerance": "00:05:00",
    "WebhookMaxBodySize": 1048576
  }
}
```

```csharp
using Labyrinthe.Connect;
using Labyrinthe.Connect.AspNetCore;

var builder = WebApplication.CreateBuilder(args);
builder.Services.AddLabyrinthe(builder.Configuration);   // section "Labyrinthe" (or AddLabyrinthe(configuration, "MySection"))

var app = builder.Build();
app.MapPost("/notify", async (string to, LabyrintheClient client, CancellationToken ct) =>
    (await client.Sms.SendAsync(to, "Your order is ready.", ct)).Id);
app.Run();
```

- The section binds every `LabyrintheClientOptions` property above and the `LabyrintheWebhookOptions`
  (`WebhookSecret`, `WebhookTolerance` = 5 min, `WebhookMaxBodySize` = 1 MiB). Environment variables work too
  (`Labyrinthe__ApiKey`, `Labyrinthe__WebhookSecret`); without a key the client falls back to `LABYRINTHE_API_KEY`.
- Options in code instead: `builder.Services.AddLabyrinthe(options => options.ApiKey = "lsk_test_…")`.
- `LabyrintheClient` is a typed client of `IHttpClientFactory` (named client `Labyrinthe.Connect`): inject it
  anywhere. SDK logs go to `ILogger<LabyrintheClient>` (category `Labyrinthe.Connect.LabyrintheClient`) unless you
  set `Logger` yourself.
- `AddLabyrinthe` returns the `IHttpClientBuilder`: add a proxy or a `DelegatingHandler`, but **no retry or
  resilience policy** (`AddStandardResilienceHandler`, Polly…). The SDK already retries when it is safe, and an
  extra retry layer could send an OTP twice. Redirects are not followed.

## Sending SMS

```csharp
var message = await client.Sms.SendAsync(new SendSmsRequest
{
    To = "+243 81 234 5678",        // +243…, 00243…, 243…, 0812…, 812…, 33612345678 are all accepted
    Body = "Your order 1042 is ready.",
    Sender = "MYSHOP",              // optional: an approved Sender ID (client.SenderIds.ListAsync())
    Reference = "order-1042",       // optional: your own reference, max 64 characters
    StripAccents = false,           // optional: see "Cost before sending"
    IdempotencyKey = "order-1042-ready", // optional: see "Idempotency"
});

message.Id              // "9d5c0a3e-…"
message.Recipient       // "243812345678"
message.Status          // MessageStatus.Queued
message.Segments        // 1
message.CreditsCharged  // 1
message.CreatedAt       // DateTimeOffset?
```

`client.Sms.SendAsync(to, body)` is the short form. The text of a message is never returned by the API (it may
contain a code).

Sending is asynchronous: `202 Accepted` means *queued and paid for*, not *delivered*. Follow the status with
webhooks (`message.status_changed`, recommended) or by polling:

```csharp
var latest = await client.Sms.GetAsync(message.Id);
if (latest.Status.IsFinal())   // also IsFailed(), IsPending()
{
    Console.WriteLine(latest.Status == MessageStatus.Delivered ? "Delivered" : $"Failed: {latest.ErrorCode}");
}
```

`MessageStatus`: `Queued`, `Submitted`, `Sent`, `Delivered`, `Failed`, `Rejected`, `Expired`, `Undeliverable`, and
`Unknown` for a value added after this SDK version. `status.ToApiString()` gives the API value (`"queued"`).

### Cost before sending

1 credit = 1 SMS segment. A pure ASCII message (GSM-7) holds 160 characters (153 per segment beyond). **Any
non-ASCII character** (é, à, ç, emoji, curly quotes…) switches to UCS-2: 70 characters (67 per segment beyond).

```csharp
var estimate = await client.Sms.EstimateAsync(new EstimateSmsRequest
{
    Body = "Réunion demain à 9h",
    Recipients = new[] { "0812345678", "0899999999" },
    StripAccents = false,
});
estimate.Encoding            // SmsEncoding.Ucs2
estimate.SegmentsPerMessage  // 1
estimate.RecipientsCount     // 2 (normalised, duplicates merged, invalid numbers ignored)
estimate.TotalCredits        // 2
estimate.BalanceAfter        // int?
estimate.IsAffordable        // BalanceAfter >= 0
```

`StripAccents = true` (on `SendSmsRequest`, `SendManySmsRequest`, `EstimateSmsRequest` and `SendOtpRequest`) lets the
server replace accents and typographic characters with ASCII: the message becomes GSM-7, often half the price. The
transliteration is done by the server; do not rely on it for an exact text.

**Phone numbers.** `+` or `00` keeps the country code; without `+`, 11 digits or more not starting with 0 are already
international (`33612345678` stays `33612345678`); otherwise the number is local and gets `DefaultCountryCode`
(`0812345678` → `243812345678`). For a short foreign number (10 digits or fewer), always use `+`. The SDK sends every
number in E.164 with `+`; responses use international digits without `+`. `PhoneNumber.Normalize("0812345678")`
(`"243812345678"` or `null`), `PhoneNumber.IsValid()` and `PhoneNumber.Mask()` apply the same rules locally.

### Several recipients: all or nothing

```csharp
try
{
    var batch = await client.Sms.SendManyAsync(new SendManySmsRequest
    {
        Recipients = new[] { "0812345678", "0899999999" },
        Body = "Meeting tomorrow at 9am.",
        Sender = "MYSHOP",
        Reference = "meeting-2026-11-07",
    });
    // batch.Messages: one message per distinct number (duplicates are merged)
    // batch.IdempotencyKey, batch.Replayed, batch.RequestId
}
catch (RecipientNotSendableException error)
{
    // NOTHING was sent: fix the list and try again.
    foreach (var rejected in error.Rejected)
    {
        Console.WriteLine($"{rejected.Recipient}: {rejected.Reason}");   // InvalidPhone, Blacklisted, OptedOut
    }
}
```

Up to 1000 recipients per call (`Labyrinthe.Connect.Resources.SmsResource.MaxRecipients`). Every recipient, the Sender ID and the balance are
checked **before** anything is sent: one refused number (or an insufficient balance) means no SMS at all. Numbers
the SDK can already tell are invalid are refused locally (`ArgumentException`, with their index only), before any
network call.

### Listing messages

```csharp
var page = await client.Sms.ListAsync(new ListSmsOptions
{
    Status = MessageStatus.Failed,
    Recipient = "0812345678",              // any format
    From = DateFilter.Day(2026, 11, 1),    // a whole day (a DateOnly converts implicitly on net8.0)
    To = DateTimeOffset.UtcNow,            // an exact instant
    Page = 1,
    PerPage = 50,                          // default 15, max 100
});
// page.Data, page.CurrentPage, page.LastPage, page.Total, page.HasMore
var next = await page.GetNextPageAsync();  // null on the last page

await foreach (var message in client.Sms.ListAutoPagingAsync(new ListSmsOptions { Reference = "order-1042" }))
{
    // every page, fetched lazily
}
```

Filters: `Status`, `Recipient`, `Reference`, `From`, `To` (inclusive), `Page`, `PerPage`. The list only holds the
messages sent **with the calling key**; `GetAsync(id)` finds any message of the account, by `Id` only.

## Balance and Sender IDs

```csharp
var balance = await client.Balance.GetAsync();        // balance.Available, balance.Held (credits)
var senderIds = await client.SenderIds.ListAsync();   // IReadOnlyList<SenderId>

foreach (var senderId in senderIds)
{
    Console.WriteLine($"{senderId.Value} {senderId.Status} default={senderId.IsDefault}");   // usable as Sender
}
```

## OTP: verify a phone number

The code is generated, sent by SMS and checked by Labyrinthe Connect. It is never returned by the API (except with a
test key) and never logged by the SDK.

```csharp
// 1. Send the code. Keep OtpId SERVER-SIDE (session, cache, database), never in the browser.
var otp = await client.Otp.SendAsync("0812345678");
HttpContext.Session.SetString("otp_id", otp.OtpId);
// otp.ExpiresAt, otp.MaxAttempts
// otp.CodeLength (4 to 8), otp.CodeType (Numeric / Alphanumeric): size the input field and pick the keyboard.
// otp.ResendAfterSeconds: countdown of the "Resend the code" button.

// 2. The user types the code received by SMS.

// 3. Verify it.
var result = await client.Otp.VerifyAsync(HttpContext.Session.GetString("otp_id")!, typedCode);
if (result.Verified)
{
    // log the user in / mark the number as verified
}
else if (result.Reason == OtpFailureReason.InvalidCode)
{
    // wrong code: one attempt consumed, result.AttemptsRemaining left
}
else
{
    // Expired, AlreadyUsed, MaxAttemptsReached: ask for a new code
}
```

All the options of a send:

```csharp
var otp = await client.Otp.SendAsync(new SendOtpRequest
{
    Phone = "0812345678",
    Template = "Your MyShop code: {code}",   // must contain {code}; optional {app_name}, {minutes}
    Locale = OtpLocale.En,                   // Fr or En (standard templates)
    WebOtpDomain = "app.example.com",        // adds "@app.example.com #123456" for the Web OTP API
    StripAccents = true,
    IdempotencyKey = "login-42-attempt-1",   // optional: a Guid is generated otherwise
});
```

- A wrong code is **not** an exception: `VerifyAsync` returns `OtpVerifyResult { Verified = false, Reason = … }`.
- A new send to the same number invalidates the previous code. After a successful verification, a new code can be
  sent at once.
- Length, validity, attempts, Sender ID and template default to the account's OTP settings.
- `WebOtpDomain` enables automatic filling with the [Web OTP API](https://developer.mozilla.org/docs/Web/API/WebOTP_API)
  (Chrome, Android).
- Limits: `OtpResendTooSoonException` and `OtpRateLimitedException` (`Scope` = `Phone` / `Account`, `Limit`), both
  with `RetryAfter`. Never retried by the SDK. `otp/send` is also limited to 10 requests per minute per API key.
- **Test mode**: with a `lsk_test_` key no SMS is sent and `otp.TestCode` holds the code, so you can test the whole
  flow. Always `null` with a live key; never shown by `ToString()`.

`Otp.SendAsync` always carries an `Idempotency-Key` reused by its retries: a replay returns the same `OtpId` without
a second SMS (`otp.Replayed`). Every verification outcome is also notified by the `otp.verified` and `otp.failed`
webhooks.

## Webhooks

Configure an endpoint in the client area (*Developers > Webhooks*) and copy its secret. Events are `POST`ed as JSON
(`{ id, event, data, timestamp }`) and signed: `X-Signature: sha256=<HMAC-SHA256(secret, raw body)>`. The SDK
verifies the signature in constant time (`CryptographicOperations.FixedTimeEquals`) on the **raw body**, before any
JSON parsing, then checks the signed timestamp (5 minutes by default, replay protection).

> Always verify the **exact bytes** received. A body that went through model binding or `JsonSerializer` and back
> no longer matches the signature.

### ASP.NET Core: `MapLabyrintheWebhooks()`

```csharp
builder.Services.AddLabyrinthe(builder.Configuration);              // reads Labyrinthe:WebhookSecret
builder.Services.AddLabyrintheWebhookHandler<MyWebhookHandler>();   // scoped ILabyrintheWebhookHandler

var app = builder.Build();
app.MapLabyrintheWebhooks();                                         // POST /webhooks/labyrinthe

sealed class MyWebhookHandler : ILabyrintheWebhookHandler
{
    public Task HandleAsync(WebhookEvent webhookEvent, CancellationToken cancellationToken) => webhookEvent switch
    {
        MessageStatusChanged changed => UpdateMessageAsync(changed.Data.MessageId, changed.Data.Status, cancellationToken),
        OtpFailed failed => AuditAsync(failed.Data.OtpId, failed.Data.Reason, cancellationToken),
        _ => Task.CompletedTask,                                     // unknown or ignored events: acknowledge them
    };
}
```

- The endpoint reads the raw body (up to `WebhookMaxBodySize`), verifies the signature and the timestamp, then
  calls your handler and answers `200 {"received":true}`. Bad signature, expired timestamp or invalid envelope:
  `400`; body too large: `413`; an exception in your handler: `500` (the API delivers the event again). A missing
  secret is a configuration error (`InvalidOperationException`).
- Other forms: `MapLabyrintheWebhooks("/hooks/sms")`, `MapLabyrintheWebhooks(pattern, (evt, ct) => …)` and
  `MapLabyrintheWebhooks(pattern, handlerInstance)`.
- It returns a `RouteHandlerBuilder`: chain `.RequireHost(…)`, `.RequireRateLimiting(…)` or `.AddEndpointFilter(…)`.
  Do not put it behind authentication or antiforgery: the signature is the authentication.

Full example: [`examples/WebhookMinimalApi`](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/tree/main/dotnet/examples/WebhookMinimalApi).

### Any framework: `Webhook.ConstructEvent()`

```csharp
app.MapPost("/webhooks/labyrinthe", async (HttpRequest request, IConfiguration configuration) =>
{
    using var buffer = new MemoryStream();
    await request.Body.CopyToAsync(buffer);                          // the raw body, untouched

    WebhookEvent evt;
    try
    {
        evt = Webhook.ConstructEvent(buffer.ToArray(), request.Headers[Webhook.SignatureHeader], configuration["Labyrinthe:WebhookSecret"]!);
    }
    catch (Exception e) when (e is SignatureVerificationException or InvalidWebhookPayloadException)
    {
        return Results.BadRequest();
    }

    if (evt is MessageStatusChanged changed)
    {
        await UpdateMessageAsync(changed.Data.MessageId, changed.Data.Status);
    }
    return Results.Ok();
});
```

`ConstructEvent(rawBody, signatureHeader, secret, tolerance)` accepts a `byte[]` or a `string` (the exact body,
decoded as UTF-8); `tolerance` defaults to `Webhook.DefaultTolerance` (5 min), `TimeSpan.Zero` disables the check
(not recommended). `Webhook.VerifySignature(rawBody, header, secret)` only checks the signature;
`Webhook.Sign(body, secret)` builds a valid header for your tests.

### Events

Every event has `Id` (deduplicate on it: deliveries are retried up to 5 times and may arrive out of order), `Type`,
`EventType` (`WebhookEventType`), `Timestamp` (`DateTimeOffset`), `Raw` (`JsonElement`) and a typed `Data`:

| Type | `Type` | `Data` |
|---|---|---|
| `MessageStatusChanged` | `message.status_changed` | `MessageId`, `Recipient`, `Status`, `ProviderMessageId`, `CampaignId`, `Reference`, `ErrorCode`, `ErrorMessage` |
| `OtpVerified` | `otp.verified` | `OtpWebhookData`: `OtpId`, `Phone`, `Status` (`Verified`), `Reason` (`null`), `VerifiedAt` |
| `OtpFailed` | `otp.failed` | `OtpWebhookData`: `Status` `Failed` (`Reason` = `MaxAttemptsReached`) or `Expired` (`Reason` = `Expired`) |
| `CampaignCompleted` | `campaign.completed` | `CampaignId`, `Name`, `RecipientsCount`, `MessagesByStatus`, `CreditsCharged`, `CompletedAt` |
| `BalanceLow` | `balance.low` | `Balance`, `Threshold` |
| `TestPing` | `test.ping` | `JsonElement` (`{}`) |
| `UnknownEvent` | a type added after this SDK version | `JsonElement` as sent; acknowledge it with a 2xx |

Answer `2xx` within 10 seconds; queue slow work.

## Errors

Every exception extends `LabyrintheException` and has `Code` (stable, for your code), `Message` (French, for
humans), `StatusCode` (HTTP status, `null` without a response) and `Details`
(`IReadOnlyDictionary<string, JsonElement>`). API errors extend `ApiException` and also expose `Headers`,
`RequestId` (`X-Request-Id`, give it to support) and `ApiVersion`.

| Exception | HTTP / code | When — useful properties |
|---|---|---|
| `AuthenticationException` | 401 `UNAUTHENTICATED` | Missing, invalid or revoked key |
| `PermissionException` | 403 `FORBIDDEN`, `OTP_DISABLED_FOR_ACCOUNT` | IP not allowed for this key, OTP disabled for the account |
| `AccountSuspendedException` | 403 `ACCOUNT_SUSPENDED` | Extends `PermissionException`; the whole API is closed to the account |
| `NotFoundException` | 404 `NOT_FOUND` | Unknown message or `otp_id`, unknown route |
| `ValidationException` | 422 `VALIDATION_ERROR` | `FieldErrors` (field → messages) |
| `RecipientNotSendableException` | 422 `RECIPIENT_NOT_SENDABLE` | `Rejected`, `Reason`; nothing was sent |
| `SenderIdNotApprovedException` | 422 `SENDER_ID_NOT_APPROVED` | `Sender` is not an approved Sender ID |
| `InvalidPhoneException` | 422 `INVALID_PHONE` | OTP phone number refused by the API |
| `InsufficientCreditsException` | 409 `INSUFFICIENT_CREDITS` | `Available`, `Required`; nothing was sent (never in test mode) |
| `IdempotencyKeyReusedException` | 409 `IDEMPOTENCY_KEY_REUSED` | Same key, different request |
| `IdempotencyRequestInProgressException` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` | `RetryAfter`; already retried by the SDK |
| `IdempotencyKeyUnresolvedException` | 409 `IDEMPOTENCY_KEY_UNRESOLVED` | Outcome unknown, an SMS may have been sent; never retried |
| `RateLimitException` | 429 `TOO_MANY_REQUESTS` | `RetryAfter` |
| `OtpResendTooSoonException`, `OtpRateLimitedException` | 429 | Extend `RateLimitException`; `Scope`, `Limit` (rate limited) |
| `FeatureDisabledException` | 503 `FEATURE_DISABLED`, `OTP_SERVICE_DISABLED` | Extends `ServiceUnavailableException`; maintenance, nothing was done; `Feature`, `BlockedBy`, `AdminMessage`, `Until`, `RetryAfter` |
| `ServiceUnavailableException` | other 5xx | Temporary API failure |
| `ApiException` | any other code | The list is open: new codes may appear |
| `ApiConnectionException`, `ApiTimeoutException` | `CONNECTION_ERROR`, `TIMEOUT` | Network error, attempt timeout (`ApiTimeoutException` extends `ApiConnectionException`) |
| `UnexpectedResponseException` | — | A response the SDK cannot read |
| `SignatureVerificationException`, `InvalidWebhookPayloadException` | — | Webhook verification (answer 400) |

Invalid input detected before any call raises `ArgumentException` (phone numbers are never part of the message).
Cancelling your `CancellationToken` raises `OperationCanceledException`, never `ApiTimeoutException`. Catch the most
specific types first:

```csharp
try
{
    await client.Sms.SendAsync("0812345678", "Hello");
}
catch (InsufficientCreditsException error)
{
    await AlertFinanceAsync(error.Available, error.Required);
}
catch (ValidationException error)
{
    foreach (var (field, messages) in error.FieldErrors) logger.LogWarning("{Field}: {Messages}", field, string.Join(" ", messages));
}
catch (ApiException error)
{
    logger.LogError("Labyrinthe Connect error {Code} (HTTP {Status}, request {RequestId})", error.Code, error.StatusCode, error.RequestId);
}
catch (ApiConnectionException error)
{
    logger.LogError("Labyrinthe Connect unreachable: {Code}", error.Code);   // CONNECTION_ERROR or TIMEOUT
}
```

### Maintenance (503 `FEATURE_DISABLED`)

A platform administrator can temporarily switch off the whole API (`BlockedBy` = `"api"`, every route, even before
authentication) or one function: `api.sms_send` (`Sms.SendAsync` / `SendManyAsync`), `api.otp_send`
(`Otp.SendAsync`), `api.otp_verify` (`Otp.VerifyAsync`). The whole OTP service switched off answers the historical
`OTP_SERVICE_DISABLED` on `Otp.SendAsync`, with the same details (`Feature` = `BlockedBy` = `"otp"`). Nothing is sent
and no credit is used. The SDK retries only after `Retry-After` (an automatic re-enable is scheduled) when it is no
longer than `MaxRetryWait`, never with a blind backoff. Do not retry in a loop: queue the work, tell the user.

```csharp
try
{
    await client.Sms.SendAsync("0812345678", "Hello");
}
catch (FeatureDisabledException error)
{
    // error.Feature: "api.sms_send"; error.BlockedBy: "api" when the whole API is off.
    var text = error.AdminMessage ?? "SMS sending is under maintenance.";
    var retryAt = error.Until ?? DateTimeOffset.UtcNow.Add(error.RetryAfter ?? TimeSpan.FromMinutes(15));
    await outbox.ScheduleAsync(retryAt);                                  // your queue
    logger.LogWarning("Labyrinthe Connect off ({BlockedBy}): {Message}", error.BlockedBy, text);
}
```

`Feature`, `BlockedBy`, `AdminMessage`, `Until` and `RetryAfter` are `null` when the API does not send them. Catch
`FeatureDisabledException` before `ServiceUnavailableException`.

## Retries and idempotency

### Automatic retries

The SDK retries automatically (default: 2 retries, exponential backoff 0.5 s → 1 s → 2 s with jitter, capped at
8 s) **only when a replay cannot cause a side effect twice**:

| Request | Network error / timeout | 5xx | 429 `TOO_MANY_REQUESTS` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` | 503 `FEATURE_DISABLED` |
|---|---|---|---|---|---|
| `GetAsync`, `ListAsync`, `Balance`, `SenderIds`, `EstimateAsync` | retried | retried | after `Retry-After` | — | only after `Retry-After` |
| `Sms.SendAsync` / `SendManyAsync` / `Otp.SendAsync` (always with an `Idempotency-Key`) | retried, same key | retried, same key | after `Retry-After` | after `Retry-After`, same key | only after `Retry-After`, same key |
| `Otp.VerifyAsync` | **never** | **never** | after `Retry-After` (refused before processing) | — | only after `Retry-After` (refused before processing) |

Business errors (other 4xx, `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED`, `IDEMPOTENCY_KEY_UNRESOLVED`) are never
retried. When the API asks to wait longer than `MaxRetryWait`, the SDK throws (`RateLimitException`,
`FeatureDisabledException`…) instead of waiting. Redirects are never followed.

### Idempotency (no double sends)

Every SMS and OTP send carries an `Idempotency-Key`. When you give none, the SDK generates a `Guid` and reuses it for
its own retries, so a network error never produces a second SMS. To also protect against **your** retries
(background jobs, double clicks, crashes), pass a key derived from your business event. A replay within 24 hours
returns the original result without sending or billing again:

```csharp
var batch = await client.Sms.SendManyAsync(new SendManySmsRequest
{
    Recipients = new[] { "0812345678" },
    Body = "Your order 1042 is ready.",
    IdempotencyKey = "order-1042-ready",   // max 255 characters
});
batch.Replayed   // true when this was a replay
```

- The same key with a different content: `IdempotencyKeyReusedException` (409). Recipients are compared after
  normalisation (`0812345678` then `243812345678` is a replay). `/sms` and `/otp/send` have separate key spaces.
- Original request still running: the API answers `409 IDEMPOTENCY_REQUEST_IN_PROGRESS`; the SDK waits
  `Retry-After` and retries with the same key (`IdempotencyRequestInProgressException` if it still is).
- `IdempotencyKeyUnresolvedException` (409 `IDEMPOTENCY_KEY_UNRESOLVED`): the original request failed unexpectedly
  on the API side, its outcome is unknown and **an SMS may already have been sent**. Neither the SDK nor you should
  retry it automatically. Check first (`ListAsync` with the `Reference` you sent, or ask the user whether the code
  arrived), then, only if nothing went out, send again with a **new** key.

```csharp
try
{
    await client.Sms.SendAsync(new SendSmsRequest { To = "0812345678", Body = "…", Reference = "order-1042", IdempotencyKey = key });
}
catch (IdempotencyKeyUnresolvedException e)
{
    var sent = await client.Sms.ListAsync(new ListSmsOptions { Reference = "order-1042" });
    logger.LogWarning("Outcome unknown (request {RequestId}), {Count} message(s) found", e.RequestId, sent.Total);
}
```

## Test mode, logging and security

### Test mode

A `lsk_test_…` key runs against a simulated provider: **nothing is sent, no credit is used**, responses have the
production shape. Two differences: the balance is not checked (no
`InsufficientCreditsException` in test mode) and `Otp.SendAsync` returns the code in `TestCode`. Use test keys in
development, CI and the examples; check `client.IsTestMode` at startup if you want to refuse a live key outside
production.

### Logging

```csharp
Logger = LabyrintheLogger.Create((level, message) => Console.Error.WriteLine($"[{level}] {message}"), LabyrintheLogLevel.Debug)
```

Levels: `Debug` (each attempt and response), `Information` (API errors returned to your code, the default minimum),
`Warning` (automatic retries), `Error` (network errors and timeouts). Implement `ILabyrintheLogger` to plug in another
library; with `Labyrinthe.Connect.AspNetCore`, records go to `ILogger<LabyrintheClient>` (set
`"Labyrinthe.Connect": "Debug"` under `Logging:LogLevel` to see every attempt). Records include the request id and
never contain the key, a code, a message body or a full phone number (`24381*****78`).

### Security

- `client.ToString()` and `options.ToString()` show `lsk_test_****`; `OtpSendResult.ToString()` hides `TestCode`;
  `Message.ToString()` and `OtpWebhookData.ToString()` mask the phone number.
- Keys stay server-side; store them in user secrets, environment variables or a vault, never in `appsettings.json`
  committed to source control.
- Webhooks: constant-time signature check on the raw body, timestamp tolerance, deduplicate on `Id`.

### Request ids

Every response carries `X-Request-Id`. It is exposed on `ApiException.RequestId` and on the results
(`MessageBatch`, `Estimate`, `Balance`, `OtpSendResult`, `OtpVerifyResult`, `Page<T>`; not part of record
equality). To send your own identifier (trace id, order number), call through a lightweight view of the client:

```csharp
var batch = await client.WithRequestId("order-1042").Sms.SendManyAsync(request);   // 1-128 chars: A-Z a-z 0-9 . _ : -
batch.RequestId   // "order-1042"
```

The view shares the connections of `client` (no need to dispose it) and sends the id on every retry.

### Timeouts and cancellation

`Timeout` bounds **each attempt**; an attempt that exceeds it raises `ApiTimeoutException` (and is retried when that
is safe). Every method takes a `CancellationToken` that bounds the **whole call**, retries and waits included:

```csharp
using var cts = new CancellationTokenSource(TimeSpan.FromSeconds(10));
var balance = await client.Balance.GetAsync(cts.Token);   // OperationCanceledException after 10 s
```

In ASP.NET Core, pass `HttpContext.RequestAborted` (or the `CancellationToken` parameter of a minimal API handler).
Cancelling a send after the request left does not cancel it on the server: resend with the **same**
`IdempotencyKey` to learn the outcome without sending twice.

## Without the package (raw HTTP)

The API is plain JSON over HTTPS; the SDK is a convenience. With `HttpClient` only:

```csharp
using System.Net.Http.Headers;
using System.Net.Http.Json;

using var http = new HttpClient { BaseAddress = new Uri("https://api.connect.labyrinthe-rdc.com/api/public/v1/") };
http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", Environment.GetEnvironmentVariable("LABYRINTHE_API_KEY"));

using var request = new HttpRequestMessage(HttpMethod.Post, "sms")
{
    Content = JsonContent.Create(new { recipient = "+243812345678", body = "Your order 1042 is ready.", reference = "order-1042" }),
};
request.Headers.Add("Idempotency-Key", Guid.NewGuid().ToString());   // keep it to retry safely

using var response = await http.SendAsync(request);
var json = await response.Content.ReadAsStringAsync();
// 202: { "data": [ { "id": "…", "recipient": "243812345678", "status": "queued", … } ] }
// error: { "error": { "code": "…", "message": "…", "details": { } } }, plus X-Request-Id
```

Webhook signature without the package: compute `"sha256=" + Convert.ToHexString(HMACSHA256.HashData(secretBytes,
rawBody)).ToLowerInvariant()`, compare it to `X-Signature` with `CryptographicOperations.FixedTimeEquals`, then
check that the `timestamp` of the envelope (Unix seconds) is recent. You then handle retries, idempotency, error
mapping and number formats yourself.

Reference: the OpenAPI specification [`openapi/openapi.yaml`](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/blob/main/openapi/openapi.yaml)
(1.3.1), also served by the API at `/api/public/openapi.yaml`.

### Known API limits

- Webhook endpoints are managed in the client area only (no API).
- `INSUFFICIENT_CREDITS` cannot be reproduced with a test key.
- `EstimateAsync` counts blacklisted or opted-out numbers that `SendManyAsync` would refuse.
- `ListAsync` returns the messages of the calling key; `GetAsync` finds any message of the account.

## Upgrading

The SDK follows SemVer; 1.x versions are backward compatible. See the
[CHANGELOG](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/blob/main/dotnet/CHANGELOG.md).

- **1.2.0 → 1.2.1** (API 1.3.1): no code change. The default API URL is now `https://api.connect.labyrinthe-rdc.com/api/public/v1` (the 1.2.0 default
  did not answer). Upgrade, or set this URL explicitly if you stay on 1.2.0.
- **1.1.x → 1.2.0** (API 1.3.0): new `FeatureDisabledException` (503 `FEATURE_DISABLED`). `OTP_SERVICE_DISABLED` is
  now raised as `FeatureDisabledException` (still a `ServiceUnavailableException`, so existing `catch` blocks keep
  working). Both are retried only after `Retry-After`, no longer with the 5xx backoff.
- **1.1.0 → 1.1.1**: numbers are sent in E.164 with `+`; new `IdempotencyKeyUnresolvedException`.
- **1.0.x → 1.1.0** (API 1.2.0): `RequestId` on errors and results, `WithRequestId()`, idempotent OTP sends,
  `StripAccents`, `WebOtpDomain`, `otp.verified` / `otp.failed` events. Without `+`, 11 digits or more not starting
  with 0 are now read as international.

## Contributing

Issues and pull requests go to the [monorepo](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks); read
[CONTRIBUTING.md](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/blob/main/CONTRIBUTING.md) first. From
`dotnet/`:

```bash
dotnet format --verify-no-changes
dotnet build -c Release -warnaserror      # TreatWarningsAsErrors + .NET analyzers
dotnet test -c Release                    # unit + OpenAPI contract tests (mocked HTTP, no network)
dotnet pack src/Labyrinthe.Connect -c Release -o artifacts   # .nupkg + .snupkg

# integration tests against a local backend, with a TEST key only
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 dotnet test --filter Category=Integration
```

Examples (built with the solution):
[`SendSms`](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/tree/main/dotnet/examples/SendSms) (console),
[`OtpLogin`](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/tree/main/dotnet/examples/OtpLogin) (OTP flow),
[`WebhookMinimalApi`](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/tree/main/dotnet/examples/WebhookMinimalApi)
(ASP.NET Core). Security issues: connect@labyrinthe-rdc.com rather than a public issue.

## License

MIT — see [LICENSE](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/blob/main/dotnet/LICENSE).
