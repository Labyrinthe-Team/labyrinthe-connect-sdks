<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Model;

use Labyrinthe\Connect\Enum\OtpFailureReason;
use Labyrinthe\Connect\Internal\Data;

/**
 * Result of an OTP verification. A wrong code is not an exception: check
 * isValid(), `reason` to tell the user what to do next and
 * `attemptsRemaining` to show how many tries are left.
 */
final class OtpVerifyResult
{
    public function __construct(
        public readonly bool $verified,
        /** Null when verified. */
        public readonly ?OtpFailureReason $reason,
        /**
         * Attempts left: an integer after `invalid_code`, 0 with
         * `max_attempts_reached`, null when verified, already used or expired.
         */
        public readonly ?int $attemptsRemaining = null,
    ) {
    }

    /**
     * @internal
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $verified = Data::bool($data, 'verified');
        $reason = Data::nullableString($data, 'reason');

        return new self(
            $verified,
            $verified ? null : OtpFailureReason::fromApi($reason ?? 'unknown'),
            Data::nullableInt($data, 'attempts_remaining'),
        );
    }

    public function isValid(): bool
    {
        return $this->verified;
    }
}
