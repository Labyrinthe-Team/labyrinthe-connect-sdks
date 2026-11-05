<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Internal;

use Labyrinthe\Connect\PhoneNumber;

/**
 * Masks secrets and personal data before they reach a log line or a dump.
 *
 * @internal
 */
final class Redactor
{
    /** Query parameters whose value is a phone number. */
    private const PHONE_PARAMS = ['recipient', 'phone'];

    private function __construct()
    {
    }

    /**
     * "lsk_test_abc…" → "lsk_test_****". Never reveals any character of the secret part.
     */
    public static function apiKey(string $apiKey): string
    {
        if (preg_match('/^(lsk_(?:live|test)_)/', $apiKey, $m) === 1) {
            return $m[1].'****';
        }

        return '****';
    }

    /**
     * Path + query string with phone numbers masked.
     */
    public static function path(string $path, string $query): string
    {
        if ($query === '') {
            return $path;
        }

        parse_str($query, $params);
        foreach (self::PHONE_PARAMS as $name) {
            if (isset($params[$name]) && \is_string($params[$name])) {
                $params[$name] = PhoneNumber::mask($params[$name]);
            }
        }

        return $path.'?'.str_replace('%2A', '*', http_build_query($params, '', '&', \PHP_QUERY_RFC3986));
    }
}
