<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Enum;

/**
 * Which hourly OTP cap was reached (`OTP_RATE_LIMITED`).
 */
enum OtpRateLimitScope: string
{
    case Phone = 'phone';
    case Account = 'account';
    case Unknown = 'unknown';

    public static function fromApi(string $value): self
    {
        return self::tryFrom($value) ?? self::Unknown;
    }
}
