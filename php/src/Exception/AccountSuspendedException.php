<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

/**
 * 403 ACCOUNT_SUSPENDED: the account was suspended by the platform. The whole
 * API is closed until it is reactivated (contact support with getRequestId()).
 * Never retried. Extends PermissionException, so existing 403 handlers still
 * catch it.
 */
final class AccountSuspendedException extends PermissionException
{
}
