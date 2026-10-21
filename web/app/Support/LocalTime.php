<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** Times are stored in UTC and shown in Yerevan time (config app.display_timezone). */
final class LocalTime
{
    public static function tz(): string
    {
        return config('app.display_timezone', 'Asia/Yerevan');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::tz());
    }

    /** A local date "2026-10-21" -> start of that day in local time. */
    public static function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::tz())->startOfDay();
    }

    /** Local day + decimal hour (21.5) -> local time 21:30 on that day (24.0 = next midnight). */
    public static function at(CarbonImmutable $day, float $hour): CarbonImmutable
    {
        return $day->startOfDay()->addMinutes((int) round($hour * 60));
    }

    /** Local time -> decimal hours from the start of its day. */
    public static function hourOf(CarbonImmutable $t, ?CarbonImmutable $day = null): float
    {
        $day ??= $t->setTimezone(self::tz())->startOfDay();

        return $day->diffInMinutes($t->setTimezone(self::tz())) / 60;
    }
}
