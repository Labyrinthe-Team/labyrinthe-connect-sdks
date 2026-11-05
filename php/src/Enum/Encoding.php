<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Enum;

/**
 * Character encoding of a message.
 *
 * GSM-7 (ASCII only for now): 160 characters, 153 per segment beyond.
 * UCS-2 (any non-ASCII character, e.g. é, à, emoji): 70 characters, 67 per segment beyond.
 */
enum Encoding: string
{
    case Gsm7 = 'gsm7';
    case Ucs2 = 'ucs2';
}
