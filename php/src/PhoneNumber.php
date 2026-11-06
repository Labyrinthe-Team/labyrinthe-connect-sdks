<?php

declare(strict_types=1);

namespace Labyrinthe\Connect;

use Labyrinthe\Connect\Exception\InvalidArgumentException;

/**
 * Phone number helpers, consistent with the API's own normalisation.
 *
 * normalize() returns the format of the API responses: international digits
 * without "+" (e.g. "243812345678"). Requests are sent in E.164 with "+"
 * (toE164(): "+243812345678"), so that the API never mistakes a short foreign
 * number for a local one ("+2481234567" stays in the Seychelles).
 * Accepted input formats (with the default country code 243, DR Congo):
 *
 *  - "+243 81 234 5678", "00243812345678"  → "243812345678" (explicit international prefix kept)
 *  - "243812345678"                         → "243812345678"
 *  - "33612345678" (11+ digits, no leading 0) → "33612345678"  (already international, other country)
 *  - "0812345678", "812345678"              → "243812345678" (local format, default country code added)
 *  - "+33 6 12 34 56 78"                    → "33612345678"  (other countries are never blocked)
 *
 * Idempotent, like the API: a normalised number stays unchanged. A foreign
 * number of 10 digits or less must be written with "+".
 *
 * Validation is deliberately light (8 to 15 digits, E.164): the API remains
 * the authority on whether a number can receive SMS.
 */
final class PhoneNumber
{
    public const DEFAULT_COUNTRY_CODE = '243';

    private function __construct()
    {
    }

    /**
     * Normalises a number, or returns null when it cannot be a valid phone number.
     */
    public static function normalize(string $raw, string $defaultCountryCode = self::DEFAULT_COUNTRY_CODE): ?string
    {
        self::assertCountryCode($defaultCountryCode);

        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $explicitInternational = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        $digits = (string) preg_replace('/\D/', '', $raw);

        if ($explicitInternational) {
            $digits = str_starts_with($raw, '00') ? substr($digits, 2) : $digits;
        } elseif (str_starts_with($digits, $defaultCountryCode) && \strlen($digits) > 10) {
            // Already international with the default country code ("243812345678").
        } elseif (\strlen($digits) >= 11 && !str_starts_with($digits, '0')) {
            // Already international, other country, without "+" ("33612345678"): no local
            // number of the default country is that long.
        } else {
            // Local format: drop the trunk "0" and add the default country code.
            $digits = $defaultCountryCode.ltrim($digits, '0');
        }

        $length = \strlen($digits);

        return $length >= 8 && $length <= 15 ? $digits : null;
    }

    /**
     * Normalises a number or throws.
     *
     * @throws InvalidArgumentException
     */
    public static function normalizeOrFail(string $raw, string $defaultCountryCode = self::DEFAULT_COUNTRY_CODE, string $field = 'phone'): string
    {
        $normalized = self::normalize($raw, $defaultCountryCode);
        if ($normalized === null) {
            throw new InvalidArgumentException(\sprintf('Invalid phone number in "%s": expected an international number (e.g. 243812345678) or a local number.', $field));
        }

        return $normalized;
    }

    /**
     * Normalises a number to E.164 with "+" ("0812345678" → "+243812345678"), the
     * format the SDK sends to the API, or returns null when it cannot be a valid
     * phone number.
     */
    public static function toE164(string $raw, string $defaultCountryCode = self::DEFAULT_COUNTRY_CODE): ?string
    {
        $normalized = self::normalize($raw, $defaultCountryCode);

        return $normalized === null ? null : '+'.$normalized;
    }

    /**
     * Normalises a number to E.164 with "+" or throws.
     *
     * @throws InvalidArgumentException
     */
    public static function toE164OrFail(string $raw, string $defaultCountryCode = self::DEFAULT_COUNTRY_CODE, string $field = 'phone'): string
    {
        return '+'.self::normalizeOrFail($raw, $defaultCountryCode, $field);
    }

    public static function isValid(string $raw, string $defaultCountryCode = self::DEFAULT_COUNTRY_CODE): bool
    {
        return self::normalize($raw, $defaultCountryCode) !== null;
    }

    /**
     * Masks a number for logs: "243812345678" → "24381*****78".
     */
    public static function mask(string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);
        $length = \strlen($digits);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        $visibleStart = min(5, max(1, $length - 7));

        return substr($digits, 0, $visibleStart).str_repeat('*', $length - $visibleStart - 2).substr($digits, -2);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function assertCountryCode(string $countryCode): void
    {
        if (preg_match('/^[1-9]\d{0,2}$/', $countryCode) !== 1) {
            throw new InvalidArgumentException('The default country code must be 1 to 3 digits without "+" (e.g. "243").');
        }
    }
}
