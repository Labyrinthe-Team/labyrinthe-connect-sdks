# Labyrinthe Connect for Laravel

[![Packagist](https://img.shields.io/packagist/v/labyrinthe/connect-laravel.svg)](https://packagist.org/packages/labyrinthe/connect-laravel)
[![CI](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/laravel.yml/badge.svg)](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks/actions/workflows/laravel.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
![Laravel](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012%20%7C%2013-ff2d20)

Send SMS and verify phone numbers with [Labyrinthe Connect](https://connect.labyrinthe-rdc.com), the SMS and OTP platform for
the DR Congo and Central Africa, the Laravel way: a `Labyrinthe` facade, a `labyrinthe` notification channel, an OTP
helper with validation rules, signed webhooks turned into Laravel events, and `Labyrinthe::fake()` for your tests.
Package 1.2.1, built on the [PHP SDK](../php) 1.2.1 (API specification 1.3.0): same typed exceptions, same safe
retries, never a double SMS.

*Version française : [README.fr.md](README.fr.md).*

[Requirements](#requirements) · [Installation](#installation) · [Quick start](#quick-start) ·
[Configuration](#configuration) · [Sending SMS](#sending-sms) · [Balance and Sender IDs](#balance-and-sender-ids) ·
[OTP](#otp-phone-verification-and-login) · [Webhooks](#webhooks) · [Errors](#errors) ·
[Retries, idempotency and queues](#retries-idempotency-and-queues) ·
[Test mode, logging and security](#test-mode-logging-and-security) · [Testing your application](#testing-your-application) ·
[Without the package](#without-the-package-raw-http) · [Upgrading](#upgrading)

## Requirements

| | |
|---|---|
| PHP | 8.1 or newer (and what your Laravel version requires) |
| Laravel | 10, 11, 12 or 13 |
| SDK | `labyrinthe/connect-php` ^1.2.1 (installed automatically) |

## Installation

```bash
composer require labyrinthe/connect-laravel
```

The service provider and the `Labyrinthe` facade are auto-discovered. Add your key to `.env` (create it in the client
area, *Developers > API keys*; keep it server-side):

```dotenv
LABYRINTHE_API_KEY=lsk_test_…     # test key: nothing is sent, no credit is used
```

Optionally publish the configuration (`config/labyrinthe.php`) and the validation messages
(`lang/vendor/labyrinthe`):

```bash
php artisan vendor:publish --tag=labyrinthe-config
php artisan vendor:publish --tag=labyrinthe-lang
```

## Quick start

```php
use Labyrinthe\Connect\Laravel\Facades\Labyrinthe;

$message = Labyrinthe::send('0812345678', 'Your order 1042 is ready.');
echo $message->status->value;                           // "queued" (nothing sent with a lsk_test_ key)
```

## Configuration

Every option has an environment variable; the client is built lazily, so a missing key only fails when the API is
used.

| Variable | Config key | Default | |
|---|---|---|---|
| `LABYRINTHE_API_KEY` | `api_key` | — | `lsk_test_…` (sandbox) or `lsk_live_…` |
| `LABYRINTHE_BASE_URL` | `base_url` | `https://api.connect.labyrinthe-rdc.com/api/public/v1` | `http://localhost:8000` is completed with `/api/public/v1` |
| `LABYRINTHE_TIMEOUT` | `timeout` | `30` | seconds |
| `LABYRINTHE_CONNECT_TIMEOUT` | `connect_timeout` | `10` | seconds |
| `LABYRINTHE_MAX_RETRIES` | `max_retries` | `2` | safe retries only (never a second SMS or OTP) |
| `LABYRINTHE_DEFAULT_COUNTRY_CODE` | `default_country_code` | `243` | added to local numbers (`0812345678` → `243812345678`) |
| `LABYRINTHE_SENDER` | `sender` | — | approved Sender ID used by default (else the account default) |
| `LABYRINTHE_OTP_TEMPLATE` | `otp.template` | — | OTP message, must contain `{code}` (else the account's OTP settings) |
| `LABYRINTHE_OTP_LOCALE` | `otp.locale` | — | `fr` or `en` |
| `LABYRINTHE_OTP_WEB_OTP_DOMAIN` | `otp.web_otp_domain` | — | host name for the Web OTP API (e.g. `app.example.com`) |
| `LABYRINTHE_OTP_STRIP_ACCENTS` | `otp.strip_accents` | `false` | OTP message in GSM-7 |
| `LABYRINTHE_WEBHOOK_SECRET` | `webhook.secret` | — | endpoint secret (client area > Developers > Webhooks) |
| `LABYRINTHE_WEBHOOK_PATH` | `webhook.path` | `webhooks/labyrinthe` | URI used by `Route::labyrintheWebhooks()` |
| `LABYRINTHE_WEBHOOK_TOLERANCE` | `webhook.tolerance` | `300` | max age of a delivery, in seconds (replay protection) |
| `LABYRINTHE_WEBHOOK_DEDUPLICATE` | `webhook.deduplicate` | `true` | ignore already-processed event ids |
| `LABYRINTHE_WEBHOOK_CACHE_STORE` | `webhook.cache_store` | default store | cache store used for deduplication |
| `LABYRINTHE_WEBHOOK_CACHE_TTL` | `webhook.cache_ttl` | `86400` | how long processed ids are kept, in seconds |
| `LABYRINTHE_LOG_CHANNEL` | `log_channel` | — | log channel for SDK logs (none when empty) |

The SDK waits at most 10 seconds for a `Retry-After` (`maxRetryWait`). Every option of the PHP SDK is described in
its [README](../php/README.md#configuration). You can inject `Labyrinthe\Connect\Client` (singleton) or
`Labyrinthe\Connect\Laravel\LabyrintheManager` instead of using the facade.

## Sending SMS

### With the facade

```php
use Labyrinthe\Connect\Laravel\Facades\Labyrinthe;

// Shortcut: a string → Message, an array → MessageBatch (all or nothing)
Labyrinthe::send('0812345678', 'Your order 1042 is ready.', sender: 'MYSHOP', reference: 'order-1042');
Labyrinthe::send(['0812345678', '0899999999'], 'Meeting tomorrow at 9am.');

// The full SDK service: sender, reference, stripAccents, idempotencyKey, requestId
$message = Labyrinthe::sms()->send(to: '0812345678', body: 'Réunion à 9h', stripAccents: true);
$batch = Labyrinthe::sms()->sendMany(['0812345678', '0899999999'], 'Meeting tomorrow at 9am.');

$estimate = Labyrinthe::sms()->estimate('Réunion à 9h', ['0812345678']);   // encoding, segments, credits: free
$message = Labyrinthe::sms()->get($message->id);
$page = Labyrinthe::sms()->list(status: 'failed', reference: 'order-1042', perPage: 50);
foreach ($page->autoPaging() as $message) { /* … */ }
```

`sendMany()` checks every recipient, the Sender ID and the balance **before** sending: one refused number
(`RecipientNotSendableException`, with `getRejected()`) means no SMS at all. Up to 1000 recipients, duplicates merged,
body of 1 to 1600 characters. Any non-ASCII character switches to UCS-2 (70 characters per SMS instead of 160):
`stripAccents: true` keeps the text in GSM-7. Sending is asynchronous: follow delivery with the
`MessageStatusChanged` event.

### Notification channel

```php
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Labyrinthe\Connect\Laravel\Notifications\LabyrintheMessage;

class OrderShipped extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Order $order) {}

    public function via(object $notifiable): array
    {
        return ['labyrinthe'];
    }

    public function toLabyrinthe(object $notifiable): LabyrintheMessage
    {
        return LabyrintheMessage::create("Your order {$this->order->number} has shipped.")
            ->reference("order-{$this->order->id}")    // optional, max 64 characters
            ->sender('MYSHOP')                          // optional, else LABYRINTHE_SENDER
            ->stripAccents();                           // optional: accents → ASCII, GSM-7
    }
}
```

| `LabyrintheMessage` | |
|---|---|
| `create($content)` / `content($text)` | the SMS text |
| `sender(?string)` | an approved Sender ID |
| `reference(?string)` | your reference, filterable in the API and returned in webhooks |
| `stripAccents(bool = true)` | accents replaced by ASCII by the API |
| `idempotencyKey(?string)` | overrides the key derived from the notification id |
| `to(string\|array)` | overrides the notifiable's route (several numbers: one all-or-nothing send) |

`toLabyrinthe()` may also return a plain string. The number comes from `routeNotificationForLabyrinthe()` on the
notifiable, otherwise from its `phone` attribute; nothing is sent when there is none.

```php
class User extends Authenticatable
{
    use Notifiable;

    public function routeNotificationForLabyrinthe(Notification $notification): string|array|null
    {
        return $this->mobile_number;          // any format: +243…, 0812…, 243…
    }
}

$user->notify(new OrderShipped($order));
Notification::route('labyrinthe', '0812345678')->notify(new OrderShipped($order));   // on demand
```

## Balance and Sender IDs

```php
Labyrinthe::balance()->get()->balance;     // available credits (->held: held by campaigns in progress)

foreach (Labyrinthe::senderIds()->list() as $senderId) {
    $senderId->value;                      // approved name, usable as sender
    $senderId->isDefault;
}

Labyrinthe::isTestMode();                  // true with a lsk_test_ key
```

## OTP: phone verification and login

`Labyrinthe::otp()` sends and verifies codes with the `labyrinthe.otp` defaults; the rules `PhoneNumber` and
`ValidOtp` plug it into Laravel validation.

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Labyrinthe\Connect\Exception\FeatureDisabledException;
use Labyrinthe\Connect\Exception\OtpRateLimitedException;
use Labyrinthe\Connect\Exception\OtpResendTooSoonException;
use Labyrinthe\Connect\Laravel\Facades\Labyrinthe;
use Labyrinthe\Connect\Laravel\Rules\PhoneNumber;
use Labyrinthe\Connect\Laravel\Rules\ValidOtp;

class PhoneLoginController
{
    // 1. Send the code and keep otp_id in the SESSION (server-side), never in the form.
    public function send(Request $request)
    {
        $request->validate(['phone' => ['required', new PhoneNumber()]]);

        try {
            $otp = Labyrinthe::otp()->send($request->input('phone'));
        } catch (OtpResendTooSoonException $e) {
            return back()->withErrors(['phone' => "Please wait {$e->getRetryAfter()} seconds."]);
        } catch (OtpRateLimitedException) {
            return back()->withErrors(['phone' => 'Too many codes requested. Try again later.']);
        } catch (FeatureDisabledException $e) {
            return back()->withErrors(['phone' => $e->getAdminMessage() ?? 'SMS codes are temporarily unavailable.']);
        }

        $request->session()->put(['otp_id' => $otp->otpId, 'otp_phone' => $request->input('phone')]);

        return redirect()->route('login.code')->with([
            'code_length' => $otp->codeLength,         // size of the input
            'resend_after' => $otp->resendAfterSeconds, // "Resend the code" countdown
        ]);
    }

    // 2. The user types the code; 3. verify it.
    public function verify(Request $request)
    {
        $request->validate([
            'code' => ['bail', 'required', 'string', ValidOtp::withAttemptsRemaining($request->session()->get('otp_id'))],
        ]);

        $request->session()->forget('otp_id');
        $phone = \Labyrinthe\Connect\PhoneNumber::normalize($request->session()->pull('otp_phone'));
        Auth::login(User::firstOrCreate(['phone' => $phone]));
        $request->session()->regenerate();

        return redirect()->intended('/');
    }
}
```

| `Labyrinthe::otp()` | |
|---|---|
| `send($phone, template:, locale:, webOtpDomain:, stripAccents:, idempotencyKey:)` | `OtpSendResult`: `otpId`, `expiresAt`, `maxAttempts`, `codeLength`, `codeType`, `resendAfterSeconds`, `replayed`, `testCode()` |
| `verify($otpId, $code)` | `OtpVerifyResult`: `verified`, `reason`, `attemptsRemaining`, `isValid()` |
| `check(?$otpId, ?$code)` | plain `bool`; an empty or unknown `otpId` gives `false` |

| Rule | |
|---|---|
| `new PhoneNumber(?string $defaultCountryCode = null)` | offline check with the API rules (8 to 15 digits, international numbers accepted) |
| `new ValidOtp(?string $otpId, bool $showAttemptsRemaining = false)` | calls the API and fails with a translated message: wrong code, expired, already used, too many attempts, missing `otp_id` |
| `ValidOtp::withAttemptsRemaining($otpId)` | same, the wrong-code message says how many attempts are left ("The code field is incorrect. 2 attempts remaining.") |

- Each wrong code consumes an attempt: put `ValidOtp` last, after `bail`. `$rule->result()` gives the API result.
- Messages exist in English and French (`labyrinthe::validation.*`); publish them with `--tag=labyrinthe-lang`.
- A new send to the same number invalidates the previous code; limits are a minimum resend delay per number
  (`OtpResendTooSoonException`) and an hourly cap per number and per account (`OtpRateLimitedException`, `getScope()`,
  `getLimit()`), plus 10 sends / minute per API key.
- **Web OTP**: `webOtpDomain: 'app.example.com'` (or `LABYRINTHE_OTP_WEB_OTP_DOMAIN`) adds `@app.example.com #123456`
  to the SMS so Chrome / Android can fill the code in (`navigator.credentials.get({ otp: { transport: ['sms'] } })`).
- **Test mode**: with a `lsk_test_` key no SMS is sent and `$otp->testCode()` returns the code.
- Store the number normalised: `Labyrinthe\Connect\PhoneNumber::normalize($phone)` → `"243812345678"`.

## Webhooks

Register the endpoint, preferably in `routes/api.php` (no session):

```php
use Illuminate\Support\Facades\Route;

Route::labyrintheWebhooks();                     // POST /api/webhooks/labyrinthe, named labyrinthe.webhooks
// or Route::labyrintheWebhooks('hooks/sms');
```

Then enter the URL in the client area (*Developers > Webhooks*) and copy the secret into
`LABYRINTHE_WEBHOOK_SECRET`. The route excludes the CSRF middleware itself, so it also works from `routes/web.php`.
If you point your own route to `Labyrinthe\Connect\Laravel\Http\Controllers\WebhookController`, exclude CSRF for it.

The controller verifies the signature on the **raw body** in constant time (400 when invalid or older than the
tolerance; 500 while the secret is not set, so the delivery is retried), ignores ids already processed (atomic
`Cache::add()` on the event `id`), dispatches `WebhookReceived` then the typed event, and answers `200`. If a
listener throws, the controller answers 5xx and forgets the id, so the next delivery attempt is processed.

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Labyrinthe\Connect\Laravel\Events\MessageStatusChanged;

class UpdateMessageStatus implements ShouldQueue        // queue slow work: the API waits 10 s at most
{
    public function handle(MessageStatusChanged $event): void
    {
        SmsLog::where('message_id', $event->event->messageId)->update(['status' => $event->event->status->value]);
    }
}
```

| Laravel event (`Labyrinthe\Connect\Laravel\Events\…`) | Webhook | Payload (`$event->event`) |
|---|---|---|
| `MessageStatusChanged` | `message.status_changed` | `messageId`, `recipient`, `status` (enum), `providerMessageId`, `campaignId`, `reference`, `errorCode`, `errorMessage` |
| `CampaignCompleted` | `campaign.completed` | `campaignId`, `name`, `recipientsCount`, `countFor(MessageStatus)`, `creditsCharged`, `completedAt` |
| `BalanceLow` | `balance.low` | `balance`, `threshold` |
| `OtpVerified` | `otp.verified` | `otpId`, `phone`, `status` (`OtpWebhookStatus::Verified`), `verifiedAt` (never the code) |
| `OtpFailed` | `otp.failed` | `otpId`, `phone`, `status` (`Failed` or `Expired`), `reason` (`MaxAttemptsReached`, `Expired` or `null`) |
| `WebhookReceived` | every event, including `test.ping` and future types | `id()`, `type()`, `timestamp()`, `raw()` |

Deliveries are retried up to 5 times and may arrive out of order: keep `LABYRINTHE_WEBHOOK_DEDUPLICATE=true` (or
deduplicate on `$event->event->id()` yourself).

## Errors

All exceptions come from the PHP SDK (`Labyrinthe\Connect\Exception\…`) and implement `LabyrintheException`; API
errors extend `ApiException` with `getErrorCode()`, `getStatusCode()`, `getDetails()` and `getRequestId()`.

| Exception | HTTP | Code | What to do |
|---|---|---|---|
| `AuthenticationException` | 401 | `UNAUTHENTICATED` | check `LABYRINTHE_API_KEY` |
| `PermissionException` / `AccountSuspendedException` | 403 | `FORBIDDEN`, `OTP_DISABLED_FOR_ACCOUNT` / `ACCOUNT_SUSPENDED` | IP not allowed, OTP disabled, or account suspended: contact support |
| `NotFoundException` | 404 | `NOT_FOUND` | unknown message or `otp_id` |
| `ValidationException` | 422 | `VALIDATION_ERROR` | `getErrors()`, `getFieldErrors('body')` |
| `RecipientNotSendableException` | 422 | `RECIPIENT_NOT_SENDABLE` | nothing sent: `getRejected()` |
| `SenderIdNotApprovedException` | 422 | `SENDER_ID_NOT_APPROVED` | use an approved Sender ID |
| `InvalidPhoneException` | 422 | `INVALID_PHONE` | OTP: ask for another number |
| `InsufficientCreditsException` | 409 | `INSUFFICIENT_CREDITS` | nothing sent: buy credits (`getAvailable()`, `getRequired()`) |
| `IdempotencyKeyReusedException` | 409 | `IDEMPOTENCY_KEY_REUSED` | same key, different content (the first SMS was sent) |
| `IdempotencyKeyUnresolvedException` | 409 | `IDEMPOTENCY_KEY_UNRESOLVED` | outcome unknown: check with `Labyrinthe::sms()->list(reference: …)`, then send with a **new** key |
| `RateLimitException`, `OtpResendTooSoonException`, `OtpRateLimitedException` | 429 | `TOO_MANY_REQUESTS`, `OTP_RESEND_TOO_SOON`, `OTP_RATE_LIMITED` | wait `getRetryAfter()` seconds |
| `FeatureDisabledException` | 503 | `FEATURE_DISABLED`, `OTP_SERVICE_DISABLED` | maintenance, nothing sent: see below |
| `ServiceUnavailableException` | 5xx | any | temporary error, already retried when safe |
| `ConnectionException` | — | `CONNECTION_ERROR`, `TIMEOUT` | network error |
| `ApiException` | any | any other code | the list is open |

The full table, with every accessor, is in the [PHP SDK README](../php/README.md#errors).

### Maintenance (503 `FEATURE_DISABLED`)

A platform administrator can temporarily shut down the whole API (`api`), SMS sending (`api.sms_send`), OTP sending
or verification (`api.otp_send`, `api.otp_verify`) or the whole OTP service (`otp`, code `OTP_SERVICE_DISABLED`).
**Nothing was sent, no credit was used.** `FeatureDisabledException` (a `ServiceUnavailableException`) exposes
`getFeature()`, `getBlockedBy()`, `getAdminMessage()` (the administrator's text or `null`), `getUntil()`
(`DateTimeImmutable` or `null`) and `getRetryAfter()` (seconds or `null`). The SDK retries it only after a
`Retry-After` of at most 10 seconds; otherwise do not retry in a loop: release the job until the announced return
time (see below) or show the message to the user.

## Retries, idempotency and queues

- **Every SMS and OTP send carries an `Idempotency-Key`** (yours, or a generated UUID reused by the SDK's own
  retries): network errors, 5xx and `IDEMPOTENCY_REQUEST_IN_PROGRESS` are retried without a second SMS. A replay
  within 24 hours returns the first result (`$batch->replayed`, `$otp->replayed`, same `otpId`).
- **Notifications** use `laravel-notification-<notification id>` as key. Laravel keeps that id across queue retries,
  so a retried queued notification replays the first send instead of sending a second SMS.
- **Your own jobs**: pass a key derived from the business event, stable across retries.
- `otp->verify()` is never retried after a network error or a 5xx; business errors (4xx, `OTP_RESEND_TOO_SOON`,
  `OTP_RATE_LIMITED`, `IDEMPOTENCY_KEY_UNRESOLVED`) are never retried. Details: [PHP SDK
  README](../php/README.md#retries-and-idempotency).

```php
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Labyrinthe\Connect\Exception\FeatureDisabledException;
use Labyrinthe\Connect\Laravel\Facades\Labyrinthe;

class SendShippingSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(private int $orderId, private string $phone) {}

    public function handle(): void
    {
        try {
            Labyrinthe::send($this->phone, 'Your order has shipped.',
                reference: "order-{$this->orderId}",
                idempotencyKey: "order-{$this->orderId}-shipped");     // same key on every attempt
        } catch (FeatureDisabledException $e) {
            $this->release($e->getRetryAfter() ?? 900);              // maintenance: come back later
        }
    }
}
```

For a queued notification, set `$tries` and `$backoff` on the notification class: on retry it reuses the same key.

## Test mode, logging and security

- `lsk_test_…` keys use the sandbox: nothing is sent, nothing is billed, the balance is not checked, and OTP sends
  return the code (`testCode()`). `lsk_live_…` keys send for real. `Labyrinthe::isTestMode()` tells you which.
- `LABYRINTHE_LOG_CHANNEL=stack` sends the SDK logs (requests, retries, API errors with their `request_id`) to that
  channel. The API key, OTP codes and message bodies are never logged; phone numbers are masked.
- Keep the key in `.env`, never in the browser or a mobile app. `config:cache` works as usual.
- Every exception carries `getRequestId()` (the `X-Request-Id` header): give it to support
  (connect@labyrinthe-rdc.com). `Labyrinthe::client()->lastResponse()?->requestId()` gives the last one.

## Testing your application

`Labyrinthe::fake()` swaps the API for an in-memory implementation. The real SDK still runs (validation, phone
normalisation, typed errors), and nothing leaves your application.

```php
use Labyrinthe\Connect\Exception\FeatureDisabledException;
use Labyrinthe\Connect\Laravel\Facades\Labyrinthe;
use Labyrinthe\Connect\Laravel\Testing\SentSms;

public function test_customers_are_told_when_their_order_ships(): void
{
    Labyrinthe::fake();

    $this->post("/orders/{$order->id}/ship");

    Labyrinthe::assertSmsSentTo('0812345678', fn (SentSms $sms) => str_contains($sms->body, 'shipped'));
    Labyrinthe::assertSmsSentCount(1);
}

public function test_phone_login(): void
{
    $fake = Labyrinthe::fake();

    $this->post('/login/phone', ['phone' => '0812345678']);
    Labyrinthe::assertOtpSent('0812345678');

    $this->post('/login/code', ['code' => $fake->otpCode('0812345678')])->assertRedirect('/');
}

public function test_maintenance_is_shown(): void
{
    Labyrinthe::fake()->failNextRequestWithFeatureDisabled('api.otp_send', 'Back at 10:00.', now()->addHour());

    $this->post('/login/phone', ['phone' => '0812345678'])->assertSessionHasErrors('phone');
    Labyrinthe::assertNoOtpSent();
}
```

| Method | |
|---|---|
| `assertSmsSent(?callable)`, `assertSmsSentTo($phone, ?callable)`, `assertSmsNotSentTo($phone)`, `assertSmsSentCount($n)` | SMS (numbers in any format) |
| `assertOtpSent($phone or callable)`, `assertNoOtpSent()` | OTP |
| `assertNothingSent()` | no SMS, no OTP |
| `sent(?callable)`, `otps(?callable)`, `otpCode(?$phone)` | recorded `SentSms` / `SentOtp` and the last code |
| `withBalance(int)`, `withSenderIds([...])`, `withOtpCode('123456')`, `withOtpMaxAttempts(5)`, `withOtpResendAfter(60)` | set up the fake |
| `failNextRequest($code, $status = 422, $details = [], $message)` | next call fails, e.g. `failNextRequest('INSUFFICIENT_CREDITS', 409, ['available' => 0, 'required' => 1])` |
| `failNextRequestWithFeatureDisabled($feature = 'api', $message = null, $until = null, $blockedBy = null)` | next call fails with 503 `FEATURE_DISABLED` (`'otp'` → `OTP_SERVICE_DISABLED`; `$until` adds `Retry-After`); never retried by the fake |

The fake behaves like the sandbox: idempotent replays (SMS and OTP, compared after normalisation), merged duplicates,
single-use codes, attempts cap (3 by default) with `attemptsRemaining`, a new code invalidating the previous one.
`SentSms` records `recipients`, `body`, `sender`, `reference`, `idempotencyKey`, `messageIds`, `stripAccents`;
`SentOtp` records `otpId`, `phone`, `code`, `template`, `locale`, `webOtpDomain`, `stripAccents`, `idempotencyKey`.

## Without the package (raw HTTP)

The package wraps a REST JSON API; with Laravel's HTTP client only:

```php
Http::withToken(config('labyrinthe.api_key'))
    ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
    ->post('https://api.connect.labyrinthe-rdc.com/api/public/v1/sms', ['recipient' => '+243812345678', 'body' => 'Hello'])
    ->throw()
    ->json('data.0.status');                               // "queued"
```

You then handle retries, idempotency, errors and webhook signatures yourself: see the OpenAPI specification
[`openapi/openapi.yaml`](../openapi/openapi.yaml).

## Upgrading

See [CHANGELOG.md](CHANGELOG.md). 1.x releases are backward compatible. 1.2.0 requires `labyrinthe/connect-php`
^1.2.0 and adds `FeatureDisabledException` and `failNextRequestWithFeatureDisabled()`; no configuration change.

## Contributing

Issues and pull requests go to the monorepo
[Labyrinthe-Team/labyrinthe-connect-sdks](https://github.com/Labyrinthe-Team/labyrinthe-connect-sdks) (the
`labyrinthe-connect-laravel` repository is a read-only mirror). See [CONTRIBUTING.md](../CONTRIBUTING.md).

```bash
composer install       # uses ../php through a Composer "path" repository (monorepo)
composer check         # PHP-CS-Fixer (dry run) + Larastan level max + Orchestra Testbench tests
```

## License

MIT — see [LICENSE](LICENSE). Security issues: please email connect@labyrinthe-rdc.com rather than opening a public
issue.
