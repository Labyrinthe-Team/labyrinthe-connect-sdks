# Labyrinthe Connect for Laravel

[![CI](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/actions/workflows/laravel.yml/badge.svg)](https://github.com/labyrinthe-rdc/labyrinthe-connect-sdks/actions/workflows/laravel.yml)
[![Packagist](https://img.shields.io/packagist/v/labyrinthe/connect-laravel.svg)](https://packagist.org/packages/labyrinthe/connect-laravel)
![Laravel](https://img.shields.io/badge/laravel-10%20%7C%2011%20%7C%2012%20%7C%2013-ff2d20)

Send SMS and verify phone numbers with [Labyrinthe Connect](https://labyrinthe-rdc.com) from Laravel: a
notification channel, an OTP helper with validation rules, signed webhooks turned into Laravel events, and a fake
for your tests. Built on the [PHP SDK](../php).

*Version française : [README.fr.md](README.fr.md).*

## Installation

```bash
composer require labyrinthe/connect-laravel
```

The service provider and the `Labyrinthe` facade are auto-discovered. Add your key to `.env`:

```dotenv
LABYRINTHE_API_KEY=lsk_test_xxxxxxxxxxxxxxxx   # test key: nothing is sent, no credit is used
LABYRINTHE_SENDER=MYSHOP                       # optional: approved Sender ID used by default
LABYRINTHE_WEBHOOK_SECRET=whsec_…              # optional: to receive webhooks
```

Optionally publish the configuration (`config/labyrinthe.php`) and translations:

```bash
php artisan vendor:publish --tag=labyrinthe-config
php artisan vendor:publish --tag=labyrinthe-lang
```

| Variable | Default | |
|---|---|---|
| `LABYRINTHE_API_KEY` | — | `lsk_test_…` (sandbox) or `lsk_live_…` |
| `LABYRINTHE_BASE_URL` | `https://api.labyrinthe-rdc.com/api/public/v1` | `http://localhost:8000` is completed with `/api/public/v1` |
| `LABYRINTHE_TIMEOUT`, `LABYRINTHE_CONNECT_TIMEOUT` | `30`, `10` | seconds |
| `LABYRINTHE_MAX_RETRIES` | `2` | safe retries only (never a second SMS or OTP) |
| `LABYRINTHE_DEFAULT_COUNTRY_CODE` | `243` | added to local numbers (`0812345678` → `243812345678`) |
| `LABYRINTHE_SENDER` | — | default Sender ID |
| `LABYRINTHE_OTP_TEMPLATE`, `LABYRINTHE_OTP_LOCALE` | — | OTP defaults (else the account's OTP settings) |
| `LABYRINTHE_WEBHOOK_SECRET` | — | endpoint secret (client area > Developers > Webhooks) |
| `LABYRINTHE_WEBHOOK_PATH` | `webhooks/labyrinthe` | URI used by `Route::labyrintheWebhooks()` |
| `LABYRINTHE_WEBHOOK_TOLERANCE` | `300` | max age of a delivery, in seconds |
| `LABYRINTHE_WEBHOOK_DEDUPLICATE` | `true` | ignore already-processed event ids (cache) |
| `LABYRINTHE_LOG_CHANNEL` | — | log channel for SDK logs (no key, no code, masked numbers) |

## Notifications

```php
use Illuminate\Notifications\Notification;
use Labyrinthe\Connect\Laravel\Notifications\LabyrintheMessage;

class OrderShipped extends Notification
{
    public function __construct(private Order $order) {}

    public function via(object $notifiable): array
    {
        return ['labyrinthe'];
    }

    public function toLabyrinthe(object $notifiable): LabyrintheMessage
    {
        return LabyrintheMessage::create("Your order {$this->order->number} has shipped.")
            ->reference("order-{$this->order->id}");   // optional; ->sender('MYSHOP') too
    }
}
```

The phone number comes from `routeNotificationForLabyrinthe()` on the notifiable, otherwise from its `phone`
attribute:

```php
class User extends Authenticatable
{
    use Notifiable;

    public function routeNotificationForLabyrinthe(Notification $notification): string
    {
        return $this->mobile_number;     // any format: +243…, 0812…, 243…
    }
}

$user->notify(new OrderShipped($order));
Notification::route('labyrinthe', '0812345678')->notify(new OrderShipped($order));   // on demand
```

`toLabyrinthe()` may also return a plain string. Returning several numbers sends them in one all-or-nothing request.

**Queued notifications are safe to retry**: the channel uses the notification id as `Idempotency-Key`, so a retried
job replays the first send instead of sending a second SMS (if the content changed in between, the API answers
`IDEMPOTENCY_KEY_REUSED`: the first SMS was already sent).

## Sending directly

```php
use Labyrinthe\Connect\Laravel\Facades\Labyrinthe;

$message = Labyrinthe::sms()->send(to: '0812345678', body: 'Your code is ready.', reference: 'order-1042');
$batch = Labyrinthe::sms()->sendMany(['0812345678', '0899999999'], 'Meeting tomorrow at 9am.');

Labyrinthe::send('0812345678', 'Hello');                  // shortcut (string → Message, array → MessageBatch)
Labyrinthe::sms()->estimate('Réunion à 9h', ['0812345678'])->totalCredits;
Labyrinthe::balance()->get()->balance;
Labyrinthe::senderIds()->list();
```

You can also inject `Labyrinthe\Connect\Client` (singleton) or `Labyrinthe\Connect\Laravel\LabyrintheManager`.
See the [PHP SDK README](../php/README.md) for every option, error and retry rule.

## OTP login

```php
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
            $otp = Labyrinthe::otp()->send($request->phone);
        } catch (OtpResendTooSoonException $e) {
            return back()->withErrors(['phone' => "Please wait {$e->getRetryAfter()} seconds."]);
        }

        $request->session()->put('otp_id', $otp->otpId);
        $request->session()->put('otp_phone', $request->phone);

        return redirect()->route('login.code');
    }

    // 2. The user types the code; 3. verify it.
    public function verify(Request $request)
    {
        $request->validate([
            'code' => ['bail', 'required', 'string', new ValidOtp($request->session()->get('otp_id'))],
        ]);

        $request->session()->forget('otp_id');
        $user = User::firstOrCreate(['phone' => \Labyrinthe\Connect\PhoneNumber::normalize($request->session()->pull('otp_phone'))]);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }
}
```

- `ValidOtp` calls the API and gives a translated message (English and French included) for each failure:
  wrong code, expired, already used, too many attempts. Each wrong code consumes an attempt: keep it last, with `bail`.
- `Labyrinthe::otp()->check($otpId, $code)` returns a plain boolean; `verify()` returns the full result with its reason.
- `PhoneNumber` validates a number offline with the same rules as the API (international numbers are accepted).
- With a test key no SMS is sent and `$otp->testCode()` contains the code.

## Webhooks

Register the endpoint, preferably in `routes/api.php` (no session, no CSRF):

```php
use Illuminate\Support\Facades\Route;

Route::labyrintheWebhooks();                       // POST /api/webhooks/labyrinthe
// or Route::labyrintheWebhooks('hooks/sms');
```

Then enter the URL in the client area (*Developers > Webhooks*) and copy the secret into
`LABYRINTHE_WEBHOOK_SECRET`. The route excludes the CSRF middleware itself, so it also works from `routes/web.php`
(Laravel 10 to 13). If you register your own route to `WebhookController`, exclude CSRF for it
(`validateCsrfTokens(except: ['webhooks/labyrinthe'])` in `bootstrap/app.php`, or `$except` in
`VerifyCsrfToken` on Laravel 10).

The controller verifies the signature on the raw body (400 if invalid or older than the tolerance), ignores ids
already processed (cache), dispatches the events below and answers `200` immediately. Listen to them:

```php
use Labyrinthe\Connect\Laravel\Events\BalanceLow;
use Labyrinthe\Connect\Laravel\Events\CampaignCompleted;
use Labyrinthe\Connect\Laravel\Events\MessageStatusChanged;
use Labyrinthe\Connect\Laravel\Events\WebhookReceived;

class UpdateMessageStatus implements ShouldQueue        // queue slow work: the API waits 10 s at most
{
    public function handle(MessageStatusChanged $event): void
    {
        SmsLog::where('provider_id', $event->event->messageId)->update(['status' => $event->event->status->value]);
    }
}
```

| Laravel event | Webhook | Payload (`$event->event`) |
|---|---|---|
| `MessageStatusChanged` | `message.status_changed` | `messageId`, `recipient`, `status` (enum), `providerMessageId`, `campaignId` |
| `CampaignCompleted` | `campaign.completed` | `campaignId`, `name`, `recipientsCount`, `countFor(MessageStatus)`, `creditsCharged`, `completedAt` |
| `BalanceLow` | `balance.low` | `balance`, `threshold` |
| `WebhookReceived` | every event, including `test.ping` and future types | `id()`, `type()`, `raw()` |

If a listener throws, the controller answers 5xx and forgets the id, so the next delivery attempt is processed.

## Testing your application

`Labyrinthe::fake()` swaps the API for an in-memory implementation. The real SDK still runs (validation, phone
normalisation, typed errors), nothing leaves your application:

```php
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
```

| Method | |
|---|---|
| `assertSmsSent(?callable)`, `assertSmsSentTo($phone, ?callable)`, `assertSmsNotSentTo($phone)`, `assertSmsSentCount($n)` | SMS (numbers in any format) |
| `assertOtpSent($phone or callable)`, `assertNoOtpSent()` | OTP |
| `assertNothingSent()` | no SMS, no OTP |
| `sent()`, `otps()`, `otpCode(?$phone)` | inspect what was recorded |
| `withBalance(int)`, `withSenderIds([...])`, `withOtpCode('123456')` | set up the fake |
| `failNextRequest('INSUFFICIENT_CREDITS', 409, ['available' => 0, 'required' => 1])` | simulate an API error |

The fake behaves like the sandbox: idempotent replays, merged duplicates, OTP single use, attempts cap, a new code
invalidating the previous one.

## Development

```bash
composer install       # uses ../php through a Composer "path" repository (monorepo)
composer test          # Orchestra Testbench
composer analyse       # Larastan, level max
composer lint
```

## License

MIT — see [LICENSE](LICENSE).
