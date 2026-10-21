<?php

namespace App\Support;

/** Armenian mobile numbers: "+374 91 234567", "091234567", "91 23 45 67" -> "+37491234567". */
final class Phone
{
    public static function normalize(?string $input): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $input);
        if (str_starts_with($digits, '374')) {
            $digits = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === 8 ? '+374'.$digits : null;
    }

    /** "+37491234567" -> "+374 91 234 567" */
    public static function format(?string $phone): string
    {
        if (! $phone || ! preg_match('/^\+374(\d{2})(\d{3})(\d{3})$/', $phone, $m)) {
            return (string) $phone;
        }

        return "+374 {$m[1]} {$m[2]} {$m[3]}";
    }

    /** "+374 91 *** 567" for people who should not see the full number */
    public static function mask(?string $phone): string
    {
        $f = self::format($phone);

        return strlen($f) > 10 ? substr($f, 0, 8).'***'.substr($f, -4) : $f;
    }
}
