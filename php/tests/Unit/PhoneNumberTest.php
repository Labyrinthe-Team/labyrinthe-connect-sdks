<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Tests\Unit;

use Labyrinthe\Connect\Exception\InvalidArgumentException;
use Labyrinthe\Connect\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneNumberTest extends TestCase
{
    /**
     * Same cases as the backend (App\Domain\Gateway\Support\PhoneNumber).
     *
     * @return iterable<string, array{string, string|null}>
     */
    public static function numbers(): iterable
    {
        yield 'international without plus' => ['243812345678', '243812345678'];
        yield 'plus' => ['+243812345678', '243812345678'];
        yield 'plus with spaces' => ['+243 81 234 5678', '243812345678'];
        yield 'double zero' => ['00243812345678', '243812345678'];
        yield 'local with trunk zero' => ['0812345678', '243812345678'];
        yield 'local without trunk zero' => ['812345678', '243812345678'];
        yield 'local with separators' => ['081-234-56.78', '243812345678'];
        yield 'other country with plus' => ['+33 6 12 34 56 78', '33612345678'];
        yield 'other country with 00' => ['0033612345678', '33612345678'];
        yield 'us number' => ['+1 415 555 2671', '14155552671'];
        yield 'empty' => ['', null];
        yield 'blank' => ['   ', null];
        yield 'letters' => ['abc', null];
        yield 'too short' => ['+1234', null];
        yield 'too long' => ['+1234567890123456', null];
    }

    #[DataProvider('numbers')]
    public function test_normalize(string $input, ?string $expected): void
    {
        self::assertSame($expected, PhoneNumber::normalize($input));
        self::assertSame($expected !== null, PhoneNumber::isValid($input));
    }

    public function test_the_default_country_code_is_configurable(): void
    {
        self::assertSame('24261234567', PhoneNumber::normalize('061234567', '242'));
        self::assertSame('243812345678', PhoneNumber::normalize('+243812345678', '242'), 'An explicit prefix wins.');
    }

    public function test_normalize_or_fail(): void
    {
        self::assertSame('243812345678', PhoneNumber::normalizeOrFail('0812345678'));

        $this->expectException(InvalidArgumentException::class);
        PhoneNumber::normalizeOrFail('nope');
    }

    public function test_invalid_country_codes_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PhoneNumber::normalize('0812345678', '+243');
    }

    public function test_mask_keeps_only_the_country_prefix_and_last_digits(): void
    {
        self::assertSame('24381*****78', PhoneNumber::mask('243812345678'));
        self::assertSame('24381*****78', PhoneNumber::mask('+243 812 345 678'));
        self::assertSame('3361*****78', PhoneNumber::mask('33612345678'));
        self::assertSame('****', PhoneNumber::mask('1234'));
    }
}
