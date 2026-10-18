<?php

namespace Tests\Unit\Support;

use App\Support\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /** @return array<string, array{int, int, int, int}> */
    public static function splits(): array
    {
        return [
            'round amount' => [15000, 2000, 12000, 3000],
            'rounds half up' => [15001, 2000, 12001, 3000],
            'tiny amount' => [1, 2000, 1, 0],
            'zero commission' => [9999, 0, 9999, 0],
            'full commission' => [500, 10000, 0, 500],
            'odd rate' => [33333, 1750, 27500, 5833],
        ];
    }

    #[DataProvider('splits')]
    public function test_split_always_adds_up_to_the_gross_amount(int $gross, int $bps, int $doctor, int $platform): void
    {
        [$d, $p] = Money::split($gross, $bps);

        $this->assertSame([$doctor, $platform], [$d, $p]);
        $this->assertSame($gross, $d + $p);
    }

    public function test_percent_rounds_half_up(): void
    {
        $this->assertSame(7500, Money::percent(15000, 50));
        $this->assertSame(1, Money::percent(1, 50));
        $this->assertSame(0, Money::percent(1, 49));
    }

    public function test_format(): void
    {
        $this->assertSame('1,234.50 SAR', Money::format(123450));
    }
}
