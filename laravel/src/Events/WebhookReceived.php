<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Events;

use Labyrinthe\Connect\Webhook\Event;

/**
 * Any verified webhook, including types this version does not know
 * (`$event->event` is then an UnknownEvent). Dispatched before the typed event.
 */
final class WebhookReceived
{
    public function __construct(public readonly Event $event)
    {
    }
}
