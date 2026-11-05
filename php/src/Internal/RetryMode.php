<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Internal;

/**
 * When a request may be sent again automatically.
 *
 * @internal
 */
enum RetryMode
{
    /**
     * Replaying cannot cause a side effect twice: GET, POST /sms/estimate,
     * POST /sms with an Idempotency-Key. Retried on network errors, 5xx and
     * 429 TOO_MANY_REQUESTS.
     */
    case Safe;

    /**
     * POST /otp/send and /otp/verify: only retried on 429 TOO_MANY_REQUESTS
     * (the request was refused before being processed). Never on network
     * errors or 5xx (risk of a second SMS or of a consumed attempt).
     */
    case OnlyWhenThrottled;
}
