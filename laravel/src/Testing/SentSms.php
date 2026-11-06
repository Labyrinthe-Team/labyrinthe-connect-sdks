<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Testing;

use Labyrinthe\Connect\PhoneNumber;

/**
 * An SMS recorded by Labyrinthe::fake().
 */
final class SentSms
{
    /**
     * @param list<string> $recipients normalised numbers (e.g. "243812345678")
     * @param list<string> $messageIds
     */
    public function __construct(
        public readonly array $recipients,
        public readonly string $body,
        public readonly ?string $sender,
        public readonly ?string $reference,
        public readonly ?string $idempotencyKey,
        public readonly array $messageIds,
        private readonly string $defaultCountryCode = PhoneNumber::DEFAULT_COUNTRY_CODE,
        /** True when sent with strip_accents (`body` is the text as given, before transliteration). */
        public readonly bool $stripAccents = false,
    ) {
    }

    /** True when this send targets the number (any format). */
    public function hasRecipient(string $phone): bool
    {
        $normalized = PhoneNumber::normalize($phone, $this->defaultCountryCode) ?? $phone;

        return \in_array($normalized, $this->recipients, true);
    }
}
