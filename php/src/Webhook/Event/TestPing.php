<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Webhook\Event;

use Labyrinthe\Connect\Webhook\Event;

/**
 * `test.ping`: sent by the "Test" button of the client area (Developers > Webhooks).
 */
final class TestPing implements Event
{
    use EnvelopeTrait;

    /**
     * @param array<mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly int $timestamp,
        private readonly array $raw = [],
    ) {
    }
}
