# Labyrinthe Connect — .NET SDK

[![CI](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/actions/workflows/dotnet.yml/badge.svg)](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/actions/workflows/dotnet.yml)
[![NuGet](https://img.shields.io/nuget/v/Labyrinthe.Connect.svg)](https://www.nuget.org/packages/Labyrinthe.Connect)
![.NET](https://img.shields.io/badge/.NET-netstandard2.0%20%7C%20net8.0-512BD4)

Official .NET client for [Labyrinthe Connect](https://labyrinthe-rdc.com): send SMS and verify phone numbers with
one-time codes (OTP), in the DR Congo and Central Africa.

*Version française : [README.fr.md](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/blob/main/dotnet/README.fr.md).*

- `async` everywhere, with `CancellationToken`; `IAsyncEnumerable` pagination. Nullable annotations, immutable records.
- Targets `netstandard2.0` (.NET Framework 4.7.2+, Mono, Unity…) and `net8.0` (trimming / AOT compatible).
- Safe by default: automatic retries **only** when they cannot send an SMS twice.
- One dependency on `netstandard2.0` (`System.Text.Json`), none on `net8.0`.
- [`Labyrinthe.Connect.AspNetCore`](https://www.nuget.org/packages/Labyrinthe.Connect.AspNetCore): `AddLabyrinthe()` with
  `IHttpClientFactory` and `ILogger`, and a minimal API webhook endpoint.
- Never logs or prints your API key, OTP codes or full phone numbers.

## Installation

```bash
dotnet add package Labyrinthe.Connect
dotnet add package Labyrinthe.Connect.AspNetCore   # ASP.NET Core integration (optional)
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

`client.IsTestMode` tells you which one you are using. Every example below works with a test key. A
`LabyrintheClient` is thread-safe: create one (or register it with `AddLabyrinthe`) and reuse it.

## Sending SMS

```csharp
var message = await client.Sms.SendAsync(new SendSmsRequest
{
    To = "+243 81 234 5678",        // +243…, 00243…, 243…, 0812…, 812…, 33612345678 are all accepted
    Body = "Your order 1042 is ready.",
    Sender = "MYSHOP",              // optional: an approved Sender ID (client.SenderIds.ListAsync())
    Reference = "order-1042",       // optional: your own reference, max 64 characters
});

message.Id              // "9d5c0a3e-…"
message.Recipient       // "243812345678"
message.Status          // MessageStatus.Queued
message.Segments        // 1
message.CreditsCharged  // 1
message.CreatedAt       // DateTimeOffset (UTC)
```

Sending is asynchronous: `202 Accepted` means *queued and paid for*, not *delivered*. Follow the status with
webhooks (`message.status_changed`, recommended) or by polling:

```csharp
var latest = await client.Sms.GetAsync(message.Id);
if (latest.Status.IsFinal())
{
    Console.WriteLine(latest.Status == MessageStatus.Delivered ? "Delivered" : $"Failed: {latest.ErrorCode}");
}
```

### Cost before sending

1 credit = 1 SMS segment. A pure ASCII message (GSM-7) holds 160 characters (153 per segment beyond). **Any
non-ASCII character** (é, à, ç, emoji, curly quotes…) switches to UCS-2: 70 characters (67 per segment beyond).

```csharp
var estimate = await client.Sms.EstimateAsync(new EstimateSmsRequest
{
    Body = "Réunion demain à 9h",
    Recipients = new[] { "0812345678", "0899999999" },
});
estimate.Encoding      // SmsEncoding.Ucs2
estimate.TotalCredits  // 2
estimate.IsAffordable  // does the balance cover it?
```

`StripAccents = true` (on `SendSmsRequest`, `SendManySmsRequest`, `EstimateSmsRequest` and `SendOtpRequest`) lets the
server replace accents and typographic characters with ASCII: the message becomes GSM-7, often half the price. The
transliteration is done by the server; do not rely on it for an exact text.

Phone numbers: without `+`, 11 digits or more not starting with 0 are already international (`33612345678` stays
`33612345678`); otherwise the number is local and gets the default country code (`0812345678` → `243812345678`). For a
short foreign number (10 digits or fewer), always use `+`.

### Several recipients: all or nothing

```csharp
try
{
    var batch = await client.Sms.SendManyAsync(new SendManySmsRequest
    {
        Recipients = new[] { "0812345678", "0899999999" },
        Body = "Meeting tomorrow at 9am.",
    });
    // batch.Messages: one message per distinct number (duplicates are merged)
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

Up to 1000 recipients per call. Every recipient, the Sender ID and the balance are checked **before** anything is
sent: one refused number (or an insufficient balance) means no SMS at all. Numbers the SDK can already tell are
invalid are refused locally (`ArgumentException`, with their index only), before any network call.

### Idempotency (no double sends)

Every send carries an `Idempotency-Key`. When you give none, the SDK generates a `Guid` and reuses it for its own
retries, so a network error never produces a second SMS. To also protect against **your** retries (background jobs,
double clicks, crashes), pass a key derived from your business event. A replay within 24 hours returns the original
messages without sending or billing again:

```csharp
var batch = await client.Sms.SendManyAsync(new SendManySmsRequest
{
    Recipients = new[] { "0812345678" },
    Body = "Your order 1042 is ready.",
    IdempotencyKey = "order-1042-ready",
});
batch.Replayed   // true when this was a replay
```

The same key with a different content is refused with `IdempotencyKeyReusedException` (409). While the original
request is still being processed, the API answers `409 IDEMPOTENCY_REQUEST_IN_PROGRESS`: the SDK waits `Retry-After`
and retries with the same key (`IdempotencyRequestInProgressException` if it still is after its retries).

`IdempotencyKeyUnresolvedException` (409 `IDEMPOTENCY_KEY_UNRESOLVED`, `SendAsync`, `SendManyAsync` and
`Otp.SendAsync`) means the original request with this key failed unexpectedly on the API side: its outcome is
unknown and **an SMS may already have been sent**. The SDK never retries it, and you must not retry it automatically
either. Check first: `client.Sms.ListAsync(new ListSmsOptions { Reference = "order-1042" })` for an SMS sent with a
`Reference`, or ask the user whether the code arrived for an OTP. Then, only if nothing went out, send again with a
**new** `Idempotency-Key` (the old one stays unresolved). Log `RequestId` for support.

```csharp
try
{
    await client.Sms.SendAsync(new SendSmsRequest { To = "0812345678", Body = "…", Reference = "order-1042", IdempotencyKey = key });
}
catch (IdempotencyKeyUnresolvedException e)
{
    logger.LogWarning("Outcome unknown (request {RequestId}): check GET /sms?reference=order-1042 before resending with a new key.", e.RequestId);
}
```

### Listing messages

```csharp
var page = await client.Sms.ListAsync(new ListSmsOptions
{
    Status = MessageStatus.Failed,
    From = DateFilter.Day(2026, 11, 1),    // a whole day (or a DateOnly)
    To = DateTimeOffset.UtcNow,            // an exact instant
    PerPage = 50,
});
// page.Data, page.Total, page.HasMore, await page.GetNextPageAsync()

await foreach (var message in client.Sms.ListAutoPagingAsync(new ListSmsOptions { Reference = "order-1042" }))
{
    // every page, fetched lazily
}
```

Filters: `Status`, `Recipient` (any format), `Reference`, `From`, `To` (inclusive), `Page`, `PerPage` (max 100).
Only messages sent **with the calling key**.

### Balance and Sender IDs

```csharp
var balance = await client.Balance.GetAsync();        // balance.Available, balance.Held
var senderIds = await client.SenderIds.ListAsync();   // approved names, usable as Sender
```

## OTP: verify a phone number

The code is generated, sent by SMS and checked by Labyrinthe Connect. It is never returned by the API (except with a
test key) and never logged by the SDK.

```csharp
// 1. Send the code. Keep OtpId SERVER-SIDE (session, cache, database), never in the browser.
var otp = await client.Otp.SendAsync("0812345678");
HttpContext.Session.SetString("otp_id", otp.OtpId);
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

- A wrong code is **not** an exception: `VerifyAsync` returns `OtpVerifyResult { Verified = false, Reason = … }`.
- A new send to the same number invalidates the previous code.
- Length, validity, attempts, Sender ID and template come from the account's OTP settings. You can override the
  template (`new SendOtpRequest { Phone = …, Template = "Your MyShop code: {code}" }`, must contain `{code}`;
  optional `{app_name}`, `{minutes}`) and the language (`Locale = OtpLocale.Fr` or `En`).
- `WebOtpDomain = "app.example.com"` adds the line `@app.example.com #123456` to the SMS for automatic filling with the
  [Web OTP API](https://developer.mozilla.org/docs/Web/API/WebOTP_API) (Chrome, Android).
- Limits: `OtpResendTooSoonException` and `OtpRateLimitedException` (`Scope`, `Limit`), both with `RetryAfter` (the
  `Retry-After` header). Never retried by the SDK.
- **Test mode**: with a `lsk_test_` key no SMS is sent and `otp.TestCode` holds the code, so you can test the whole
  flow. Always `null` with a live key; never shown by `ToString()`.

`Otp.SendAsync` always carries an `Idempotency-Key` (`SendOtpRequest.IdempotencyKey`, or a generated `Guid` reused by
its retries): it is retried safely after a network error, a 5xx or a 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS`, and a
replay returns the same `OtpId` without a second SMS (`otp.Replayed`). `VerifyAsync` is retried only on 429
`TOO_MANY_REQUESTS` and on 503 `FEATURE_DISABLED` after its `Retry-After` (an attempt may have been consumed
otherwise). Maintenance: `FeatureDisabledException` (see [Errors](#maintenance-503-feature_disabled)).

Every verification outcome is also notified by the `otp.verified` and `otp.failed` webhooks.

## Webhooks

Configure an endpoint in the client area (*Developers > Webhooks*) and copy its secret. Events are `POST`ed as JSON and
signed (`X-Signature: sha256=<HMAC-SHA256 of the raw body>`). The SDK verifies the signature in constant time
(`CryptographicOperations.FixedTimeEquals`) on the **raw body**, then checks the signed timestamp (5 minutes by default).

```csharp
try
{
    var evt = Webhook.ConstructEvent(rawBody, request.Headers["X-Signature"], secret);   // raw body: string or byte[]
    switch (evt)
    {
        case MessageStatusChanged changed:
            await UpdateMessageAsync(changed.Data.MessageId, changed.Data.Status);
            break;
        case BalanceLow low:
            await AlertFinanceAsync(low.Data.Balance, low.Data.Threshold);
            break;
    }
}
catch (SignatureVerificationException)
{
    // answer 400
}
```

Every event has `Id` (deduplicate on it: deliveries are retried up to 5 times and may arrive out of order), `Type`,
`EventType`, `Timestamp` (`DateTimeOffset`, UTC), `Raw` and a typed `Data`:

| Type | `Type` | `Data` |
|---|---|---|
| `MessageStatusChanged` | `message.status_changed` | `MessageId`, `Recipient`, `Status`, `ProviderMessageId`, `CampaignId`, `Reference`, `ErrorCode`, `ErrorMessage` |
| `OtpVerified` | `otp.verified` | `OtpId`, `Phone`, `Status` (`Verified`), `Reason` (`null`), `VerifiedAt` |
| `OtpFailed` | `otp.failed` | `OtpId`, `Phone`, `Status` (`Failed` / `Expired`), `Reason` (`MaxAttemptsReached` / `Expired`) |
| `CampaignCompleted` | `campaign.completed` | `CampaignId`, `Name`, `RecipientsCount`, `MessagesByStatus`, `CreditsCharged`, `CompletedAt` |
| `BalanceLow` | `balance.low` | `Balance`, `Threshold` |
| `TestPing` | `test.ping` | `JsonElement` (`{}`) |
| `UnknownEvent` | a type added after this SDK version | `JsonElement` as sent; acknowledge it with a 2xx |

Invalid signature or expired timestamp: `SignatureVerificationException`; signed body that is not an envelope:
`InvalidWebhookPayloadException` — answer `400` to both. Answer `2xx` within 10 seconds; queue slow work.
`Webhook.Sign(body, secret)` builds a valid header for your tests.

## ASP.NET Core

```csharp
// appsettings.json: { "Labyrinthe": { "ApiKey": "lsk_test_…", "WebhookSecret": "…" } }
builder.Services.AddLabyrinthe(builder.Configuration);              // LabyrintheClient via IHttpClientFactory
builder.Services.AddLabyrintheWebhookHandler<MyWebhookHandler>();   // ILabyrintheWebhookHandler (scoped)

var app = builder.Build();
app.MapLabyrintheWebhooks("/webhooks/labyrinthe");                  // POST: raw body + signature + timestamp checked

app.MapPost("/notify", async (string to, LabyrintheClient client) =>
    (await client.Sms.SendAsync(to, "Your order is ready.")).Id);

sealed class MyWebhookHandler : ILabyrintheWebhookHandler
{
    public Task HandleAsync(WebhookEvent webhookEvent, CancellationToken cancellationToken) => …;
}
```

- The `Labyrinthe` section binds every `LabyrintheClientOptions` property (`ApiKey`, `BaseUrl`, `Timeout`,
  `MaxRetries`, `MaxRetryWait`, `DefaultCountryCode`, `DefaultSender`) and `LabyrintheWebhookOptions`
  (`WebhookSecret`, `WebhookTolerance`, `WebhookMaxBodySize`). Environment variables work too
  (`Labyrinthe__ApiKey`); without a key, `LABYRINTHE_API_KEY` is used, without a secret `LABYRINTHE_WEBHOOK_SECRET`.
- SDK logs go to `ILogger<LabyrintheClient>` (category `Labyrinthe.Connect.LabyrintheClient`).
- `AddLabyrinthe` returns the `IHttpClientBuilder`: add a proxy or a handler, but **no retry policy** (the SDK already
  retries when it is safe, and never otherwise). Redirects are not followed.
- `MapLabyrintheWebhooks(pattern, (evt, ct) => …)` and `MapLabyrintheWebhooks(pattern, handlerInstance)` also exist.
  Bad signature, expired timestamp or invalid envelope: `400`; body over `WebhookMaxBodySize` (1 MiB): `413`; an
  exception in your handler: `500` (the API delivers the event again).

See [`examples/WebhookMinimalApi`](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/tree/main/dotnet/examples/WebhookMinimalApi).

## Errors

Every exception extends `LabyrintheException` and has `Code` (stable, for your code), `Message`, `StatusCode` (HTTP
status, or `null` without a response) and `Details` (`IReadOnlyDictionary<string, JsonElement>`). API errors extend
`ApiException` and also expose `Headers`, `RequestId` (`X-Request-Id`, give it to support) and `ApiVersion`.

Results (`MessageBatch`, `Estimate`, `Balance`, `OtpSendResult`, `OtpVerifyResult`, `Page<T>`) expose `RequestId` too.
To use your own identifier (trace id, order number), call through a lightweight view of the client:

```csharp
var batch = await client.WithRequestId("order-1042").Sms.SendManyAsync(request);   // 1-128 chars: A-Z a-z 0-9 . _ : -
batch.RequestId   // "order-1042"
```

| Exception | When |
|---|---|
| `AuthenticationException` | 401 — missing, invalid or revoked key |
| `PermissionException` | 403 — IP not allowed (`FORBIDDEN`), OTP disabled for the account (`OTP_DISABLED_FOR_ACCOUNT`) |
| `AccountSuspendedException` | 403 `ACCOUNT_SUSPENDED` — extends `PermissionException`; the whole API is closed |
| `NotFoundException` | 404 — unknown message or `otp_id` |
| `ValidationException` | 422 `VALIDATION_ERROR` — `FieldErrors` |
| `RecipientNotSendableException` | 422 — `Rejected`, `Reason`; nothing was sent |
| `SenderIdNotApprovedException` | 422 — `Sender` is not an approved Sender ID |
| `InvalidPhoneException` | 422 `INVALID_PHONE` (OTP) |
| `InsufficientCreditsException` | 409 — `Available`, `Required`; nothing was sent |
| `IdempotencyKeyReusedException` | 409 — same key, different request |
| `IdempotencyRequestInProgressException` | 409 — original request still in progress (`RetryAfter`); already retried |
| `IdempotencyKeyUnresolvedException` | 409 — original request failed unexpectedly, outcome unknown (an SMS may have been sent); never retried: check, then resend with a new key |
| `RateLimitException` | 429 `TOO_MANY_REQUESTS` — `RetryAfter` |
| `OtpResendTooSoonException`, `OtpRateLimitedException` | 429 — extend `RateLimitException`; `Scope`, `Limit` |
| `FeatureDisabledException` | 503 `FEATURE_DISABLED` / `OTP_SERVICE_DISABLED` — extends `ServiceUnavailableException`; feature switched off by the platform (maintenance), nothing was done; `Feature`, `BlockedBy`, `AdminMessage`, `Until`, `RetryAfter` |
| `ServiceUnavailableException` | other 5xx |
| `ApiException` | any other code (the list is open: new codes may appear) |
| `ApiConnectionException`, `ApiTimeoutException` | network error (`CONNECTION_ERROR`) or timeout (`TIMEOUT`) |
| `UnexpectedResponseException` | a response the SDK cannot read |
| `SignatureVerificationException`, `InvalidWebhookPayloadException` | webhook verification (answer 400) |

Invalid input detected before any call raises `ArgumentException` (numbers are never part of the message).
Cancelling your `CancellationToken` raises `OperationCanceledException`, never `ApiTimeoutException`.

```csharp
try
{
    await client.Sms.SendAsync("0812345678", "Hello");
}
catch (InsufficientCreditsException error)
{
    AlertFinance(error.Available, error.Required);
}
catch (ApiException error)
{
    logger.LogError("Labyrinthe Connect error {Code} (HTTP {Status})", error.Code, error.StatusCode);
}
```

### Maintenance (503 `FEATURE_DISABLED`)

A platform administrator can temporarily switch off the whole API (`BlockedBy` = `"api"`, every route, even before
authentication) or one function: `api.sms_send` (`Sms.SendAsync` / `SendManyAsync`), `api.otp_send`
(`Otp.SendAsync`), `api.otp_verify` (`Otp.VerifyAsync`). The whole OTP service switched off answers the historical
`OTP_SERVICE_DISABLED` on `Otp.SendAsync`, with the same details (`Feature` = `BlockedBy` = `"otp"`). Nothing is sent
and no credit is used. The SDK never retries it with a blind backoff: only after `Retry-After` (automatic re-enable
scheduled) when it is no longer than `MaxRetryWait`. Do not retry in a loop: queue the work, tell the user.

```csharp
try
{
    await client.Sms.SendAsync("0812345678", "Hello");
}
catch (FeatureDisabledException error)
{
    // error.Feature: "api.sms_send"; error.BlockedBy: "api" when the whole API is off.
    var message = error.AdminMessage ?? "SMS sending is under maintenance.";
    var retryAt = error.Until ?? DateTimeOffset.UtcNow.AddMinutes(15);   // Until / RetryAfter: null when unknown
    await outbox.ScheduleAsync(retryAt);                                  // your queue
    logger.LogWarning("Labyrinthe Connect off ({BlockedBy}): {Message}", error.BlockedBy, message);
}
```

## Retries and timeouts

The SDK retries automatically (default: 2 retries, exponential backoff 0.5 s → 1 s → 2 s with jitter) **only when a
replay cannot cause a side effect twice**:

| Request | Network error / timeout | 5xx | 429 `TOO_MANY_REQUESTS` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` |
|---|---|---|---|---|
| `GetAsync`, `ListAsync`, `Balance`, `SenderIds`, `EstimateAsync` | retried | retried | retried after `Retry-After` | — |
| `SendAsync` / `SendManyAsync` / `Otp.SendAsync` (always with an `Idempotency-Key`) | retried, same key | retried, same key | retried after `Retry-After` | retried after `Retry-After`, same key |
| `Otp.VerifyAsync` | **never** | **never** | retried (refused before processing) | **never** |

Business errors (4xx, `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED`, `IDEMPOTENCY_KEY_UNRESOLVED`) are never retried.
503 `FEATURE_DISABLED` / `OTP_SERVICE_DISABLED` (`FeatureDisabledException`, refused before processing) is retried by
every method, `Otp.VerifyAsync` included, **only** after its `Retry-After`; without it, it is thrown at once (no
backoff). When the API asks to wait longer than `MaxRetryWait` (default 10 s), the SDK throws the error
(`RateLimitException`, `FeatureDisabledException`…) instead of waiting. Redirects are never
followed. `Timeout` applies to each attempt; your `CancellationToken` bounds the whole call (retries included).

## Configuration

```csharp
var client = new LabyrintheClient(new LabyrintheClientOptions
{
    ApiKey = Environment.GetEnvironmentVariable("LABYRINTHE_API_KEY"),   // default
    BaseUrl = "https://api.labyrinthe-rdc.com/api/public/v1",          // default (or LABYRINTHE_BASE_URL); a bare host is completed
    Timeout = TimeSpan.FromSeconds(30),     // per attempt
    MaxRetries = 2,                         // 0 disables retries (max 10)
    MaxRetryWait = TimeSpan.FromSeconds(10),// longest Retry-After the SDK waits for
    DefaultCountryCode = "243",             // added to local numbers: 0812345678 → 243812345678
    DefaultSender = "MYSHOP",               // used when a send gets no Sender
    Logger = LabyrintheLogger.Create((level, message) => Console.Error.WriteLine($"[{level}] {message}")),
}, httpClient: myHttpClient);               // optional: your own HttpClient (never disposed by the SDK)
```

- **Logging**: records (debug: each attempt; information: API errors; warning: retries; error: network errors) never
  contain the key, a code, a body or a full phone number (`24381*****78`). Nothing is logged without a `Logger`.
- `client.ToString()` shows `lsk_test_****`; `OtpSendResult.ToString()` hides `TestCode`; `Message.ToString()` masks
  the recipient.
- `PhoneNumber.Normalize("0812345678")` returns `"243812345678"` (or `null`) with the same rules as the API;
  `IsValid()` and `Mask()` are also available. International numbers are never blocked. Requests always send the
  number in E.164 with "+" (`+243812345678`), so a short foreign number keeps its country code.
- If you pass your own `HttpClient`, configure its handler with `AllowAutoRedirect = false` and leave its `Timeout`
  above the SDK's.
- `User-Agent: labyrinthe-dotnet/<version> .NET/<runtime version>`.

## Development

```bash
dotnet build -c Release -warnaserror      # TreatWarningsAsErrors + .NET analyzers
dotnet test -c Release                    # unit + OpenAPI contract tests (mocked HTTP, no network)
dotnet format --verify-no-changes
dotnet pack -c Release -o artifacts       # .nupkg + .snupkg

# integration tests against a local backend, with a TEST key only
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 dotnet test --filter Category=Integration
```

The API contract lives in [`openapi/openapi.yaml`](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/blob/main/openapi/openapi.yaml);
the contract tests keep the SDK aligned with it. Examples:
[`examples/`](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/tree/main/dotnet/examples) (console SMS, OTP login,
ASP.NET Core webhook receiver).

## License

MIT — see [LICENSE](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/blob/main/dotnet/LICENSE).
