<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

use Labyrinthe\Connect\Enum\OtpRateLimitScope;

/**
 * 429 OTP_RATE_LIMITED: hourly cap reached for this phone number or for the
 * whole account. Never retried by the SDK.
 */
final class OtpRateLimitedException extends RateLimitException
{
    public function getScope(): OtpRateLimitScope
    {
        $scope = $this->getDetails()['scope'] ?? null;

        return \is_string($scope) ? OtpRateLimitScope::fromApi($scope) : OtpRateLimitScope::Unknown;
    }

    /** The hourly cap that was reached. */
    public function getLimit(): ?int
    {
        return $this->detailInt('limit');
    }
}
