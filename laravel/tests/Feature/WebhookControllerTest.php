<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Tests\Feature;

use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Labyrinthe\Connect\Enum\MessageStatus;
use Labyrinthe\Connect\Enum\OtpFailureReason;
use Labyrinthe\Connect\Enum\OtpWebhookStatus;
use Labyrinthe\Connect\Laravel\Events\BalanceLow;
use Labyrinthe\Connect\Laravel\Events\CampaignCompleted;
use Labyrinthe\Connect\Laravel\Events\MessageStatusChanged;
use Labyrinthe\Connect\Laravel\Events\OtpFailed;
use Labyrinthe\Connect\Laravel\Events\OtpVerified;
use Labyrinthe\Connect\Laravel\Events\WebhookReceived;
use Labyrinthe\Connect\Laravel\LabyrintheServiceProvider;
use Labyrinthe\Connect\Laravel\Tests\TestCase;
use Labyrinthe\Connect\Webhook\Event\UnknownEvent;
use Labyrinthe\Connect\Webhook\Webhook;

final class WebhookControllerTest extends TestCase
{
    private const EVENTS = [WebhookReceived::class, MessageStatusChanged::class, CampaignCompleted::class, BalanceLow::class, OtpVerified::class, OtpFailed::class];

    protected function defineRoutes($router): void
    {
        Route::labyrintheWebhooks();
        Route::middleware('web')->group(static function (): void {
            Route::labyrintheWebhooks('web/webhooks/labyrinthe');
        });
    }

    /**
     * @param array<mixed> $data
     */
    private static function body(string $event, array $data, ?int $timestamp = null, string $id = 'evt-1'): string
    {
        return json_encode(['id' => $id, 'event' => $event, 'data' => $data, 'timestamp' => $timestamp ?? time()], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function deliver(string $body, ?string $signature = null, string $uri = '/webhooks/labyrinthe'): TestResponse
    {
        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => $signature ?? Webhook::sign($body, self::WEBHOOK_SECRET),
        ], $body);
    }

    private static function statusChanged(string $id = 'evt-1'): string
    {
        return self::body('message.status_changed', [
            'message_id' => '9d5c0a3e-6f0b-4c2e-9a51-3b7f2d8e4c10',
            'recipient' => '243812345678',
            'status' => 'delivered',
            'provider_message_id' => 'DD-1',
            'campaign_id' => null,
        ], id: $id);
    }

    public function test_the_macro_registers_a_named_post_route(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(static fn (\Illuminate\Routing\Route $r): bool => $r->uri() === 'webhooks/labyrinthe');

        self::assertNotNull($route);
        self::assertSame('labyrinthe.webhooks', $route->getName());
        self::assertSame(['POST'], $route->methods());
    }

    public function test_a_valid_delivery_dispatches_typed_events(): void
    {
        Event::fake(self::EVENTS);

        $this->deliver(self::statusChanged())->assertOk()->assertJson(['received' => true]);

        Event::assertDispatched(WebhookReceived::class, static fn (WebhookReceived $e): bool => $e->event->id() === 'evt-1');
        Event::assertDispatched(MessageStatusChanged::class, static fn (MessageStatusChanged $e): bool => $e->event->status === MessageStatus::Delivered
            && $e->event->recipient === '243812345678');
    }

    public function test_campaign_and_balance_events(): void
    {
        Event::fake(self::EVENTS);

        $this->deliver(self::body('campaign.completed', [
            'campaign_id' => 'c-1', 'name' => 'Promo', 'recipients_count' => 2,
            'messages_by_status' => ['delivered' => 2], 'credits_charged' => 2, 'completed_at' => null,
        ], id: 'evt-c'))->assertOk();
        $this->deliver(self::body('balance.low', ['balance' => 9, 'threshold' => 10], id: 'evt-b'))->assertOk();

        Event::assertDispatched(CampaignCompleted::class, static fn (CampaignCompleted $e): bool => $e->event->countFor(MessageStatus::Delivered) === 2);
        Event::assertDispatched(BalanceLow::class, static fn (BalanceLow $e): bool => $e->event->balance === 9);
    }

    public function test_otp_events(): void
    {
        Event::fake(self::EVENTS);

        $this->deliver(self::body('otp.verified', [
            'otp_id' => 'otp-1', 'phone' => '243812345678', 'status' => 'verified', 'reason' => null, 'verified_at' => '2026-11-06T09:41:02+00:00',
        ], id: 'evt-ov'))->assertOk();
        $this->deliver(self::body('otp.failed', [
            'otp_id' => 'otp-2', 'phone' => '243812345678', 'status' => 'failed', 'reason' => 'max_attempts_reached', 'verified_at' => null,
        ], id: 'evt-of'))->assertOk();
        $this->deliver(self::body('message.status_changed', [
            'message_id' => 'm-1', 'recipient' => '243812345678', 'status' => 'failed', 'provider_message_id' => null,
            'campaign_id' => null, 'reference' => 'CMD-1', 'error_code' => 'E1', 'error_message' => 'Refusé',
        ], id: 'evt-ms'))->assertOk();

        Event::assertDispatched(OtpVerified::class, static fn (OtpVerified $e): bool => $e->event->otpId === 'otp-1'
            && $e->event->status === OtpWebhookStatus::Verified && $e->event->verifiedAt !== null);
        Event::assertDispatched(OtpFailed::class, static fn (OtpFailed $e): bool => $e->event->otpId === 'otp-2'
            && $e->event->reason === OtpFailureReason::MaxAttemptsReached);
        Event::assertDispatched(MessageStatusChanged::class, static fn (MessageStatusChanged $e): bool => $e->event->reference === 'CMD-1'
            && $e->event->errorCode === 'E1');
        Event::assertDispatchedTimes(WebhookReceived::class, 3);
    }

