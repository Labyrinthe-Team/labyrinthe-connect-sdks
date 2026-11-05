<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Events;

use Labyrinthe\Connect\Webhook\Event\CampaignCompleted as Payload;

/**
 * Webhook `campaign.completed`.
 */
final class CampaignCompleted
{
    public function __construct(public readonly Payload $event)
    {
    }
}
