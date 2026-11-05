<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

/**
 * The API could not be reached (DNS, TLS, connection refused, timeout…).
 *
 * For POST /otp/send and /otp/verify the request may or may not have been
 * processed: the SDK never retries them on a network error (no double SMS,
 * no silently consumed attempt).
 */
final class ConnectionException extends \RuntimeException implements LabyrintheException
{
    public function __construct(string $message, private readonly bool $timeout = false)
    {
        parent::__construct($message);
    }

    public function isTimeout(): bool
    {
        return $this->timeout;
    }

    public function getErrorCode(): string
    {
        return $this->timeout ? 'TIMEOUT' : 'CONNECTION_ERROR';
    }
}
