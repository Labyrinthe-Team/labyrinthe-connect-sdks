<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Webhook\Event;

use Labyrinthe\Connect\Enum\MessageStatus;
use Labyrinthe\Connect\Webhook\Event;

/**
 * `campaign.completed`: every message of a campaign reached a final status.
 */
final class CampaignCompleted implements Event
{
    use EnvelopeTrait;

    /**
     * @param array<string, int> $messagesByStatus number of messages per final status
     * @param array<mixed>       $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly int $timestamp,
        public readonly string $campaignId,
        public readonly string $name,
        public readonly int $recipientsCount,
        public readonly array $messagesByStatus,
        public readonly int $creditsCharged,
        public readonly ?\DateTimeImmutable $completedAt,
        private readonly array $raw = [],
    ) {
    }

    /** Number of messages that ended with this status. */
    public function countFor(MessageStatus $status): int
    {
        return $this->messagesByStatus[$status->value] ?? 0;
    }
}
