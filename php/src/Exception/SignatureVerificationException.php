<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

/**
 * A webhook failed verification: missing or wrong signature, or a timestamp
 * outside the tolerance (possible replay). Answer with HTTP 400.
 */
final class SignatureVerificationException extends \RuntimeException implements LabyrintheException
{
}
