<?php

namespace App\Tests\Service\Exchange;

use App\Service\Exchange\PriceValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PriceValueTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, ?float}>
     */
    public static function valueProvider(): iterable
    {
        yield 'numeric string' => ['65432.10', 65432.10];
        yield 'sub-cent string' => ['0.0000057690', 0.000005769];
        yield 'int' => [42, 42.0];
        yield 'float' => [1.5, 1.5];
        yield 'zero' => ['0', null];
        yield 'negative' => [-1.0, null];
        yield 'non-numeric string' => ['abc', null];
        yield 'empty string' => ['', null];
        yield 'infinite' => ['1e999', null];
        yield 'null' => [null, null];
        yield 'bool' => [true, null];
        yield 'array' => [['1'], null];
    }

    #[DataProvider('valueProvider')]
    public function testOnlyFinitePositiveNumbersPass(mixed $value, ?float $expected): void
    {
        self::assertSame($expected, PriceValue::positive($value));
    }
}
