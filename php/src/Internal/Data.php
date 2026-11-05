<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Internal;

use Labyrinthe\Connect\Exception\UnexpectedValueException;

/**
 * Typed readers over decoded JSON. Lenient on representation (numeric strings
 * are accepted for integers), strict on presence of required fields.
 *
 * @internal
 */
final class Data
{
    private function __construct()
    {
    }

    /**
     * @param array<mixed> $data
     */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (\is_string($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        throw self::invalid($key, 'string');
    }

    /**
     * @param array<mixed> $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        if (!isset($data[$key])) {
            return null;
        }

        return self::string($data, $key);
    }

    /**
     * @param array<mixed> $data
     */
    public static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (\is_int($value)) {
            return $value;
        }
        if (\is_float($value) && floor($value) === $value) {
            return (int) $value;
        }
        if (\is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw self::invalid($key, 'integer');
    }

    /**
     * @param array<mixed> $data
     */
    public static function nullableInt(array $data, string $key): ?int
    {
        if (!isset($data[$key])) {
            return null;
        }

        return self::int($data, $key);
    }

    /**
     * @param array<mixed> $data
     */
    public static function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (\is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1) {
            return $value === 1;
        }

        throw self::invalid($key, 'boolean');
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public static function array(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (\is_array($value)) {
            return $value;
        }

        throw self::invalid($key, 'object or array');
    }

    /**
     * Same as array() but an absent or null value gives an empty array.
     *
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public static function optionalArray(array $data, string $key): array
    {
        if (!isset($data[$key])) {
            return [];
        }

        return self::array($data, $key);
    }

    /**
     * A list of objects (each item is an array).
     *
     * @param array<mixed> $data
     *
     * @return list<array<mixed>>
     */
    public static function objects(array $data, string $key): array
    {
        $items = [];
        foreach (self::array($data, $key) as $item) {
            if (!\is_array($item)) {
                throw self::invalid($key.'[]', 'object');
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param array<mixed> $data
     */
    public static function dateTime(array $data, string $key): \DateTimeImmutable
    {
        $value = self::string($data, $key);

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw self::invalid($key, 'ISO 8601 date-time');
        }
    }

    /**
     * @param array<mixed> $data
     */
    public static function nullableDateTime(array $data, string $key): ?\DateTimeImmutable
    {
        if (!isset($data[$key])) {
            return null;
        }

        return self::dateTime($data, $key);
    }

    private static function invalid(string $key, string $expected): UnexpectedValueException
    {
        return new UnexpectedValueException(\sprintf('Unexpected API payload: field "%s" should be a %s.', $key, $expected));
    }
}
