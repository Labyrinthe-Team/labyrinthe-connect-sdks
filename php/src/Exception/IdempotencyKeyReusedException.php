<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

/**
 * 409 IDEMPOTENCY_KEY_REUSED: the Idempotency-Key was already used for a different request (recipients, body, sender or reference differ).
 */
final class IdempotencyKeyReusedException extends ApiException
{
}
