<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

/**
 * Invalid input detected by the SDK before calling the API (nothing was sent).
 */
final class InvalidArgumentException extends \InvalidArgumentException implements LabyrintheException
{
}
