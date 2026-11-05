<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Webhook\Event;

use Labyrinthe\Connect\Enum\WebhookEvent;

/**
 * Envelope accessors shared by every event class.
 *
 * @internal
 */
trait EnvelopeTrait
{
    public function id(): string
    {
        return $this->id;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function eventType(): ?WebhookEvent
    {
        return WebhookEvent::tryFrom($this->type);
    }

    public function timestamp(): int
    {
        return $this->timestamp;
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
