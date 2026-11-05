<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Service;

use Labyrinthe\Connect\Exception\ApiException;
use Labyrinthe\Connect\Exception\ConnectionException;
use Labyrinthe\Connect\Internal\RetryMode;
use Labyrinthe\Connect\Internal\Transport;
use Labyrinthe\Connect\Model\SenderId;

/**
 * Approved Sender IDs (`$client->senderIds`).
 */
final class SenderIdService
{
    /**
     * @internal use $client->senderIds
     */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Sender IDs with the `approved` status, usable as `sender`.
     *
     * @return list<SenderId>
     *
     * @throws ApiException
     * @throws ConnectionException
     */
    public function list(): array
    {
        $response = $this->transport->request('GET', '/sender-ids', RetryMode::Safe);

        return array_map(static fn (array $s): SenderId => SenderId::fromArray($s), $response->dataList());
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['transport' => $this->transport];
    }
}
