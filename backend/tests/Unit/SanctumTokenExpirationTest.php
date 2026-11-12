<?php

namespace Tests\Unit;

use App\Support\SanctumTokenExpiration;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SanctumTokenExpirationTest extends TestCase
{
    #[DataProvider('validMinutes')]
    public function test_accepts_positive_integer_minutes(mixed $value, int $expected): void
    {
        $this->assertSame($expected, SanctumTokenExpiration::minutes($value));
    }

    public static function validMinutes(): array
    {
        return [[1, 1], ['1', 1], [1440, 1440], ['1440', 1440]];
    }

    #[DataProvider('invalidMinutes')]
    public function test_rejects_invalid_configuration(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        SanctumTokenExpiration::minutes($value);
    }

    public static function invalidMinutes(): array
    {
        return [[0], [-1], ['0'], ['-1'], ['01'], [' 1'], ['1.5'], ['1x'], [''], [null], [false], [true], [1.0], [[]], ['999999999999999999999999999999']];
    }
}
