<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Enum;

/**
 * Alphabet of an OTP code (`code_type`), from the account's OTP settings.
 */
enum OtpCodeType: string
{
    /** Digits 0-9: show a numeric keypad (inputmode="numeric"). */
    case Numeric = 'numeric';
    /** Upper-case letters and digits without ambiguous characters; typing is case-insensitive. */
    case Alphanumeric = 'alphanumeric';
    /** Type not known by this SDK version. */
    case Unknown = 'unknown';

    public static function fromApi(string $value): self
    {
        return self::tryFrom($value) ?? self::Unknown;
    }
}
