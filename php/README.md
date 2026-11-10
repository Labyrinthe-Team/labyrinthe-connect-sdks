# Labyrinthe Connect — PHP SDK

[![Packagist](https://img.shields.io/packagist/v/labyrinthe/connect-php.svg)](https://packagist.org/packages/labyrinthe/connect-php)
[![CI](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/php.yml/badge.svg)](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/php.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.1-777bb4)

Official PHP client for [Labyrinthe Connect](https://connect.labyrinthe-rdc.com), the SMS and OTP platform for the DR Congo
and Central Africa: send SMS, check your balance and Sender IDs, verify phone numbers with one-time codes (OTP) and
receive signed webhooks. Typed responses and exceptions, automatic retries **only** when they cannot send an SMS
twice, Guzzle out of the box or any PSR-18 client, and your API key, OTP codes and full phone numbers never reach
logs or dumps. SDK 1.2.1, built against the API specification 1.3.1.

*Version française : [README.fr.md](README.fr.md). Laravel? Use [`labyrinthe/connect-laravel`](../laravel).
WordPress? Use the [plugin](../wordpress).*

[Requirements](#requirements) · [Installation](#installation) · [Quick start](#quick-start) ·
[Configuration](#configuration) · [Sending SMS](#sending-sms) · [Balance and Sender IDs](#balance-and-sender-ids) ·
[OTP](#otp-verify-a-phone-number) · [Webhooks](#webhooks) · [Errors](#errors) ·
[Retries and idempotency](#retries-and-idempotency) · [Test mode, logging and security](#test-mode-logging-and-security) ·
[Without the SDK](#without-the-sdk-raw-http) · [Upgrading](#upgrading)

## Requirements

| | |
|---|---|
| PHP | 8.1 or newer, with the `json` and `mbstring` extensions |
| HTTP | `guzzlehttp/guzzle` ^7.5 (installed and used by default), or any PSR-18 client with PSR-17 factories |
| Logging | optional, any PSR-3 logger (`psr/log` ^2 or ^3) |
| API | Labyrinthe Connect public API v1 (specification 1.3.1) |

## Installation

```bash
composer require labyrinthe/connect-php
```

Create an API key in the client area (*Developers > API keys*). **Keep it server-side**: never in a browser, a
mobile app or a Git repository.

## Quick start

```php
use Labyrinthe\Connect\Client;

$client = new Client('lsk_test_…');                    // test key: nothing is sent, nothing is billed
$message = $client->sms->send(to: '0812345678', body: 'Your order 1042 is ready.');
echo $message->status->value;                           // "queued"
```

## Configuration

```php
$client = new Client(
    apiKey: getenv('LABYRINTHE_API_KEY'),
    baseUrl: null,                 // default https://api.connect.labyrinthe-rdc.com/api/public/v1
    timeout: 30.0,
    connectTimeout: 10.0,
    maxRetries: 2,
    maxRetryWait: 10.0,
    defaultCountryCode: '243',
    defaultSender: 'MYSHOP',
    httpClient: $psr18Client,
    requestFactory: $psr17Factory,
    streamFactory: $psr17Factory,
    logger: $psr3Logger,
);

$client = Client::fromEnv();       // LABYRINTHE_API_KEY, and LABYRINTHE_BASE_URL when set
```

| Option | Default | Meaning |
|---|---|---|
| `apiKey` | — (required) | `lsk_test_…` (sandbox) or `lsk_live_…` (production) |
| `baseUrl` | `https://api.connect.labyrinthe-rdc.com/api/public/v1` | API root; a bare host such as `http://localhost:8000` is completed with `/api/public/v1` |
| `timeout` / `connectTimeout` | `30.0` / `10.0` s | timeouts of the default Guzzle client (set them yourself on your own PSR-18 client) |
| `maxRetries` | `2` | automatic retries when they are safe (0 to 10; `0` disables them) |
| `maxRetryWait` | `10.0` s | longest `Retry-After` (or backoff) the SDK waits for; beyond it, the exception is thrown |
| `defaultCountryCode` | `243` | added to local numbers: `0812345678` → `243812345678` |
| `defaultSender` | `null` | Sender ID used when `send()` / `sendMany()` get none (else the account default) |
| `httpClient`, `requestFactory`, `streamFactory` | Guzzle | any PSR-18 client and PSR-17 factories |
| `logger` | none | PSR-3 logger (see [logging](#test-mode-logging-and-security)) |
| `userAgentSuffix` | `null` | appended to `User-Agent: labyrinthe-php/1.2.1 PHP/x.y` (for integrations) |

| Environment variable | Read by | |
|---|---|---|
| `LABYRINTHE_API_KEY` | `Client::fromEnv()` | required |
| `LABYRINTHE_BASE_URL` | `Client::fromEnv()` | optional, e.g. `http://localhost:8000` for a local backend |

`Client::fromEnv()` also accepts `timeout`, `maxRetries`, `defaultCountryCode`, `defaultSender`, `httpClient` and
`logger`.

**Phone numbers.** `+243…`, `00243…`, `243…`, `0812…` and `812…` are all accepted; spaces, dashes and parentheses
are ignored. International numbers (`+33…`, `+1…`) are never blocked. Without `+`, 11 digits or more not starting
with 0 are already international (`33612345678`); a foreign number of 10 digits or fewer needs its `+`. Helpers with
the same rules as the API: `PhoneNumber::normalize('0812345678')` → `"243812345678"` (or `null`),
`PhoneNumber::toE164()` → `"+243812345678"` (the format sent to the API), `PhoneNumber::isValid()`,
`PhoneNumber::mask()`.

**Dates** are parsed from the API format (`2026-11-06T09:39:11+00:00`) into `DateTimeImmutable`. Every response
object keeps the decoded JSON in `raw()`.

## Sending SMS

### One recipient

```php
$message = $client->sms->send(
    to: '+243 81 234 5678',
    body: 'Your order 1042 is ready.',
    sender: 'MYSHOP',              // optional: an approved Sender ID
    reference: 'order-1042',       // optional: your reference, max 64 characters, filterable
    stripAccents: false,           // optional: accents → ASCII (see below)
    idempotencyKey: null,          // optional: generated when omitted (see Retries and idempotency)
);

$message->id;               // "9d5c0a3e-…"
$message->recipient;        // "243812345678"
$message->status;           // MessageStatus::Queued
$message->segments;         // 1
$message->creditsCharged;   // 1
```

Sending is asynchronous: `202 Accepted` means *queued and paid for*, not *delivered*. Follow the status with the
`message.status_changed` webhook (recommended) or by polling `get()`. A body holds 1 to 1600 characters.

### Several recipients: all or nothing

```php
use Labyrinthe\Connect\Exception\RecipientNotSendableException;

try {
    $batch = $client->sms->sendMany(['0812345678', '0899999999'], 'Meeting tomorrow at 9am.');
    count($batch);          // one Message per distinct number (duplicates merged after normalisation)
    $batch->first();        // ?Message
} catch (RecipientNotSendableException $e) {
    // NOTHING was sent: fix the list and try again.
    foreach ($e->getRejected() as $rejected) {
        echo $rejected->recipient.': '.$rejected->reason->value; // invalid_phone, blacklisted, opted_out
    }
}
```

Up to 1000 recipients per call. Every recipient, the Sender ID and the balance are checked **before** anything is
sent: one refused number or an insufficient balance means no SMS at all. Numbers the SDK can already tell are
invalid are refused locally (`InvalidArgumentException`), before any network call.

### Cost before sending, `stripAccents`

1 credit = 1 SMS segment. A pure ASCII message (GSM-7) holds 160 characters (153 per segment beyond). **Any
non-ASCII character** (é, à, ç, emoji, curly quotes…) switches to UCS-2: 70 characters (67 per segment beyond), often
doubling the cost.

```php
$estimate = $client->sms->estimate('Réunion demain à 9h', ['0812345678', '0899999999']);
$estimate->encoding;           // Encoding::Ucs2
$estimate->characters;         // 19
$estimate->segmentsPerMessage; // 1
$estimate->recipientsCount;    // 2 (normalised, duplicates merged, invalid numbers ignored)
$estimate->totalCredits;       // 2
$estimate->balanceAfter;       // ?int
$estimate->isAffordable();     // does the balance cover it?

$client->sms->estimate('Réunion demain à 9h', '0812345678', stripAccents: true)->encoding; // Encoding::Gsm7
```

`estimate()` sends nothing and is free. `stripAccents: true` (on `send()`, `sendMany()`, `estimate()` and
`otp->send()`) asks the API to replace accents and typographic characters by ASCII. It is never applied unless you
ask, and the transliteration depends on the server (`Café` may become `Cafe`), so do not rely on it for an exact
text.

### Get one message, list messages

```php
use Labyrinthe\Connect\Enum\MessageStatus;

$message = $client->sms->get($message->id);
if ($message->status->isFinal()) {
    echo $message->status->isSuccessful() ? 'Delivered' : 'Failed: '.$message->errorCode;
}

$page = $client->sms->list(
    status: MessageStatus::Failed,  // or 'failed'
    recipient: '0812345678',        // any format
    reference: 'order-1042',        // exact match
    from: '2026-11-01',             // a date covers the whole day; DateTimeInterface accepted
    to: new DateTimeImmutable(),
    page: 1,
    perPage: 50,                    // 1 to 100, default 15
);

$page->total; $page->currentPage; $page->lastPage; $page->hasMore();
$next = $page->nextPage();          // ?Page

foreach ($page->autoPaging() as $message) {   // this page and every following one, fetched lazily
    // …
}
```

`get()` finds any message of the account by `id`. `list()` only returns messages sent **with the calling API key**,
newest first. Statuses (`MessageStatus`): `queued`, `submitted`, `sent`, `delivered`, `failed`, `rejected`,
`expired`, `undeliverable`, and `unknown` for a value added later.

## Balance and Sender IDs

```php
$balance = $client->balance->get();
$balance->balance;                        // available credits
$balance->held;                           // credits held by campaigns in progress

foreach ($client->senderIds->list() as $senderId) {
    echo $senderId->value;                // approved name, usable as `sender`
    $senderId->isDefault;                 // the account's default Sender ID?
}
```

Only approved Sender IDs are listed. Request new ones in the client area; a `sender` that is not approved is refused
with `SenderIdNotApprovedException`.

## OTP: verify a phone number

Labyrinthe Connect generates the code, sends it by SMS (billed like an SMS) and checks it (free). The code is never
returned by the API in production and never logged by the SDK.

```php
use Labyrinthe\Connect\Enum\OtpFailureReason;

// 1. Send the code. Keep otp_id SERVER-SIDE (session, cache, database), never in a form field.
$otp = $client->otp->send('0812345678');
$_SESSION['otp_id'] = $otp->otpId;
$otp->expiresAt;           // DateTimeImmutable
$otp->maxAttempts;         // 5
$otp->codeLength;          // 6: size of the input
$otp->codeType;            // OtpCodeType::Numeric (numeric keypad) or OtpCodeType::Alphanumeric
$otp->resendAfterSeconds;  // countdown before "Resend the code" (also resendAvailableAt())
$otp->testCode();          // the code with a lsk_test_ key, always null in production

// 2. The user types the code received by SMS.

// 3. Verify it.
$result = $client->otp->verify($_SESSION['otp_id'], $_POST['code']);
if ($result->isValid()) {
    unset($_SESSION['otp_id']);
    // log the user in / mark the number as verified
} else {
    $error = match ($result->reason) {
        OtpFailureReason::InvalidCode => "Wrong code, {$result->attemptsRemaining} attempt(s) left.",
        OtpFailureReason::Expired, OtpFailureReason::AlreadyUsed,
        OtpFailureReason::MaxAttemptsReached => 'Please request a new code.',
        default => 'Could not verify the code.',
    };
}
```

| `otp->send()` parameter | |
|---|---|
| `phone` | any accepted format |
| `template` | your message, must contain `{code}`; optional `{app_name}`, `{minutes}` (default: the account's OTP template) |
| `locale` | `'fr'` or `'en'`, language of the standard template |
| `webOtpDomain` | host name such as `'app.example.com'`: adds the last line `@app.example.com #123456` so Chrome / Android can fill the code in (`navigator.credentials.get({ otp: { transport: ['sms'] } })`) |
| `stripAccents` | keep the message in GSM-7 |
| `idempotencyKey`, `requestId` | see [Retries and idempotency](#retries-and-idempotency) |

- A wrong code is **not** an exception: `verify()` returns `verified: false` with a `reason` (`invalid_code`,
  `expired`, `already_used`, `max_attempts_reached`); `$result->reason?->canRetry()` tells whether the user may type
  again.
- `attemptsRemaining`: attempts left after `invalid_code`, `0` with `max_attempts_reached`, `null` otherwise.
- Code length, alphabet, validity, attempts, resend delay, Sender ID and default template come from the account's
  OTP settings (client area > *OTP verification*); `codeLength`, `codeType` and `resendAfterSeconds` report them.
- A new `send()` to the same number invalidates the previous code. A resend is accepted at once after a successful
  verification.
- **Limits**: 10 sends / minute per API key (`RateLimitException`); a minimum delay between two sends to the same
  number (`OtpResendTooSoonException`, `getRetryAfter()`); an hourly cap per number and per account
  (`OtpRateLimitedException`, `getScope()` = `OtpRateLimitScope::Phone` or `Account`, `getLimit()`,
  `getRetryAfter()`).
- **Test mode**: with a `lsk_test_` key no SMS is sent and `$otp->testCode()` returns the code, so you can test the
  whole flow. It is hidden from `var_dump()` and `json_encode()`.

A complete login flow (two endpoints, PHP session) is in [`examples/otp-login.php`](examples/otp-login.php).

## Webhooks

Add an endpoint in the client area (*Developers > Webhooks*) and copy its secret. Events are `POST`ed as JSON
`{ id, event, data, timestamp }`, signed with `X-Signature: sha256=<HMAC-SHA256 of the raw body>`.

```php
use Labyrinthe\Connect\Enum\MessageStatus;
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

| Event | Class | Main properties |
|---|---|---|
| `message.status_changed` | `MessageStatusChanged` | `messageId`, `recipient`, `status`, `providerMessageId`, `campaignId`, `reference`, `errorCode`, `errorMessage` |
| `campaign.completed` | `CampaignCompleted` | `campaignId`, `name`, `recipientsCount`, `messagesByStatus`, `countFor()`, `creditsCharged`, `completedAt` |
| `balance.low` | `BalanceLow` | `balance`, `threshold` |
| `otp.verified` | `OtpVerified` | `otpId`, `phone`, `status` (`OtpWebhookStatus::Verified`), `verifiedAt` |
| `otp.failed` | `OtpFailed` | `otpId`, `phone`, `status` (`Failed` or `Expired`), `reason` (`MaxAttemptsReached`, `Expired` or `null`) |
| `test.ping` | `TestPing` | — |
| a type added later | `UnknownEvent` | `data()`; verified, never rejected: acknowledge it with a 2xx |

Every event has `id()`, `type()`, `eventType()` (`?WebhookEvent`), `timestamp()` and `raw()`.

- The signature is checked in constant time on the raw body, then the signed `timestamp` must be within 5 minutes
  (4th argument `toleranceSeconds`; `0` disables the check) to block replays. `Webhook::verifySignature()` only
  checks the signature; `Webhook::sign($body, $secret)` builds a valid header to test your endpoint.
- Failed deliveries are retried up to 5 times: the same `id` can arrive several times and the order is not
  guaranteed. Store the processed ids (24 h is enough) and answer 2xx to duplicates.
- OTP events never carry the code.

Framework examples: plain PHP in [`examples/webhook-receiver.php`](examples/webhook-receiver.php); Laravel gets a
ready-made route and events with [`labyrinthe/connect-laravel`](../laravel). In Symfony, pass
`$request->getContent()` (raw body) and `$request->headers->get('X-Signature', '')` to `Webhook::constructEvent()`.

## Errors

Every exception implements `Labyrinthe\Connect\Exception\LabyrintheException`. API errors extend `ApiException`:
`getErrorCode()` (stable, for your code), `getMessage()` (French, for humans), `getStatusCode()`, `getDetails()`,
`getRequestId()` (the `X-Request-Id`, for support), `getApiVersion()` and `getHeader()`.

| Exception | HTTP | Code | What to do |
|---|---|---|---|
| `AuthenticationException` | 401 | `UNAUTHENTICATED` | check the key (missing, invalid or revoked) |
| `PermissionException` | 403 | `FORBIDDEN`, `OTP_DISABLED_FOR_ACCOUNT` | IP not allowed for this key, or OTP disabled for the account |
| `AccountSuspendedException` | 403 | `ACCOUNT_SUSPENDED` | the whole API is closed until reactivation: contact support (extends `PermissionException`) |
| `NotFoundException` | 404 | `NOT_FOUND` | unknown message id or `otp_id` |
| `ValidationException` | 422 | `VALIDATION_ERROR` | fix the input: `getErrors()`, `getFieldErrors('body')` |
| `RecipientNotSendableException` | 422 | `RECIPIENT_NOT_SENDABLE` | nothing was sent: `getRejected()`, `getReason()` |
| `SenderIdNotApprovedException` | 422 | `SENDER_ID_NOT_APPROVED` | use an approved Sender ID (`senderIds->list()`) |
| `InvalidPhoneException` | 422 | `INVALID_PHONE` | OTP: ask for another number |
| `InsufficientCreditsException` | 409 | `INSUFFICIENT_CREDITS` | nothing was sent: buy credits; `getAvailable()`, `getRequired()` (never in test mode) |
| `IdempotencyKeyReusedException` | 409 | `IDEMPOTENCY_KEY_REUSED` | same key, different content: use a new key |
| `IdempotencyRequestInProgressException` | 409 | `IDEMPOTENCY_REQUEST_IN_PROGRESS` | already retried by the SDK; retry later with the same key (`getRetryAfter()`) |
| `IdempotencyKeyUnresolvedException` | 409 | `IDEMPOTENCY_KEY_UNRESOLVED` | outcome unknown, an SMS may have left: check (`sms->list(reference: …)`), then send with a **new** key |
| `RateLimitException` | 429 | `TOO_MANY_REQUESTS` | slow down; `getRetryAfter()` |
| `OtpResendTooSoonException` | 429 | `OTP_RESEND_TOO_SOON` | show a countdown of `getRetryAfter()` seconds (extends `RateLimitException`) |
| `OtpRateLimitedException` | 429 | `OTP_RATE_LIMITED` | hourly cap: `getScope()`, `getLimit()`, `getRetryAfter()` (extends `RateLimitException`) |
| `FeatureDisabledException` | 503 | `FEATURE_DISABLED`, `OTP_SERVICE_DISABLED` | maintenance, nothing was done: [see below](#maintenance-503-feature_disabled) |
| `ServiceUnavailableException` | 5xx | any | temporary server error (already retried when safe) |
| `ApiException` | any | any other code (e.g. `INVALID_OTP_TEMPLATE`, `HTTP_ERROR`) | the list is open: handle unknown codes generically |
| `ConnectionException` | — | `CONNECTION_ERROR`, `TIMEOUT` | network error or timeout (`isTimeout()`) |
| `InvalidArgumentException` | — | — | invalid input detected by the SDK, before any call |
| `SignatureVerificationException`, `InvalidWebhookPayloadException` | — | — | webhook refused: answer 400 |

```php
use Labyrinthe\Connect\Exception\ApiException;
use Labyrinthe\Connect\Exception\ConnectionException;
use Labyrinthe\Connect\Exception\InsufficientCreditsException;

try {
    $client->sms->send('0812345678', 'Hello');
} catch (InsufficientCreditsException $e) {
    alert_finance($e->getAvailable(), $e->getRequired());
} catch (ApiException $e) {
    log_error($e->getErrorCode(), $e->getStatusCode(), $e->getRequestId());
} catch (ConnectionException $e) {
    log_error($e->getErrorCode(), $e->isTimeout());
}
```

### Maintenance (503 `FEATURE_DISABLED`)

A platform administrator can temporarily shut down the whole API (`api`) or one feature (`api.sms_send`,
`api.otp_send`, `api.otp_verify`; `otp` for the whole OTP service, code `OTP_SERVICE_DISABLED`). **Nothing was done**:
no SMS sent, no credit debited. `FeatureDisabledException` extends `ServiceUnavailableException`, so existing
`catch` blocks keep working.

| Accessor | |
|---|---|
| `getFeature()` | feature requested by the call (`api.sms_send`…), or `null` |
| `getBlockedBy()` | feature actually shut down (itself or a parent, e.g. `api`), or `null` |
| `getAdminMessage()` | the administrator's message (French), or `null`; `getMessage()` repeats it or gives a generic text |
| `getUntil()` | scheduled re-enable (`DateTimeImmutable`), or `null` when unknown |
| `getRetryAfter()` | seconds until that re-enable (`Retry-After`), or `null` |

The SDK retries it only when the API sends a `Retry-After` no longer than `maxRetryWait`, after exactly that delay.
Otherwise, do not retry in a loop: queue the work or tell the user.

```php
use Labyrinthe\Connect\Exception\FeatureDisabledException;

try {
    $client->sms->send('0812345678', 'Your order has shipped');
} catch (FeatureDisabledException $e) {
    $delay = $e->getRetryAfter() ?? 900;          // unknown return time: try again in 15 minutes
    $queue->later($delay, new SendSms('0812345678', 'Your order has shipped'));
    show_notice($e->getAdminMessage() ?? 'SMS sending is temporarily unavailable.');
}
```

## Retries and idempotency

**Never a double SMS.** `send()`, `sendMany()` and `otp->send()` always carry an `Idempotency-Key`. When you give
none, the SDK generates a UUID v4 and reuses it for its own retries. A replay within 24 hours returns the original
response without sending or billing again (`$batch->replayed`, `$otp->replayed`; for OTP, the same `otpId` and the
same `testCode()`).

To also protect against **your** retries (queue jobs, double clicks, crashes), pass a key derived from your business
event: `idempotencyKey: 'order-1042-ready'` (255 characters max). One key = one send intent: the same key with a
different content is refused (`IdempotencyKeyReusedException`); recipients are compared after normalisation
(`0812345678` then `243812345678` is a replay). For OTP, reusing a key for 24 hours returns the same code, never a
new one.

Automatic retries (default 2, exponential backoff 0.5 s → 1 s → 2 s with jitter) happen **only when a replay cannot
cause a side effect twice**:

| Request | Network error / timeout | 5xx | 429 `TOO_MANY_REQUESTS` | 409 `IDEMPOTENCY_REQUEST_IN_PROGRESS` |
|---|---|---|---|---|
| `get()`, `list()`, `balance`, `senderIds`, `estimate()` | retried | retried | after `Retry-After` | — |
| `send()` / `sendMany()` | retried, same key | retried, same key | after `Retry-After` | after `Retry-After`, same key |
| `otp->send()` | retried, same key | retried, same key | after `Retry-After` | after `Retry-After`, same key |
| `otp->verify()` | **never** | **never** | after `Retry-After` | — |

- Business errors (other 4xx, `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED`, `ACCOUNT_SUSPENDED`,
  `IDEMPOTENCY_KEY_UNRESOLVED`) are never retried.
- 503 `FEATURE_DISABLED` / `OTP_SERVICE_DISABLED` are not part of the 5xx column: on every operation,
  `otp->verify()` included, they are retried only after a `Retry-After`, never with backoff.
- When the API asks to wait longer than `maxRetryWait` (10 s by default), the SDK throws instead of blocking.
- `otp->verify()` is never retried after a network error or a 5xx (an attempt may have been consumed): show an
  error and let the user type the code again.

## Test mode, logging and security

| Key | Mode | Effect |
|---|---|---|
| `lsk_test_…` | Sandbox | Nothing is sent, no credit is used, same responses as production. The balance is not checked (never `INSUFFICIENT_CREDITS`) and `otp->send()` returns the code (`testCode()`). |
| `lsk_live_…` | Production | SMS are really sent and credits are used. |

`$client->isTestMode()` and `$client->isLiveMode()` tell you which one you use.

**Logging.** Pass a PSR-3 `logger` to get debug logs of requests and responses, warnings on retries and info logs on
API errors, all with the `request_id`. The API key, OTP codes and message bodies are never logged; phone numbers are
masked. `var_dump($client)` shows a masked key, `serialize($client)` is refused, and `testCode()` is hidden from
dumps and `json_encode()`.

**Request ids.** Every response carries `X-Request-Id`. Pass your own with `requestId:` on any call (1 to 128
characters among `A-Z a-z 0-9 . _ : -`, sent on every retry) to correlate your logs with the API's. Read it on any
exception with `getRequestId()`, or on the last response with `$client->lastResponse()?->requestId()`
(`lastResponse()` also gives the status, `apiVersion()`, `isReplayed()`, `rateLimitLimit()`, `rateLimitRemaining()`
and every header). Give it to support: connect@labyrinthe-rdc.com.

```php
try {
    $client->sms->send('0812345678', 'Hello', requestId: 'order-1042');
} catch (ApiException $e) {
    log_error($e->getErrorCode(), $e->getRequestId()); // "order-1042"
}
```

## Without the SDK (raw HTTP)

The SDK is a thin layer over a REST JSON API. The same send with cURL:

```bash
curl https://api.connect.labyrinthe-rdc.com/api/public/v1/sms \
  -H "Authorization: Bearer lsk_test_…" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 6f1c2d0e-8a4b-4c3d-9e2f-1a2b3c4d5e6f" \
  -d '{"recipient": "+243812345678", "body": "Your order 1042 is ready."}'
```

The answer is `202` with `{ "data": [ { "id": "…", "status": "queued", … } ] }`; errors are
`{ "error": { "code", "message", "details" } }`. Every route, field and error is described in the OpenAPI
specification [`openapi/openapi.yaml`](../openapi/openapi.yaml).

## Upgrading

See [CHANGELOG.md](CHANGELOG.md). 1.x releases are backward compatible. From 1.1 to 1.2: maintenance errors are now
`FeatureDisabledException` (a subclass of `ServiceUnavailableException`, so existing `catch` blocks still work) and
are no longer retried with backoff. From 1.2.0 to 1.2.1: no code change; the default API URL is now
`https://api.connect.labyrinthe-rdc.com/api/public/v1` (the 1.2.0 default did not answer).

## Contributing

Issues and pull requests go to the monorepo
[Labyrinthe-Team/labyrinthe-connect-sdks](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks) (the
`labyrinthe-connect-php` repository is a read-only mirror). See [CONTRIBUTING.md](../CONTRIBUTING.md).

```bash
composer install
composer check         # PHP-CS-Fixer (dry run) + PHPStan level max + unit and OpenAPI contract tests
composer format        # fix the code style

# integration tests against a local backend, with a TEST key only
LABYRINTHE_API_KEY=lsk_test_… LABYRINTHE_BASE_URL=http://localhost:8000 composer test:integration
```

Examples: [`examples/send-sms.php`](examples/send-sms.php), [`examples/otp-login.php`](examples/otp-login.php),
[`examples/webhook-receiver.php`](examples/webhook-receiver.php).

## License

MIT — see [LICENSE](LICENSE). Security issues: please email connect@labyrinthe-rdc.com rather than opening a public
issue.
