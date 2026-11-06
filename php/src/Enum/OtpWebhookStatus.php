<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Enum;

/**
 * Outcome of an OTP verification, in the `otp.verified` and `otp.failed` webhooks.
 */
enum OtpWebhookStatus: string
{
    /** The right code was typed (`otp.verified`). */
    case Verified = 'verified';
    /** Attempts exhausted (`otp.failed`, reason `max_attempts_reached`). */
    case Failed = 'failed';
    /** The code expired (`otp.failed`, reason `expired`). */
    case Expired = 'expired';
    /** Status not known by this SDK version. */
    case Unknown = 'unknown';

    public static function fromApi(string $value): self
    {
        return self::tryFrom($value) ?? self::Unknown;
    }
}
