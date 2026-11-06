<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Internal;

/**
 * When a request may be sent again automatically.
 *
 * With an Idempotency-Key, 409 IDEMPOTENCY_REQUEST_IN_PROGRESS is also retried
 * after Retry-After (same key), whatever the mode.
 *
 * 503 FEATURE_DISABLED / OTP_SERVICE_DISABLED (feature shut down by the
 * platform, request refused before any processing) are retried in every mode,
 * but only when the API gives a Retry-After no longer than maxRetryWait,
 * waiting exactly that delay; never with an exponential backoff.
 *
 * @internal
 */
enum RetryMode
{
    /**
     * Replaying cannot cause a side effect twice: GET, POST /sms/estimate,
     * POST /sms and POST /otp/send (both always sent with an Idempotency-Key).
     * Retried on network errors, 5xx (except FEATURE_DISABLED /
     * OTP_SERVICE_DISABLED, see above) and 429 TOO_MANY_REQUESTS.
     */
    case Safe;

    /**
     * POST /otp/verify: only retried on 429 TOO_MANY_REQUESTS and on
     * FEATURE_DISABLED with Retry-After (the request was refused before being
     * processed). Never on network errors or other 5xx (risk of a silently
     * consumed attempt).
     */
    case OnlyWhenThrottled;
}
