<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

/**
 * Marker implemented by every exception thrown by the SDK.
 * Catch it to handle any Labyrinthe Connect failure in one place.
 */
interface LabyrintheException extends \Throwable
{
}
