<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Enum;

/**
 * Webhook event types known by this SDK version.
 */
enum WebhookEvent: string
{
    case MessageStatusChanged = 'message.status_changed';
    case CampaignCompleted = 'campaign.completed';
    case BalanceLow = 'balance.low';
    case TestPing = 'test.ping';
}
