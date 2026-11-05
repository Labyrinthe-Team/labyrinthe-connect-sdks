<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Enum;

enum SenderIdStatus: string
{
    case Pending = 'pending';
    case PendingProvider = 'pending_provider';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    /** Status not known by this SDK version. */
    case Unknown = 'unknown';

    public static function fromApi(string $value): self
    {
        return self::tryFrom($value) ?? self::Unknown;
    }
}
