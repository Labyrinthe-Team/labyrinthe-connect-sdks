<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Enum;

/**
 * Why a recipient was refused (`RECIPIENT_NOT_SENDABLE`).
 */
enum RejectionReason: string
{
    case InvalidPhone = 'invalid_phone';
    case Blacklisted = 'blacklisted';
    case OptedOut = 'opted_out';
    /** Reason not known by this SDK version. */
    case Unknown = 'unknown';

    public static function fromApi(string $value): self
    {
        return self::tryFrom($value) ?? self::Unknown;
    }
}
