<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

use Labyrinthe\Connect\Enum\RejectionReason;
use Labyrinthe\Connect\Model\RejectedRecipient;

/**
 * 422 RECIPIENT_NOT_SENDABLE: at least one recipient is invalid, blacklisted or
 * opted out. Sending is all-or-nothing: NOTHING was sent and no credit was
 * used. Remove the rejected numbers (getRejected()) and send again.
 */
final class RecipientNotSendableException extends ApiException
{
    /** Reason of the first rejection. */
    public function getReason(): RejectionReason
    {
        $reason = $this->getDetails()['reason'] ?? null;

        return \is_string($reason) ? RejectionReason::fromApi($reason) : RejectionReason::Unknown;
    }

    /**
     * Every rejected recipient (POST /sms). Empty for OTP sends.
     *
     * @return list<RejectedRecipient>
     */
    public function getRejected(): array
    {
        $rejected = $this->getDetails()['rejected'] ?? [];
        if (!\is_array($rejected)) {
            return [];
        }

        $items = [];
        foreach ($rejected as $item) {
            if (\is_array($item) && isset($item['recipient']) && \is_string($item['recipient'])) {
                $reason = $item['reason'] ?? null;
                $items[] = new RejectedRecipient(
                    $item['recipient'],
                    \is_string($reason) ? RejectionReason::fromApi($reason) : RejectionReason::Unknown,
                );
            }
        }

        return $items;
    }
}
