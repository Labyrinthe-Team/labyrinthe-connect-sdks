<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Model;

use Labyrinthe\Connect\Enum\OtpFailureReason;
use Labyrinthe\Connect\Internal\Data;

/**
 * Result of an OTP verification. A wrong code is not an exception: check
 * isValid(), and `reason` to tell the user what to do next.
 */
final class OtpVerifyResult
{
    public function __construct(
        public readonly bool $verified,
        /** Null when verified. */
        public readonly ?OtpFailureReason $reason,
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
        );
    }

    public function isValid(): bool
    {
        return $this->verified;
    }
}
