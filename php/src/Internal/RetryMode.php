<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Internal;

/**
 * When a request may be sent again automatically.
 *
 * With an Idempotency-Key, 409 IDEMPOTENCY_REQUEST_IN_PROGRESS is also retried
 * after Retry-After (same key), whatever the mode.
 *
 * @internal
 */
enum RetryMode
{
    /**
     * Replaying cannot cause a side effect twice: GET, POST /sms/estimate,
     * POST /sms and POST /otp/send (both always sent with an Idempotency-Key).
     * Retried on network errors, 5xx and 429 TOO_MANY_REQUESTS.
     */
    case Safe;

    /**
     * POST /otp/verify: only retried on 429 TOO_MANY_REQUESTS (the request was
     * refused before being processed). Never on network errors or 5xx (risk of
     * a silently consumed attempt).
     */
    case OnlyWhenThrottled;
}
