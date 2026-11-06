# Labyrinthe Connect — PHP SDK

[![CI](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/actions/workflows/php.yml/badge.svg)](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/actions/workflows/php.yml)
[![Packagist](https://img.shields.io/packagist/v/labyrinthe/connect-php.svg)](https://packagist.org/packages/labyrinthe/connect-php)
![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.1-777bb4)

Official PHP client for [Labyrinthe Connect](https://labyrinthe-rdc.com): send SMS and verify phone numbers
with one-time codes (OTP), in the DR Congo and Central Africa.

*Version française : [README.fr.md](README.fr.md). Laravel? Use [`labyrinthe/connect-laravel`](../laravel).*

- Typed everything: immutable response objects, enums, one exception class per error.
- Safe by default: automatic retries **only** when they cannot send an SMS twice.
- Works with Guzzle out of the box, or with any PSR-18 client.
- Never logs or dumps your API key, OTP codes or full phone numbers.

## Installation

```bash
composer require labyrinthe/connect-php
```

Requires PHP 8.1+ with the `json` and `mbstring` extensions.

## Quick start

```php
use Labyrinthe\Connect\Client;

$client = new Client('lsk_test_…');                 // or Client::fromEnv()
$message = $client->sms->send(to: '0812345678', body: 'Your order 1042 is ready.');
echo $message->status->value;                         // "queued"
```

Create your keys in the client area (*Developers > API keys*). **Keep them server-side**, never in a browser or a
mobile app.

| Key | Mode | Effect |
|---|---|---|
| `lsk_test_…` | Sandbox | Nothing is sent, no credit is used. Same responses as production. OTP sends return the code (`testCode()`). |
| `lsk_live_…` | Production | SMS are really sent and credits are used. |

`$client->isTestMode()` tells you which one you are using. All examples below work with a test key.

## Sending SMS

```php
$message = $client->sms->send(
    to: '+243 81 234 5678',        // +243…, 00243…, 243…, 0812…, 812… are all accepted
    body: 'Your order 1042 is ready.',
    sender: 'MYSHOP',              // optional: an approved Sender ID ($client->senderIds->list())
    reference: 'order-1042',       // optional: your own reference, max 64 characters
);

$message->id;               // "9d5c0a3e-…"
$message->recipient;        // "243812345678"
$message->status;           // MessageStatus::Queued
$message->segments;         // 1
$message->creditsCharged;   // 1
```

Sending is asynchronous: `202 Accepted` means *queued and paid for*, not *delivered*. Follow the status with
webhooks (`message.status_changed`, recommended) or by polling:

```php
$message = $client->sms->get($message->id);
if ($message->status->isFinal()) {
    echo $message->status->isSuccessful() ? 'Delivered' : 'Failed: '.$message->errorCode;
}
```

### Cost before sending

1 credit = 1 SMS segment. A pure ASCII message (GSM-7) holds 160 characters (153 per segment beyond). **Any
non-ASCII character** (é, à, ç, emoji, curly quotes…) switches to UCS-2: 70 characters (67 per segment beyond),
often doubling the cost.

```php
$estimate = $client->sms->estimate('Réunion demain à 9h', ['0812345678', '0899999999']);
$estimate->encoding;       // Encoding::Ucs2
$estimate->totalCredits;   // 2
$estimate->isAffordable(); // balance covers it?

// stripAccents: accents and typographic characters replaced by ASCII → GSM-7, often half the cost.
$client->sms->estimate('Réunion demain à 9h', '0812345678', stripAccents: true)->encoding; // Encoding::Gsm7
$client->sms->send('0812345678', 'Réunion demain à 9h', stripAccents: true);
```

Recipients are counted like a send (normalised, duplicates merged). `stripAccents` (also on `sendMany()` and
`otp->send()`) is never applied unless you ask; the transliteration depends on the server (`Café` may become `Cafe`
or `Caf'e`), so do not rely on it for an exact text.

### Several recipients: all or nothing

```php
use Labyrinthe\Connect\Exception\RecipientNotSendableException;

try {
    $batch = $client->sms->sendMany(['0812345678', '0899999999'], 'Meeting tomorrow at 9am.');
    count($batch);           // one Message per distinct number (duplicates are merged)
} catch (RecipientNotSendableException $e) {
    // NOTHING was sent: fix the list and try again.
    foreach ($e->getRejected() as $rejected) {
        echo $rejected->recipient.': '.$rejected->reason->value; // invalid_phone, blacklisted, opted_out
    }
}
```

Up to 1000 recipients per call. Every recipient, the Sender ID and the balance are checked **before** anything is
sent: one refused number (or an insufficient balance) means no SMS at all. Numbers the SDK can already tell are
invalid are refused locally (`InvalidArgumentException`), before any network call.

### Idempotency (no double sends)

Every send carries an `Idempotency-Key`. When you do not give one, the SDK generates a UUID v4 and reuses it for
its own retries, so a network error never produces a second SMS.

To also protect against **your** retries (queue jobs, double clicks, crashes), pass a key derived from your
business event. A replay within 24 hours returns the original messages without sending or billing again:

```php
$batch = $client->sms->sendMany(['0812345678'], 'Your order 1042 is ready.', idempotencyKey: 'order-1042-ready');
$batch->replayed;   // true when this was a replay
```

The same key with a different content (recipients, body, sender, reference or `stripAccents`) is refused with
`IdempotencyKeyReusedException` (409). Recipients are compared after normalisation: `0812345678` then
`243812345678` with the same key is a replay. While the original request is still running, the API answers
`409 IDEMPOTENCY_REQUEST_IN_PROGRESS`: the SDK waits `Retry-After` (5 s) and sends it again with the same key.

**Unknown outcome.** `IdempotencyKeyUnresolvedException` (409 `IDEMPOTENCY_KEY_UNRESOLVED`, `send()`, `sendMany()`
and `otp->send()`): the original request with this key failed unexpectedly on the API side and its outcome is
unknown — **an SMS may have been sent**. The SDK never retries it, and neither should you with the same key. Check
first (`$client->sms->list(reference: …)` for an SMS, or ask the user whether the code arrived), then, if nothing
was sent, send again with a **new** `idempotencyKey`. Log `getRequestId()` for support.

### Listing messages

```php
use Labyrinthe\Connect\Enum\MessageStatus;

$page = $client->sms->list(status: MessageStatus::Failed, from: '2026-11-01', to: new DateTime(), perPage: 50);
$page->total;

foreach ($page->autoPaging() as $message) {   // every page, fetched lazily
    // …
}
```

Filters: `status`, `recipient` (any format), `reference`, `from`, `to` (a date covers the whole day), `page`,
`perPage` (max 100). The list only contains messages sent **with the calling API key**.

### Balance and Sender IDs

```php
$client->balance->get()->balance;      // available credits
foreach ($client->senderIds->list() as $senderId) {
    echo $senderId->value;              // approved names, usable as `sender`
}
```

## OTP: verify a phone number

The code is generated, sent by SMS and checked by Labyrinthe Connect. It is never returned by the API (except
with a test key), never logged by the SDK.

```php
// 1. Send the code. Keep otp_id SERVER-SIDE (session, cache, database) — never in a hidden form field.
$otp = $client->otp->send('0812345678');
$_SESSION['otp_id'] = $otp->otpId;
$otp->codeLength;          // 6: size of the input
$otp->codeType;            // OtpCodeType::Numeric (numeric keypad) or OtpCodeType::Alphanumeric
$otp->resendAfterSeconds;  // countdown before "Resend the code"

// 2. The user types the code received by SMS.

// 3. Verify it.
$result = $client->otp->verify($_SESSION['otp_id'], $_POST['code']);
if ($result->isValid()) {
    unset($_SESSION['otp_id']);
    // log the user in / mark the number as verified
} else {
    match ($result->reason) {
        OtpFailureReason::InvalidCode => "Wrong code, {$result->attemptsRemaining} attempt(s) left.",  // one attempt consumed
        OtpFailureReason::Expired, OtpFailureReason::AlreadyUsed,
        OtpFailureReason::MaxAttemptsReached => 'Please request a new code.',
        default => 'Could not verify the code.',
    };
}
```

- A wrong code is **not** an exception: `verify()` returns `verified: false` with a reason.
- A new `send()` to the same number invalidates the previous code.
- Length, validity, attempts, Sender ID and message template come from the account's OTP settings (client area >
  *OTP verification*). You can override the template (`template: 'Your MyShop code: {code}'`, must contain
  `{code}`; optional `{app_name}`, `{minutes}`) and the language (`locale: 'fr'|'en'`).
- `attemptsRemaining`: attempts left after `invalid_code`, `0` with `max_attempts_reached`, `null` otherwise.
- Limits: `OtpResendTooSoonException` (wait `getRetryAfter()` seconds before resending to this number; a resend is
  accepted right away once the code was verified) and `OtpRateLimitedException` (hourly cap per number or per
  account, `getScope()`, `getLimit()`, `getRetryAfter()`). Both read the `Retry-After` header.
- **Web OTP API** (automatic fill-in on Chrome / Android): `webOtpDomain: 'app.example.com'` adds the last line
  `@app.example.com #123456` to the SMS; read it in the browser with
  `navigator.credentials.get({ otp: { transport: ['sms'] } })`. `stripAccents: true` keeps the message in GSM-7.
- **Test mode**: with a `lsk_test_` key no SMS is sent and `$otp->testCode()` returns the code, so you can test
  the whole flow. It is always `null` with a live key, and hidden from `var_dump()` and `json_encode()`.

**Idempotency.** `otp->send()` always carries an `Idempotency-Key` (a generated UUID v4, or yours), reused by
every retry: a network error, a 5xx or `IDEMPOTENCY_REQUEST_IN_PROGRESS` is retried without a second SMS, and the
replay returns the same `otpId` (`$otp->replayed === true`, and the same `testCode()` in test mode). Your own key
must identify **one** send intent (e.g. `"signup-{$attemptId}"`): reusing it for 24 hours returns the same code,
never a new one.

```php
$otp = $client->otp->send($phone, idempotencyKey: "signup-{$attemptId}", webOtpDomain: 'app.example.com');
$otp->replayed;        // true when the API replayed an earlier send (no new SMS, no new charge)
```

`otp->verify()` is never retried after a network error or a 5xx (an attempt may have been consumed): show an error
and let the user type the code again.

## Webhooks

Configure an endpoint in the client area (*Developers > Webhooks*) and copy its secret. Events are `POST`ed as
JSON and signed (`X-Signature: sha256=<HMAC-SHA256 of the raw body>`).

```php
use Labyrinthe\Connect\Exception\InvalidWebhookPayloadException;
use Labyrinthe\Connect\Exception\SignatureVerificationException;
use Labyrinthe\Connect\Webhook\Event\BalanceLow;
use Labyrinthe\Connect\Webhook\Event\CampaignCompleted;
use Labyrinthe\Connect\Webhook\Event\MessageStatusChanged;
use Labyrinthe\Connect\Webhook\Event\OtpFailed;
use Labyrinthe\Connect\Webhook\Event\OtpVerified;
use Labyrinthe\Connect\Webhook\Webhook;

$rawBody = file_get_contents('php://input');          // the RAW body: never re-encode a decoded array

try {
    $event = Webhook::constructEvent($rawBody, $_SERVER['HTTP_X_SIGNATURE'] ?? '', getenv('LABYRINTHE_WEBHOOK_SECRET'));
} catch (SignatureVerificationException | InvalidWebhookPayloadException) {
    http_response_code(400);
    exit;
}

if (already_processed($event->id())) {                 // deliveries can repeat: deduplicate on id
    http_response_code(200);
    exit;
}

match (true) {
    $event instanceof MessageStatusChanged => update_message($event->messageId, $event->status),
    $event instanceof CampaignCompleted => notify_team($event->name, $event->countFor(MessageStatus::Delivered)),
    $event instanceof BalanceLow => buy_credits($event->balance, $event->threshold),
    $event instanceof OtpVerified => mark_verified($event->otpId, $event->phone, $event->verifiedAt),
    $event instanceof OtpFailed => alert_security($event->otpId, $event->status, $event->reason),
    default => null,                                    // TestPing, or UnknownEvent for future types
};

http_response_code(200);                                // answer 2xx within 10 s; queue slow work
```

- The signature is checked in constant time, then the signed `timestamp` must be within 5 minutes (configurable:
  4th argument, `0` disables it) to block replays.
- Failed deliveries are retried up to 5 times (30 s, 2 min, 5 min, 15 min): the same `id` can arrive several
  times and the order is not guaranteed.
- Event types added later are returned as `UnknownEvent` (verified, never rejected): acknowledge them with a 2xx.
- `MessageStatusChanged` carries `reference` (given at send time), `errorCode` and `errorMessage` (on failure).
- `OtpVerified` / `OtpFailed` (`otp.verified`, `otp.failed`) are sent once per verification, never with the code:
  `otpId`, `phone`, `status` (`OtpWebhookStatus::Verified`, `Failed` or `Expired`), `reason` (`null`,
  `OtpFailureReason::MaxAttemptsReached` or `Expired`) and `verifiedAt`.
- `Webhook::sign($body, $secret)` builds a valid header to test your endpoint.

## Errors

Every exception implements `Labyrinthe\Connect\Exception\LabyrintheException`. API errors extend `ApiException`
and expose `getErrorCode()` (stable, for your code), `getMessage()` (French, for humans), `getStatusCode()`,
`getDetails()`, `getRequestId()` (the `X-Request-Id` header, to give to support) and `getApiVersion()`.

| Exception | When |
|---|---|
| `AuthenticationException` | 401 — missing, invalid or revoked key |
| `PermissionException` | 403 — IP not allowed for the key (`FORBIDDEN`), OTP disabled for the account (`OTP_DISABLED_FOR_ACCOUNT`) |
| `AccountSuspendedException` | 403 `ACCOUNT_SUSPENDED` — extends `PermissionException`; the whole API is closed until reactivation |
| `NotFoundException` | 404 — unknown message or `otp_id` |
| `ValidationException` | 422 `VALIDATION_ERROR` — `getErrors()` / `getFieldErrors('body')` |
| `RecipientNotSendableException` | 422 — `getRejected()`, `getReason()`; nothing was sent |
| `SenderIdNotApprovedException` | 422 — `sender` is not an approved Sender ID |
| `InvalidPhoneException` | 422 `INVALID_PHONE` (OTP) |
| `InsufficientCreditsException` | 409 — `getAvailable()`, `getRequired()`; nothing was sent |
| `IdempotencyKeyReusedException` | 409 — same key, different request |
| `IdempotencyRequestInProgressException` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` — already retried by the SDK; `getRetryAfter()` |
| `IdempotencyKeyUnresolvedException` | 409 `IDEMPOTENCY_KEY_UNRESOLVED` — outcome unknown (an SMS may have left); never retried: check, then send with a new key |
| `RateLimitException` | 429 `TOO_MANY_REQUESTS` — `getRetryAfter()` |
| `OtpResendTooSoonException` | 429 — extends `RateLimitException` |
| `OtpRateLimitedException` | 429 — extends `RateLimitException`, `getScope()`, `getLimit()` |
| `ServiceUnavailableException` | 5xx, including 503 `OTP_SERVICE_DISABLED` |
| `ApiException` | any other code (the list is open: new codes may appear) |
| `ConnectionException` | network error or timeout (`isTimeout()`) |
| `InvalidArgumentException` | invalid input detected by the SDK, before any call |

```php
use Labyrinthe\Connect\Exception\ApiException;
use Labyrinthe\Connect\Exception\InsufficientCreditsException;

try {
    $client->sms->send('0812345678', 'Hello');
} catch (InsufficientCreditsException $e) {
    alert_finance($e->getAvailable(), $e->getRequired());
} catch (ApiException $e) {
    log_error($e->getErrorCode(), $e->getStatusCode());
}
```

## Retries and timeouts

The SDK retries automatically (default: 2 retries, exponential backoff 0.5 s → 1 s → 2 s with jitter) **only when a
replay cannot cause a side effect twice**:

| Request | Network error / timeout | 5xx | 429 `TOO_MANY_REQUESTS` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` |
|---|---|---|---|---|
| `GET` (get, list, balance, sender IDs), `estimate()` | retried | retried | retried after `Retry-After` | — |
| `send()` / `sendMany()` (always with an `Idempotency-Key`) | retried, same key | retried, same key | retried after `Retry-After` | retried after `Retry-After`, same key |
| `otp->send()` (always with an `Idempotency-Key`, since 1.1.0) | retried, same key | retried, same key | retried after `Retry-After` | retried after `Retry-After`, same key |
| `otp->verify()` | **never** | **never** | retried (the request was refused before processing) | — |

Business errors (4xx, `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED`, `ACCOUNT_SUSPENDED`, `IDEMPOTENCY_KEY_UNRESOLVED`)
are never retried. When the
API asks to wait longer than `maxRetryWait` (default 10 s), the SDK throws the exception (`RateLimitException`,
`IdempotencyRequestInProgressException`) instead of blocking.

**Request id.** Every response carries `X-Request-Id`. Pass your own with `requestId:` on any call (1 to 128
characters among `A-Z a-z 0-9 . _ : -`, sent on every retry) to correlate your logs with the API's; read it on any
exception with `getRequestId()`, or on the last response with `$client->lastResponse()?->requestId()`
(`lastResponse()` also gives the status, `apiVersion()`, `isReplayed()`, `rateLimitRemaining()` and every header).

```php
try {
    $client->sms->send('0812345678', 'Hello', requestId: "order-1042");
} catch (ApiException $e) {
    log_error($e->getErrorCode(), $e->getRequestId()); // "order-1042"
}
```

## Configuration

```php
$client = new Client(
    apiKey: getenv('LABYRINTHE_API_KEY'),
    baseUrl: 'https://api.labyrinthe-rdc.com/api/public/v1', // default; "http://localhost:8000" is completed
    timeout: 30.0,                 // seconds (default Guzzle client)
    connectTimeout: 10.0,
    maxRetries: 2,                 // 0 disables retries
    maxRetryWait: 10.0,            // longest Retry-After the SDK waits for
    defaultCountryCode: '243',     // added to local numbers: 0812345678 → 243812345678
    defaultSender: 'MYSHOP',       // used when send() gets no sender
    httpClient: $psr18Client,      // any PSR-18 client (configure its timeouts yourself)
    requestFactory: $psr17Factory, // PSR-17, defaults to Guzzle's
    streamFactory: $psr17Factory,
    logger: $psr3Logger,           // debug/warning logs, numbers masked, no key, no code, no body
);

$client = Client::fromEnv();       // LABYRINTHE_API_KEY, optional LABYRINTHE_BASE_URL
```

Phone numbers: `Labyrinthe\Connect\PhoneNumber::normalize('0812345678')` returns `"243812345678"` (or `null`) with the
same rules as the API; `PhoneNumber::isValid()` and `PhoneNumber::mask()` are also available. International numbers
(`+33…`, `+1…`) are never blocked. Without `+`, 11 digits or more not starting with 0 are already international
(`33612345678` stays `33612345678`); a foreign number of 10 digits or less needs the `+`. Requests carry the number
in E.164 with `+` (`PhoneNumber::toE164('0812345678')` → `"+243812345678"`), so `+2481234567` keeps its country code.

Dates are parsed from the API format (`2026-11-06T09:39:11+00:00`; `Z` and microseconds are accepted too) and
`list()` sends `DateTimeInterface` filters in the same format.

Every response object keeps the decoded JSON in `raw()` for low-level access.

## Development

```bash
composer install
composer test          # unit + OpenAPI contract tests (no network)
composer analyse       # PHPStan, level max
composer lint          # PHP-CS-Fixer (dry run); `composer format` to fix

# integration tests against a local backend, with a TEST key only
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 composer test:integration
```

The API contract lives in [`openapi/openapi.yaml`](../openapi/openapi.yaml); the contract tests keep the SDK
aligned with it. Examples: [`examples/`](examples).

## License

MIT — see [LICENSE](LICENSE).
