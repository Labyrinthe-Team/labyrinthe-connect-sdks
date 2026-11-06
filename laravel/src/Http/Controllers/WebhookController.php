<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Http\Controllers;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Labyrinthe\Connect\Exception\InvalidWebhookPayloadException;
use Labyrinthe\Connect\Exception\SignatureVerificationException;
use Labyrinthe\Connect\Laravel\Events\BalanceLow;
use Labyrinthe\Connect\Laravel\Events\CampaignCompleted;
use Labyrinthe\Connect\Laravel\Events\MessageStatusChanged;
use Labyrinthe\Connect\Laravel\Events\OtpFailed;
use Labyrinthe\Connect\Laravel\Events\OtpVerified;
use Labyrinthe\Connect\Laravel\Events\WebhookReceived;
use Labyrinthe\Connect\Webhook\Event;
use Labyrinthe\Connect\Webhook\Event\BalanceLow as BalanceLowPayload;
use Labyrinthe\Connect\Webhook\Event\CampaignCompleted as CampaignCompletedPayload;
use Labyrinthe\Connect\Webhook\Event\MessageStatusChanged as MessageStatusChangedPayload;
use Labyrinthe\Connect\Webhook\Event\OtpFailed as OtpFailedPayload;
use Labyrinthe\Connect\Webhook\Event\OtpVerified as OtpVerifiedPayload;
use Labyrinthe\Connect\Webhook\Webhook;
use Psr\Log\LoggerInterface;

/**
 * Receives Labyrinthe Connect webhooks (Route::labyrintheWebhooks()).
 *
 * Verifies the signature on the raw body, ignores already-processed ids,
 * dispatches WebhookReceived then the typed event, and answers 200. Keep
 * listeners fast (or ShouldQueue): the API waits 10 s at most.
 */
final class WebhookController
{
    private const CACHE_PREFIX = 'labyrinthe:webhook:';

    public function __invoke(Request $request, Config $config, Dispatcher $events, CacheFactory $cache, LoggerInterface $logger): JsonResponse
    {
        $secret = $config->get('labyrinthe.webhook.secret');
        if (!\is_string($secret) || $secret === '') {
            $logger->error('Labyrinthe Connect webhook received but LABYRINTHE_WEBHOOK_SECRET is not set.');

            // 5xx: the delivery is retried once the secret is configured.
            return new JsonResponse(['error' => 'Webhook secret not configured.'], 500);
        }

        $tolerance = $config->get('labyrinthe.webhook.tolerance', Webhook::DEFAULT_TOLERANCE);

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header(Webhook::SIGNATURE_HEADER, ''),
                $secret,
                is_numeric($tolerance) ? (int) $tolerance : Webhook::DEFAULT_TOLERANCE,
            );
        } catch (SignatureVerificationException|InvalidWebhookPayloadException $e) {
            $logger->warning('Labyrinthe Connect webhook rejected: '.$e->getMessage());

            return new JsonResponse(['error' => 'Invalid webhook.'], 400);
        }

        $store = null;
        $key = self::CACHE_PREFIX.$event->id();
        if ($config->get('labyrinthe.webhook.deduplicate', true)) {
            $storeName = $config->get('labyrinthe.webhook.cache_store');
            $store = $cache->store(\is_string($storeName) && $storeName !== '' ? $storeName : null);
            $ttl = $config->get('labyrinthe.webhook.cache_ttl', 86400);

            // add() is atomic: two concurrent deliveries of the same id dispatch once.
            if (!$store->add($key, true, is_numeric($ttl) ? (int) $ttl : 86400)) {
                return new JsonResponse(['received' => true, 'duplicate' => true]);
            }
        }

        try {
            $events->dispatch(new WebhookReceived($event));
            $typed = self::typedEvent($event);
            if ($typed !== null) {
                $events->dispatch($typed);
            }
        } catch (\Throwable $e) {
            // Let the next delivery attempt be processed.
            $store?->forget($key);

            throw $e;
        }

        return new JsonResponse(['received' => true]);
    }

    private static function typedEvent(Event $event): ?object
    {
        return match (true) {
            $event instanceof MessageStatusChangedPayload => new MessageStatusChanged($event),
            $event instanceof CampaignCompletedPayload => new CampaignCompleted($event),
            $event instanceof BalanceLowPayload => new BalanceLow($event),
            $event instanceof OtpVerifiedPayload => new OtpVerified($event),
            $event instanceof OtpFailedPayload => new OtpFailed($event),
            default => null,
        };
    }
}
