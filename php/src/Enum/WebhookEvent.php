<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Enum;

/**
 * Webhook event types known by this SDK version (others arrive as UnknownEvent).
 */
enum WebhookEvent: string
{
    case MessageStatusChanged = 'message.status_changed';
    case CampaignCompleted = 'campaign.completed';
    case BalanceLow = 'balance.low';
    case OtpVerified = 'otp.verified';
    case OtpFailed = 'otp.failed';
    case TestPing = 'test.ping';
}
