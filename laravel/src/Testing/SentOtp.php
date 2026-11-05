<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Testing;

/**
 * An OTP recorded by Labyrinthe::fake().
 */
final class SentOtp
{
    public function __construct(
        public readonly string $otpId,
        /** Normalised number, e.g. "243812345678". */
        public readonly string $phone,
        public readonly string $code,
        public readonly ?string $template,
        public readonly ?string $locale,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['otpId' => $this->otpId, 'phone' => $this->phone, 'code' => '[redacted]', 'template' => $this->template, 'locale' => $this->locale];
    }
}
