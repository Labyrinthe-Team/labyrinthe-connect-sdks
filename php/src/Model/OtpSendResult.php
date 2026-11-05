<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Model;

use Labyrinthe\Connect\Internal\Data;

/**
 * Result of an OTP send. Store `otpId` server-side (session, cache…) and pass
 * it to verify() with the code typed by the user. Never send it to a place
 * you do not control: it is not secret, but it identifies the attempt.
 */
final class OtpSendResult implements \JsonSerializable
{
    public function __construct(
        public readonly string $otpId,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly int $maxAttempts,
        #[\SensitiveParameter]
        private readonly ?string $testCode = null,
    ) {
    }

    /**
     * @internal
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            otpId: Data::string($data, 'otp_id'),
            expiresAt: Data::dateTime($data, 'expires_at'),
            maxAttempts: Data::int($data, 'max_attempts'),
            testCode: Data::nullableString($data, 'test_code'),
        );
    }

    /**
     * The code, ONLY with a test key (lsk_test_…): nothing is sent by SMS in
     * test mode, so the API returns the code to let you test verify(). Always
     * null with a live key. Never log it, never show it to end users.
     */
    public function testCode(): ?string
    {
        return $this->testCode;
    }

    /**
     * The test code is never serialised.
     *
     * @return array{otp_id: string, expires_at: string, max_attempts: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'otp_id' => $this->otpId,
            'expires_at' => $this->expiresAt->format(\DATE_ATOM),
            'max_attempts' => $this->maxAttempts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'otpId' => $this->otpId,
            'expiresAt' => $this->expiresAt,
            'maxAttempts' => $this->maxAttempts,
            'testCode' => $this->testCode === null ? null : '[redacted]',
        ];
    }
}
