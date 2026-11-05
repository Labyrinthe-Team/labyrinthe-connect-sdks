<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Tests\Unit;

use Labyrinthe\Connect\Enum\SenderIdStatus;
use Labyrinthe\Connect\Tests\Support\TestCase;

final class AccountServicesTest extends TestCase
{
    public function test_balance(): void
    {
        $client = $this->client([self::json(200, ['data' => ['balance' => 1000, 'held' => 25]])]);

        $balance = $client->balance->get();

        self::assertSame(1000, $balance->balance);
        self::assertSame(25, $balance->held);
        self::assertSame('GET', $this->request()->getMethod());
    }

    public function test_sender_ids(): void
    {
        $client = $this->client([self::json(200, ['data' => [[
            'id' => '3c9a1f7e-2b4d-4e6a-8f0c-9d1b5e7a3c20',
            'value' => 'MABOUTIQUE',
            'status' => 'approved',
            'purpose' => 'Notifications',
            'rejection_reason' => null,
            'is_default' => true,
            'created_at' => '2026-10-01T08:00:00.000000Z',
        ]]])]);

        $senderIds = $client->senderIds->list();

        self::assertCount(1, $senderIds);
        self::assertSame('MABOUTIQUE', $senderIds[0]->value);
        self::assertSame(SenderIdStatus::Approved, $senderIds[0]->status);
        self::assertTrue($senderIds[0]->isDefault);
        self::assertSame('/api/public/v1/sender-ids', $this->request()->getUri()->getPath());
    }
}
