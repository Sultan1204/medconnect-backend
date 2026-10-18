<?php

namespace Tests\Unit\Support;

use App\Support\Phone\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    /** @return array<string, array{string, string|null}> */
    public static function numbers(): array
    {
        return [
            'saudi local' => ['0512345678', '+966512345678'],
            'saudi without zero' => ['512345678', '+966512345678'],
            'saudi with country code' => ['966512345678', '+966512345678'],
            'saudi with 00 prefix' => ['00966512345678', '+966512345678'],
            'formatted e164' => ['+966 51 234 5678', '+966512345678'],
            'international' => ['+447911123456', '+447911123456'],
            'too short' => ['12345', null],
            'letters' => ['abc', null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_normalizes_to_e164(string $input, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($input));
    }

    public function test_masks_numbers_for_logs(): void
    {
        $this->assertSame('+966*******78', PhoneNumber::mask('+966512345678'));
    }
}
