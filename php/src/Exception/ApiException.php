<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

/**
 * The API answered with an error (`{ "error": { "code", "message", "details" } }`).
 *
 * Subclasses exist for the codes an integration usually handles; any other
 * code (including codes added to the API later) is thrown as a plain
 * ApiException: always switch on getErrorCode(), never on the message
 * (which is written in French, for humans).
 */
class ApiException extends \RuntimeException implements LabyrintheException
{
    /**
     * @param array<mixed>          $details Error details, depend on the code
     * @param array<string, string> $headers Response headers (lower-case names)
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $statusCode,
        private readonly array $details = [],
        private readonly array $headers = [],
    ) {
        parent::__construct($message, $statusCode);
    }

    /** Stable machine-readable code, e.g. "INSUFFICIENT_CREDITS". */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** HTTP status code of the response. */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /** Request identifier for support (X-Request-Id), when the API provides one. */
    public function getRequestId(): ?string
    {
        return $this->getHeader('x-request-id');
    }

    /** A response header (case-insensitive name). */
    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    protected function detailInt(string $key): ?int
    {
        $value = $this->details[$key] ?? null;
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