    public function test_unknown_and_test_events_only_dispatch_webhook_received(): void
    {
        Event::fake(self::EVENTS);

        $this->deliver(self::body('contact.opted_out', ['phone' => 'x'], id: 'evt-u'))->assertOk();
        $this->deliver(self::body('test.ping', [], id: 'evt-t'))->assertOk();

        Event::assertDispatchedTimes(WebhookReceived::class, 2);
        Event::assertDispatched(WebhookReceived::class, static fn (WebhookReceived $e): bool => $e->event instanceof UnknownEvent);
        Event::assertNotDispatched(MessageStatusChanged::class);
        Event::assertNotDispatched(OtpVerified::class);
    }

    public function test_an_invalid_signature_is_rejected_with_400(): void
    {
        Event::fake(self::EVENTS);

        $this->deliver(self::statusChanged(), 'sha256='.str_repeat('0', 64))->assertStatus(400);
        $this->deliver(self::statusChanged(), '')->assertStatus(400);

        Event::assertNothingDispatched();
    }

    public function test_a_tampered_body_is_rejected(): void
    {
        Event::fake(self::EVENTS);
        $body = self::statusChanged();

        $this->deliver(str_replace('delivered', 'failed', $body), Webhook::sign($body, self::WEBHOOK_SECRET))->assertStatus(400);

        Event::assertNothingDispatched();
    }

    public function test_an_expired_delivery_is_rejected(): void
    {
        Event::fake(self::EVENTS);

        $this->deliver(self::body('test.ping', [], time() - 3600))->assertStatus(400);

        Event::assertNothingDispatched();
    }

    public function test_the_tolerance_is_configurable(): void
    {
        Event::fake(self::EVENTS);
        config()->set('labyrinthe.webhook.tolerance', 7200);

        $this->deliver(self::body('test.ping', [], time() - 3600))->assertOk();
    }

    public function test_duplicate_deliveries_are_acknowledged_but_dispatched_once(): void
    {
        Event::fake(self::EVENTS);

        $this->deliver(self::statusChanged('evt-dup'))->assertOk();
        $this->deliver(self::statusChanged('evt-dup'))->assertOk()->assertJson(['duplicate' => true]);

        Event::assertDispatchedTimes(MessageStatusChanged::class, 1);
    }

    public function test_deduplication_can_be_disabled(): void
    {
        Event::fake(self::EVENTS);
        config()->set('labyrinthe.webhook.deduplicate', false);

        $this->deliver(self::statusChanged('evt-same'))->assertOk();
        $this->deliver(self::statusChanged('evt-same'))->assertOk();

        Event::assertDispatchedTimes(MessageStatusChanged::class, 2);
    }

    public function test_a_failing_listener_lets_the_next_attempt_be_processed(): void
    {
        $calls = 0;
        Event::listen(MessageStatusChanged::class, static function () use (&$calls): void {
            if (++$calls === 1) {
                throw new \RuntimeException('Database down');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->deliver(self::statusChanged('evt-retry'));
            self::fail('The listener exception should propagate (5xx: the API retries).');
        } catch (\RuntimeException $e) {
            self::assertSame('Database down', $e->getMessage());
        }

        $this->deliver(self::statusChanged('evt-retry'))->assertOk()->assertJsonMissing(['duplicate' => true]);
        self::assertSame(2, $calls);
    }

    public function test_a_missing_secret_answers_500_so_the_api_retries(): void
    {
        config()->set('labyrinthe.webhook.secret', null);

        $this->deliver(self::statusChanged(), 'sha256=x')->assertStatus(500);
    }

    public function test_it_works_in_the_web_group_without_csrf_token(): void
    {
        Event::fake(self::EVENTS);

        $this->deliver(self::statusChanged('evt-web'), null, '/web/webhooks/labyrinthe')->assertOk();
        Event::assertDispatched(MessageStatusChanged::class);

        // CSRF is skipped while running tests: check the resolved middleware instead.
        $route = collect(Route::getRoutes()->getRoutes())->first(static fn (\Illuminate\Routing\Route $r): bool => $r->uri() === 'web/webhooks/labyrinthe');
        self::assertNotNull($route);
        $router = app()->make(Router::class);
        $middleware = array_values(array_filter($router->gatherRouteMiddleware($route), 'is_string'));
        self::assertContains(StartSession::class, $middleware, 'The web group applies.');

        $csrf = array_filter(LabyrintheServiceProvider::CSRF_MIDDLEWARE, 'class_exists');
        foreach ($middleware as $class) {
            foreach ($csrf as $csrfClass) {
                self::assertFalse(is_a($class, $csrfClass, true), $class.' should be excluded.');
            }
        }

        // Without the exclusion, the web group would verify CSRF tokens.
        $plain = $router->post('plain-web-route', static fn (): string => 'ok')->middleware('web');
        $plainMiddleware = array_values(array_filter($router->gatherRouteMiddleware($plain), 'is_string'));
        self::assertNotEmpty(array_filter($plainMiddleware, static fn (string $class): bool => array_filter($csrf, static fn (string $c): bool => is_a($class, $c, true)) !== []));
    }
}
