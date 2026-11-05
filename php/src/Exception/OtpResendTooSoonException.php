<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

/**
 * 429 OTP_RESEND_TOO_SOON: a code was sent to this number too recently.
 * Never retried by the SDK; show a countdown of getRetryAfter() seconds.
 */
final class OtpResendTooSoonException extends RateLimitException
{
}
