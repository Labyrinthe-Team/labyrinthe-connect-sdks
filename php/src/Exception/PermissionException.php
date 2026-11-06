<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

/**
 * 403: not allowed (FORBIDDEN: IP not allowed for this key; OTP_DISABLED_FOR_ACCOUNT: OTP disabled for the
 * account; ACCOUNT_SUSPENDED: see AccountSuspendedException).
 */
class PermissionException extends ApiException
{
}
