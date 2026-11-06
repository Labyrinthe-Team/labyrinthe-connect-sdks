<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Service;

use Labyrinthe\Connect\Exception\ApiException;
use Labyrinthe\Connect\Exception\ConnectionException;
use Labyrinthe\Connect\Internal\RetryMode;
use Labyrinthe\Connect\Internal\Transport;
use Labyrinthe\Connect\Model\Balance;

/**
 * Credit balance (`$client->balance`).
 */
final class BalanceService
{
    /**
     * @internal use $client->balance
     */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * @param string|null $requestId your X-Request-Id (1 to 128 characters among A-Z a-z 0-9 . _ : -)
     *
     * @throws ApiException
     * @throws ConnectionException
     */
    public function get(?string $requestId = null): Balance
    {
        return Balance::fromArray($this->transport->request('GET', '/balance', RetryMode::Safe, requestId: $requestId)->data());
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['transport' => $this->transport];
    }
}
