<?php

namespace App\Support\Money;

/**
 * Integer minor-unit arithmetic (halalas). Never use floats for money.
 */
final class Money
{
    /**
     * Basis-point share, rounded half up. 2000 bps of 15001 = 3000.
     */
    public static function bps(int $amount, int $bps): int
    {
        return intdiv($amount * $bps + 5000, 10000);
    }

    /**
     * Percentage share, rounded half up.
     */
    public static function percent(int $amount, int $percent): int
    {
        return intdiv($amount * $percent + 50, 100);
    }

    /**
     * Splits a gross amount into [doctor share, platform commission].
     * The two parts always add up to the gross amount exactly.
     *
     * @return array{0: int, 1: int}
     */
    public static function split(int $gross, int $commissionBps): array
    {
        $commission = self::bps($gross, $commissionBps);

        return [$gross - $commission, $commission];
    }

    public static function format(int $minor, string $currency = 'SAR'): string
    {
        return number_format($minor / 100, 2, '.', ',').' '.$currency;
    }
}
