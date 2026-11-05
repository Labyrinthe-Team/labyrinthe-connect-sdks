<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

/**
 * 401 UNAUTHENTICATED: the API key is missing, invalid or revoked.
 */
final class AuthenticationException extends ApiException
{
}
