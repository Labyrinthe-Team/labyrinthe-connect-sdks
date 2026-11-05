<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Notifications;

use Illuminate\Contracts\Container\Container;
use Illuminate\Notifications\Notification;
use Labyrinthe\Connect\Client;
use Labyrinthe\Connect\Model\Message;
use Labyrinthe\Connect\Model\MessageBatch;

/**
 * Notification channel `labyrinthe`.
 *
 *     public function via($notifiable): array { return ['labyrinthe']; }
 *     public function toLabyrinthe($notifiable): LabyrintheMessage|string { … }
 *
 * Recipient: routeNotificationForLabyrinthe() on the notifiable, otherwise its
 * `phone` attribute, or Notification::route('labyrinthe', '0812345678').
 */
final class LabyrintheChannel
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @throws \Labyrinthe\Connect\Exception\LabyrintheException
     */
    public function send(mixed $notifiable, Notification $notification): Message|MessageBatch|null
    {
        if (!method_exists($notification, 'toLabyrinthe')) {
            throw new \LogicException(\sprintf('%s must define a toLabyrinthe($notifiable) method to use the "labyrinthe" channel.', $notification::class));
        }

        $message = $notification->toLabyrinthe($notifiable);
        if (\is_string($message)) {
            $message = LabyrintheMessage::create($message);
        }
        if (!$message instanceof LabyrintheMessage) {
            throw new \LogicException(\sprintf('%s::toLabyrinthe() must return a string or a %s.', $notification::class, LabyrintheMessage::class));
        }

        $to = self::recipients($message->getTo() ?? $this->route($notifiable, $notification));
        if ($to === []) {
            return null;
        }

        $sms = $this->container->make(Client::class)->sms;
        $key = $message->getIdempotencyKey() ?? self::keyFor($notification);

        return \count($to) === 1
            ? $sms->send($to[0], $message->getContent(), $message->getSender(), $message->getReference(), $key)
            : $sms->sendMany($to, $message->getContent(), $message->getSender(), $message->getReference(), $key);
    }

    private function route(mixed $notifiable, Notification $notification): mixed
    {
        if (\is_object($notifiable) && method_exists($notifiable, 'routeNotificationFor')) {
            $route = $notifiable->routeNotificationFor('labyrinthe', $notification);
            if ($route !== null) {
                return $route;
            }
        }

        if (\is_object($notifiable) && isset($notifiable->phone)) {
            return $notifiable->phone;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function recipients(mixed $route): array
    {
        $route = \is_array($route) ? $route : [$route];

        return array_values(array_filter(
            array_map(static fn (mixed $r): string => \is_string($r) || \is_int($r) ? trim((string) $r) : '', $route),
            static fn (string $r): bool => $r !== '',
        ));
    }

    /**
     * Laravel gives every (notification, notifiable) pair a UUID kept across
     * queue retries: using it as Idempotency-Key makes retries safe.
     */
    private static function keyFor(Notification $notification): ?string
    {
        $id = $notification->id ?? null;

        return \is_string($id) && $id !== '' ? 'laravel-notification-'.$id : null;
    }
}
