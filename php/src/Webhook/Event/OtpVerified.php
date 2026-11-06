<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Webhook\Event;

use Labyrinthe\Connect\Enum\OtpFailureReason;
use Labyrinthe\Connect\Enum\OtpWebhookStatus;
use Labyrinthe\Connect\Webhook\Event;

/**
 * `otp.verified`: POST /otp/verify received the right code (API or OTP
 * console of the client area). Sent once; never contains the code.
 */
final class OtpVerified implements Event
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
        /** `verified`. */
        public readonly OtpWebhookStatus $status,
        /** Null for a verified code. */
        public readonly ?OtpFailureReason $reason,
        public readonly ?\DateTimeImmutable $verifiedAt,
        private readonly array $raw = [],
    ) {
    }
}
