<?php

namespace App\Filament\Support;

use App\Support\LocalTime;
use Carbon\CarbonInterface;

/** Select options and small formatters shared by the admin resources. */
final class Options
{
    public const DISTRICTS = ['ajapnyak', 'arabkir', 'avan', 'davtashen', 'erebuni', 'kanaker_zeytun', 'kentron', 'malatia', 'nornork', 'nork_marash', 'nubarashen', 'shengavit'];

    public const AMENITIES = ['parking', 'showers', 'lockers', 'cafe', 'lights', 'bibs', 'stands', 'balls', 'wifi'];

    public static function districts(): array
    {
        return collect(self::DISTRICTS)->mapWithKeys(fn ($d) => [$d => __('admin.district.'.$d)])->all();
    }

    public static function amenities(): array
    {
        return collect(self::AMENITIES)->mapWithKeys(fn ($a) => [$a => __('admin.amenity.'.$a)])->all();
    }

    /** 0 = Sunday … 6 = Saturday (as in opening_hours / price_rules); listed Monday first. */
    public static function weekdays(): array
    {
        return collect([1, 2, 3, 4, 5, 6, 0])->mapWithKeys(fn ($d) => [$d => __('admin.weekday.'.$d)])->all();
    }

    public static function venueStatuses(): array
    {
        return ['active' => __('admin.venue.status.active'), 'paused' => __('admin.venue.status.paused'), 'draft' => __('admin.venue.status.draft')];
    }

    public static function bookingStatuses(): array
    {
        return collect(['pending', 'accepted', 'declined', 'cancelled', 'expired', 'completed', 'no_show'])
            ->mapWithKeys(fn ($s) => [$s => __('telegram.status.'.$s)])->all();
    }

    public static function fieldSizes(): array
    {
        return ['small' => __('telegram.field.small'), 'big' => __('telegram.field.big')];
    }

    /** "18:00:00" → "18:00" */
    public static function hhmm(?string $t): ?string
    {
        return $t ? substr($t, 0, 5) : $t;
    }

    /** UTC timestamp → "Wed 21.10.2026 21:00" in Yerevan */
    public static function local(?CarbonInterface $t, string $format = 'D d.m.Y H:i'): ?string
    {
        return $t?->copy()->setTimezone(LocalTime::tz())->format($format);
    }

    public static function amd(?int $n): string
    {
        return number_format((int) $n, 0, '.', ' ').' ֏';
    }

    /** Time like 08:00 … 24:00 */
    public const TIME_REGEX = '/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/';
}
