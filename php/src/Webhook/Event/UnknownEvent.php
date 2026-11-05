<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Webhook\Event;

use Labyrinthe\Connect\Webhook\Event;

/**
 * An event type added to the API after this SDK version. Its signature was
 * verified; read its content with data(). Acknowledge it with a 2xx.
 */
final class UnknownEvent implements Event
{
    use EnvelopeTrait;

    /**
     * @param array<mixed> $data
     * @param array<mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly int $timestamp,
        private readonly array $data = [],
        private readonly array $raw = [],
    ) {
    }

    /**
     * The `data` member of the envelope.
     *
     * @return array<mixed>
     */
    public function data(): array
    {
        return $this->data;
    }
}
