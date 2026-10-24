<?php

namespace App\Support;

/** Our languages (hy, ru, en) from what a phone or Telegram says, e.g. "hy-AM", "ru", "en-US,en;q=0.9". */
class Locales
{
    public static function match(?string $tag): ?string
    {
        $code = strtolower(substr(trim((string) $tag), 0, 2));

        return in_array($code, config('app.locales', ['hy', 'ru', 'en']), true) ? $code : null;
    }

    /** The first of our languages in an Accept-Language header (in the order the browser prefers them). */
    public static function fromHeader(?string $header): ?string
    {
        foreach (explode(',', (string) $header) as $part) {
            if ($code = self::match(explode(';', $part)[0])) {
                return $code;
            }
        }

        return null;
    }
}
