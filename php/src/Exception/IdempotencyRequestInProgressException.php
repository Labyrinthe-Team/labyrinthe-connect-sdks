<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

/**
 * 409 IDEMPOTENCY_REQUEST_IN_PROGRESS: the original request with this
 * Idempotency-Key is still being processed. Sending it again with the SAME
 * key is safe: the SDK already did so after Retry-After (POST /sms and
 * POST /otp/send) and only throws this when its retries are exhausted or the
 * wait exceeds maxRetryWait.
 */
final class IdempotencyRequestInProgressException extends ApiException
{
    /**
     * Seconds to wait before sending the request again with the same key
     * (Retry-After header or `details.retry_after_seconds`).
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfterSeconds();
    }
}
