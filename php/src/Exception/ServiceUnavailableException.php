<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

/**
 * 5xx: server error or service temporarily disabled (e.g. 503 OTP_SERVICE_DISABLED).
 */
final class ServiceUnavailableException extends ApiException
{
}
