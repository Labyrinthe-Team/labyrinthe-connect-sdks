<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

/**
 * The API returned a payload the SDK cannot read (should not happen: please report it).
 */
final class UnexpectedValueException extends \UnexpectedValueException implements LabyrintheException
{
}
