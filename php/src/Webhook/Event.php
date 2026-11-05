<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Webhook;

use Labyrinthe\Connect\Enum\WebhookEvent;

/**
 * A verified webhook event. Use `instanceof` on the concrete classes of
 * `Labyrinthe\Connect\Webhook\Event\*` to handle each type.
 */
interface Event
{
    /** Event identifier, identical across delivery attempts: deduplicate on it. */
    public function id(): string;

    /** Event type as sent by the API, e.g. "message.status_changed". */
    public function type(): string;

    /** The known type, or null for a type this SDK version does not know. */
    public function eventType(): ?WebhookEvent;

    /** Unix time (seconds) of this delivery attempt. */
    public function timestamp(): int;

    /**
     * Decoded envelope `{ id, event, data, timestamp }` (low-level access).
     *
     * @return array<mixed>
     */
    public function raw(): array;
}
