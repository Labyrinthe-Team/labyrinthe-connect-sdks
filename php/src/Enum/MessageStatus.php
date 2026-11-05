<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Enum;

/**
 * Lifecycle of a message.
 *
 * `Unknown` is never sent by the API: the SDK uses it when the API returns a
 * status this version does not know yet (read the raw value with `raw()`).
 */
enum MessageStatus: string
{
    /** Queued at Labyrinthe Connect. */
    case Queued = 'queued';
    /** Handed over to the SMS provider. */
    case Submitted = 'submitted';
    /** Accepted by the mobile operator. */
    case Sent = 'sent';
    /** Delivered to the handset (final). */
    case Delivered = 'delivered';
    /** Failed (final). */
    case Failed = 'failed';
    /** Rejected by the provider or the operator (final). */
    case Rejected = 'rejected';
    /** Expired before delivery (final). */
    case Expired = 'expired';
    /** Cannot be delivered to this number (final). */
    case Undeliverable = 'undeliverable';
    /** Status not known by this SDK version. */
    case Unknown = 'unknown';

    public static function fromApi(string $value): self
    {
        return self::tryFrom($value) ?? self::Unknown;
    }

    /** True when the status will not change any more. */
    public function isFinal(): bool
    {
        return $this->isSuccessful() || $this->isFailure();
    }

    public function isSuccessful(): bool
    {
        return $this === self::Delivered;
    }

    public function isFailure(): bool
    {
        return \in_array($this, [self::Failed, self::Rejected, self::Expired, self::Undeliverable], true);
    }

    public function isPending(): bool
    {
        return \in_array($this, [self::Queued, self::Submitted, self::Sent], true);
    }
}
