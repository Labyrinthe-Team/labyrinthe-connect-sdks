<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Model;

use Labyrinthe\Connect\Internal\Data;

/**
 * SMS credit balance (1 credit = 1 SMS segment).
 */
final class Balance
{
    /**
     * @param array<mixed> $raw
     */
    public function __construct(
        /** Credits available. */
        public readonly int $balance,
        /** Credits held by campaigns in progress. */
        public readonly int $held,
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
        return new self(Data::int($data, 'balance'), Data::int($data, 'held'), $data);
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
