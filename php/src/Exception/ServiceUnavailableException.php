<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

/**
 * 5xx: server error or service temporarily unavailable. A feature shut down
 * by the platform (503 FEATURE_DISABLED / OTP_SERVICE_DISABLED) is thrown as
 * the FeatureDisabledException subclass.
 */
class ServiceUnavailableException extends ApiException
{
}
