<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Model;

use Labyrinthe\Connect\Enum\RejectionReason;

/**
 * A recipient refused by the API (RECIPIENT_NOT_SENDABLE).
 */
final class RejectedRecipient
{
    public function __construct(
        /** The number as sent, or normalised by the API. */
        public readonly string $recipient,
        public readonly RejectionReason $reason,
    ) {
    }
}
