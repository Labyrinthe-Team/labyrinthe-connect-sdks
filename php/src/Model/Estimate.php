<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Model;

use Labyrinthe\Connect\Enum\Encoding;
use Labyrinthe\Connect\Internal\Data;

/**
 * Cost of a send, computed by the API without sending anything.
 */
final class Estimate
{
    /**
     * @param array<mixed> $raw
     */
    public function __construct(
        public readonly Encoding $encoding,
        public readonly int $characters,
        public readonly int $segmentsPerMessage,
        /** Valid recipients only (invalid numbers are ignored, not rejected). */
        public readonly int $recipientsCount,
        public readonly int $totalCredits,
        /** Balance after the send (negative when insufficient); null if the account never had credits. */
        public readonly ?int $balanceAfter,
        private readonly array $raw = [],
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
            encoding: Encoding::tryFrom(Data::string($data, 'encoding')) ?? Encoding::Ucs2,
            characters: Data::int($data, 'characters'),
            segmentsPerMessage: Data::int($data, 'segments_per_message'),
            recipientsCount: Data::int($data, 'recipients_count'),
            totalCredits: Data::int($data, 'total_credits'),
            balanceAfter: Data::nullableInt($data, 'balance_after'),
            raw: $data,
        );
    }

    /** True when the current balance covers the send. */
    public function isAffordable(): bool
    {
        return $this->balanceAfter !== null && $this->balanceAfter >= 0;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
