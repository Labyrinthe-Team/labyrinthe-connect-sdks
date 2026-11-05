<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * Keeps log records in memory.
 */
final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    public function dump(): string
    {
        return json_encode($this->records, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
