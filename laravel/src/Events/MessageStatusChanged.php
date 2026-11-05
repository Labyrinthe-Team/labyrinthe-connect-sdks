<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Events;

use Labyrinthe\Connect\Webhook\Event\MessageStatusChanged as Payload;

/**
 * Webhook `message.status_changed`.
 *
 *     public function handle(MessageStatusChanged $e): void
 *     {
 *         if ($e->event->status->isFinal()) { … $e->event->messageId … }
 *     }
 */
final class MessageStatusChanged
{
    public function __construct(public readonly Payload $event)
    {
    }
}
