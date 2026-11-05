<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Tests\Fixtures;

use Illuminate\Notifications\Notification;

/**
 * Notifiable with an explicit route method.
 */
final class UserWithRoute extends User
{
    /**
     * @param string|list<string> $route
     */
    public function __construct(private readonly string|array $route)
    {
        parent::__construct('0800000000');
    }

    /**
     * @return string|list<string>
     */
    public function routeNotificationForLabyrinthe(?Notification $notification = null): string|array
    {
        return $this->route;
    }
}
