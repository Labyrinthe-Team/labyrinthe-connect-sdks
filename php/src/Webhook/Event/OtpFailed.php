<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Webhook\Event;

use Labyrinthe\Connect\Enum\OtpFailureReason;
use Labyrinthe\Connect\Enum\OtpWebhookStatus;
use Labyrinthe\Connect\Webhook\Event;

/**
 * `otp.failed`: a verification failed for good, sent once. Either the
 * attempts were exhausted (`status` failed, `reason` max_attempts_reached) or
 * the code was found expired (`status` expired, `reason` expired). A wrong code
 * that leaves attempts, or a code invalidated by a new send, sends nothing.
 */
final class OtpFailed implements Event
{
    use EnvelopeTrait;

    /**
     * @param array<mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly int $timestamp,
        public readonly string $otpId,
        /** Normalised number (international, without "+"). */
        public readonly string $phone,
        /** `failed` or `expired`. */
        public readonly OtpWebhookStatus $status,
        /** `MaxAttemptsReached` or `Expired`. */
        public readonly ?OtpFailureReason $reason,
        /** Always null for a failure. */
        public readonly ?\DateTimeImmutable $verifiedAt,
        private readonly array $raw = [],
    ) {
    }
}
