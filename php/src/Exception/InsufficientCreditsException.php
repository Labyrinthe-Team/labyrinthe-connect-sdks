<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

/**
 * 409 INSUFFICIENT_CREDITS: the balance does not cover the whole send.
 * Nothing was sent.
 */
final class InsufficientCreditsException extends ApiException
{
    /** Credits available on the account. */
    public function getAvailable(): ?int
    {
        return $this->detailInt('available');
    }

    /** Credits needed for this send. */
    public function getRequired(): ?int
    {
        return $this->detailInt('required');
    }
}
