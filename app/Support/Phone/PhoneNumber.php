<?php

namespace App\Support\Phone;

/**
 * Normalises phone numbers to E.164. Saudi local formats
 * (05XXXXXXXX, 5XXXXXXXX, 9665XXXXXXXX, 009665XXXXXXXX) become +9665XXXXXXXX.
 */
final class PhoneNumber
{
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $digits = preg_replace('/[^\d+]/', '', trim($input)) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }

        $saudi = match (true) {
            (bool) preg_match('/^05\d{8}$/', $digits) => '+966'.substr($digits, 1),
            (bool) preg_match('/^5\d{8}$/', $digits) => '+966'.$digits,
            (bool) preg_match('/^9665\d{8}$/', $digits) => '+'.$digits,
            default => null,
        };

        $normalized = $saudi ?? $digits;

        return preg_match('/^\+[1-9]\d{7,14}$/', $normalized) ? $normalized : null;
    }

    public static function mask(string $e164): string
    {
        return substr($e164, 0, 4).str_repeat('*', max(0, strlen($e164) - 6)).substr($e164, -2);
    }
}
