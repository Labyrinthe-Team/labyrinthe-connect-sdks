<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Webhook\Event;

use Labyrinthe\Connect\Webhook\Event;

/**
 * `balance.low`: the balance went below the account's low-balance threshold.
 * Sent once; sent again only after going back above it.
 */
final class BalanceLow implements Event
{
    use EnvelopeTrait;

    /**
     * @param array<mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly int $timestamp,
        /** Balance after the operation that crossed the threshold. */
        public readonly int $balance,
        public readonly int $threshold,
        private readonly array $raw = [],
    ) {
    }
}
