<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Tests\Fixtures;

use Illuminate\Notifications\Notifiable;

/**
 * Notifiable with a `phone` attribute (fallback route).
 */
class User
{
    use Notifiable;

    public function __construct(public ?string $phone = null)
    {
    }

    public function getKey(): int
    {
        return 1;
    }
}
