<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Model;

use Labyrinthe\Connect\Enum\Encoding;
use Labyrinthe\Connect\Enum\MessageStatus;
use Labyrinthe\Connect\Internal\Data;

/**
 * An SMS, one per recipient. The text is deliberately not returned by the
 * API (it may contain an OTP code).
 */
final class Message
{
    /**
     * @param array<mixed> $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $reference,
        public readonly string $recipient,
        public readonly ?string $sender,
        public readonly ?Encoding $encoding,
        public readonly MessageStatus $status,
        public readonly int $segments,
        public readonly int $creditsCharged,
        public readonly ?string $campaignId,
        public readonly ?string $providerMessageId,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly ?\DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $submittedAt,
        public readonly ?\DateTimeImmutable $deliveredAt,
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
        $encoding = Data::nullableString($data, 'encoding');

        return new self(
            id: Data::string($data, 'id'),
            reference: Data::nullableString($data, 'reference'),
            recipient: Data::string($data, 'recipient'),
            sender: Data::nullableString($data, 'sender'),
            encoding: $encoding !== null ? Encoding::tryFrom($encoding) : null,
            status: MessageStatus::fromApi(Data::string($data, 'status')),
            segments: Data::nullableInt($data, 'segments') ?? 0,
            creditsCharged: Data::nullableInt($data, 'credits_charged') ?? 0,
            campaignId: Data::nullableString($data, 'campaign_id'),
            providerMessageId: Data::nullableString($data, 'provider_message_id'),
            errorCode: Data::nullableString($data, 'error_code'),
            errorMessage: Data::nullableString($data, 'error_message'),
            createdAt: Data::nullableDateTime($data, 'created_at'),
            submittedAt: Data::nullableDateTime($data, 'submitted_at'),
            deliveredAt: Data::nullableDateTime($data, 'delivered_at'),
            raw: $data,
        );
    }

    /**
     * Decoded JSON as returned by the API (low-level access).
     *
     * @return array<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
