<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

/**
 * A correctly signed webhook body is not a valid event envelope. Answer with HTTP 400.
 */
final class InvalidWebhookPayloadException extends \RuntimeException implements LabyrintheException
{
}
