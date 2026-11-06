<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Laravel\Events;

use Labyrinthe\Connect\Webhook\Event\OtpVerified as Payload;

/**
 * Webhook `otp.verified`: a code was verified (API or OTP console of the client area).
 *
 *     public function handle(OtpVerified $e): void { … $e->event->otpId, $e->event->phone … }
 */
final class OtpVerified
{
    public function __construct(public readonly Payload $event)
    {
    }
}
