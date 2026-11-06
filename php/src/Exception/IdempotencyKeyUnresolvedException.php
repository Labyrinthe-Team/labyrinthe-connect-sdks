<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

/**
 * 409 IDEMPOTENCY_KEY_UNRESOLVED (POST /sms and POST /otp/send): the original
 * request with this Idempotency-Key failed unexpectedly and its outcome is
 * unknown: an SMS may have been sent. The SDK never sends it again.
 *
 * Check first (GET /sms?reference=…, or ask the user whether the code
 * arrived), then send again with a NEW Idempotency-Key if needed.
 */
final class IdempotencyKeyUnresolvedException extends ApiException
{
}
