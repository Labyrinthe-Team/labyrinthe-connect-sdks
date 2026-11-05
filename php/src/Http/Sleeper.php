<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Http;

/**
 * Waits between two retry attempts. Inject your own implementation to make
 * retries instantaneous in tests, or to integrate with an event loop.
 */
interface Sleeper
{
    public function sleep(float $seconds): void;
}
