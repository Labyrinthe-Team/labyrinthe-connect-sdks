<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use Labyrinthe\Connect\PhoneNumber as Phone;

/**
 * A phone number the API accepts: international (+243…, 00243…, 243…) or
 * local with the configured default country code (0812…, 812…). Light,
 * offline check (8 to 15 digits): international numbers are never blocked.
 *
 *     'phone' => ['required', new PhoneNumber()],
 *
 * Normalise before storing: Labyrinthe\Connect\PhoneNumber::normalize($phone).
 */
final class PhoneNumber implements ValidationRule
{
    public function __construct(private readonly ?string $defaultCountryCode = null)
    {
    }

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if ((!\is_string($value) && !\is_int($value)) || Phone::normalize((string) $value, $this->countryCode()) === null) {
            $fail('labyrinthe::validation.phone')->translate();
        }
    }

    private function countryCode(): string
    {
        if ($this->defaultCountryCode !== null) {
            return $this->defaultCountryCode;
        }

        $configured = config('labyrinthe.default_country_code', Phone::DEFAULT_COUNTRY_CODE);

        return \is_scalar($configured) && (string) $configured !== '' ? (string) $configured : Phone::DEFAULT_COUNTRY_CODE;
    }
}
