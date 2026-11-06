<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Webhook\Event;

use Labyrinthe\Connect\Enum\MessageStatus;
use Labyrinthe\Connect\Webhook\Event;

/**
 * `message.status_changed`: the status of a message of the account changed
 * (API, client area or campaign).
 */
final class MessageStatusChanged implements Event
{
    use EnvelopeTrait;

    /**
     * @param array<mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly int $timestamp,
        public readonly string $messageId,
        public readonly string $recipient,
        public readonly MessageStatus $status,
        public readonly ?string $providerMessageId,
        public readonly ?string $campaignId,
        private readonly array $raw = [],
        /** The `reference` given when sending (POST /sms). */
        public readonly ?string $reference = null,
        /** Provider or platform error code, on failure. */
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {
    }
}
