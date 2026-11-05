<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Model;

use Labyrinthe\Connect\Enum\SenderIdStatus;
use Labyrinthe\Connect\Internal\Data;

/**
 * An approved sender name, usable as `sender` when sending.
 */
final class SenderId
{
    /**
     * @param array<mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        /** The name shown on the handset, e.g. "MABOUTIQUE" (11 characters max). */
        public readonly string $value,
        public readonly SenderIdStatus $status,
        public readonly ?string $purpose,
        public readonly ?string $rejectionReason,
        public readonly bool $isDefault,
        public readonly ?\DateTimeImmutable $createdAt,
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
            id: Data::string($data, 'id'),
            value: Data::string($data, 'value'),
            status: SenderIdStatus::fromApi(Data::string($data, 'status')),
            purpose: Data::nullableString($data, 'purpose'),
            rejectionReason: Data::nullableString($data, 'rejection_reason'),
            isDefault: isset($data['is_default']) && Data::bool($data, 'is_default'),
            createdAt: Data::nullableDateTime($data, 'created_at'),
            raw: $data,
        );
    }

    /**
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
