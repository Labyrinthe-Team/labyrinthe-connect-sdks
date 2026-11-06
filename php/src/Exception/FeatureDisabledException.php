<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

/**
 * 503 FEATURE_DISABLED (or the older OTP_SERVICE_DISABLED on POST /otp/send):
 * a platform administrator temporarily shut down the whole API or one feature
 * (maintenance). Nothing was done: no SMS sent, no credit debited.
 *
 * The SDK only retries it when the API gives a Retry-After (automatic
 * re-enable scheduled) no longer than maxRetryWait. Otherwise do not retry in
 * a loop: queue the work and retry after getRetryAfter() / getUntil() when
 * known, or show the user a "temporarily unavailable" message.
 */
final class FeatureDisabledException extends ServiceUnavailableException
{
    /**
     * Feature requested by the call: "api", "api.sms_send", "api.otp_send",
     * "api.otp_verify", "otp"… (open list); null when the API does not say.
     */
    public function getFeature(): ?string
    {
        return $this->detailString('feature');
    }

    /**
     * Feature that is actually shut down: getFeature() itself or a parent
     * (e.g. "api" when the whole API is down); null when the API does not say.
     */
    public function getBlockedBy(): ?string
    {
        return $this->detailString('blocked_by');
    }

    /**
     * Message written by the administrator (French, for humans), null when
     * there is none. getMessage() repeats it or gives a generic text.
     */
    public function getAdminMessage(): ?string
    {
        return $this->detailString('message');
    }

    /**
     * Scheduled automatic re-enable, null when unknown (or unreadable).
     */
    public function getUntil(): ?\DateTimeImmutable
    {
        $until = $this->detailString('until');
        if ($until === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($until);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Seconds until the scheduled re-enable (Retry-After header), null when
     * no automatic re-enable is scheduled.
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfterSeconds();
    }

    private function detailString(string $key): ?string
    {
        $value = $this->getDetails()[$key] ?? null;

        return \is_string($value) && trim($value) !== '' ? $value : null;
    }
}
