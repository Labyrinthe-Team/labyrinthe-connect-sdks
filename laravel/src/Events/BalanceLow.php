<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Events;

use Labyrinthe\Connect\Webhook\Event\BalanceLow as Payload;

/**
 * Webhook `balance.low`: time to buy credits.
 */
final class BalanceLow
{
    public function __construct(public readonly Payload $event)
    {
    }
}
