<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Internal;

/**
 * Decoded successful API response.
 *
 * @internal
 */
final class Response
{
    /**
     * @param array<mixed>          $body    decoded JSON
     * @param array<string, string> $headers lower-case names
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly array $headers,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * The `data` member of the envelope, as an object.
     *
     * @return array<mixed>
     */
    public function data(): array
    {
        return Data::array($this->body, 'data');
    }

    /**
     * The `data` member of the envelope, as a list of objects.
     *
     * @return list<array<mixed>>
     */
    public function dataList(): array
    {
        return Data::objects($this->body, 'data');
    }
}
