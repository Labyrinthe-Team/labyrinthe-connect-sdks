<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Enum;

/**
 * Why an OTP verification failed (`verified: false`).
 */
enum OtpFailureReason: string
{
    /** Wrong code: one attempt was consumed. */
    case InvalidCode = 'invalid_code';
    /** The code expired: send a new one. */
    case Expired = 'expired';
    /** The code was already verified (each code is single-use). */
    case AlreadyUsed = 'already_used';
    /** Too many wrong attempts: the code is permanently blocked. */
    case MaxAttemptsReached = 'max_attempts_reached';
    /** Reason not known by this SDK version. */
    case Unknown = 'unknown';

    public static function fromApi(string $value): self
    {
        return self::tryFrom($value) ?? self::Unknown;
    }

    /** True when the user may type the code again for the same `otp_id`. */
    public function canRetry(): bool
    {
        return $this === self::InvalidCode;
    }
}
