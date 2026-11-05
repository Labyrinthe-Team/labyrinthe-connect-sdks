<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Notifications;

/**
 * SMS built by a notification's toLabyrinthe() method.
 *
 *     return LabyrintheMessage::create("Your order {$this->order->number} is ready.")
 *         ->reference("order-{$this->order->id}");
 */
final class LabyrintheMessage
{
    /** @var string|list<string>|null */
    private string|array|null $to = null;

    private ?string $sender = null;

    private ?string $reference = null;

    private ?string $idempotencyKey = null;

    public function __construct(private string $content = '')
    {
    }

    public static function create(string $content = ''): self
    {
        return new self($content);
    }

    /** The SMS text (1 to 1600 characters; any non-ASCII character switches to UCS-2). */
    public function content(string $content): self
    {
        $this->content = $content;

        return $this;
    }

    /** An approved Sender ID (defaults to config('labyrinthe.sender'), then the platform's). */
    public function sender(?string $sender): self
    {
        $this->sender = $sender;

        return $this;
    }

    /** Your own reference (order number…), max 64 characters, filterable in the API. */
    public function reference(?string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    /**
     * Protects against double sends for 24 h. By default the channel derives
     * one from the notification id, so a retried queued notification is not
     * sent twice.
     */
    public function idempotencyKey(?string $key): self
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    /**
     * Overrides the notifiable's route (one number, or several: all or nothing).
     *
     * @param string|array<array-key, string> $to
     */
    public function to(string|array $to): self
    {
        $this->to = \is_string($to) ? $to : array_values($to);

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getSender(): ?string
    {
        return $this->sender;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    /**
     * @return string|list<string>|null
     */
    public function getTo(): string|array|null
    {
        return $this->to;
    }
}
