<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Events;

use Labyrinthe\Connect\Webhook\Event\OtpFailed as Payload;

/**
 * Webhook `otp.failed`: attempts exhausted (`status` failed) or code found
 * expired (`status` expired). Sent once per verification.
 */
final class OtpFailed
{
    public function __construct(public readonly Payload $event)
    {
    }
}
