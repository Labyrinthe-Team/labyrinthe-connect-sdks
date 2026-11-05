<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Tests\Support;

use Labyrinthe\Connect\Http\Sleeper;

/**
 * Records requested waits instead of sleeping.
 */
final class RecordingSleeper implements Sleeper
{
    /** @var list<float> */
    public array $sleeps = [];

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
    }
}
