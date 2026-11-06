<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

/**
 * 429: too many requests. The SDK already retried TOO_MANY_REQUESTS when it
 * was safe to do so; when you get this exception, wait getRetryAfter() seconds.
 */
class RateLimitException extends ApiException
{
    /**
     * Seconds to wait before retrying, from the Retry-After header or
     * `details.retry_after_seconds`; null when the API does not say.
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfterSeconds();
    }
}
