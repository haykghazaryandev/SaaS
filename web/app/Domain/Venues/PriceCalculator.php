<?php

namespace App\Domain\Venues;

use App\Models\Field;
use App\Models\OpeningHour;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Price of a booking: every quarter hour is charged by the price rule that matches its weekday and time
 * (e.g. day hours cheaper than evenings and weekends). The highest-priority rule wins.
 * A kind of court with prices by length (ping-pong: 15 min 1 200, 30 min 2 000, 1 h 5 000) is charged by those instead:
 * the longest lengths that fit first (75 min = 1 h + 15 min), a rest shorter than every length as the shortest one;
 * the band (day / evening / weekend) is the one at the start.
 */
class PriceCalculator
{
    /** $purpose training: the place's training price per hour for the whole time (no day / evening bands). */
    public function price(Field $field, CarbonImmutable $start, int $minutes, string $purpose = 'play'): int
    {
        if ($purpose === 'training') {
            $hourly = $field->loadMissing('venue')->venue->trainingPrice($field->size->value);
            if ($hourly !== null) {
                return (int) round($hourly * $minutes / 60);
            }
        }
        $lengths = $field->loadMissing('venue')->venue->lengthPrices($field->size->value);
        if ($lengths) {
            return $this->byLength($lengths, $start->setTimezone(LocalTime::tz()), $minutes);
        }
        $rules = $field->relationLoaded('priceRules') ? $field->priceRules : $field->priceRules()->get();
        $start = $start->setTimezone(LocalTime::tz());
        $sum = 0.0;
        for ($m = 0; $m < $minutes; $m += 15) {
            $t = $start->addMinutes($m);
            $sum += $this->hourlyAt($rules, $t) / 4;
        }

        return (int) round($sum);
    }

    private function byLength(array $lengths, CarbonImmutable $start, int $minutes): int
    {
        $band = self::band($start);
        $desc = array_reverse($lengths);
        $sum = 0;
        $left = $minutes;
        while ($left > 0) {
            $row = collect($desc)->first(fn ($r) => $r['min'] <= $left) ?? $lengths[0];
            $sum += $row[$band] ?? $row['day'];
            $left -= $row['min'];
        }

        return $sum;
    }

    /** day (weekdays before 18:00), evening (weekdays from 18:00) or weekend (Saturday, Sunday), at a local moment. */
    public static function band(CarbonImmutable $t): string
    {
        return in_array($t->dayOfWeek, [0, 6], true) ? 'weekend' : (LocalTime::hourOf($t) >= 18 ? 'evening' : 'day');
    }

    /** Price per hour that applies at a local moment. */
    public function hourlyAt($rules, CarbonImmutable $t): int
    {
        $weekday = $t->dayOfWeek;
        $hour = LocalTime::hourOf($t);
        foreach ($rules->sortByDesc('priority') as $rule) {
            if (in_array($weekday, $rule->weekdays, true)
                && $hour >= OpeningHour::toHours($rule->from_time)
                && $hour < OpeningHour::toHours($rule->to_time)) {
                return $rule->price_per_hour;
            }
        }

        return (int) ($rules->max('price_per_hour') ?? 0);
    }

    /** The lowest and highest hourly price of a field ("from 15 000 ֏ / hour"). */
    public function range(Field $field): array
    {
        $p = $field->priceRules->pluck('price_per_hour');

        return ['min' => (int) $p->min(), 'max' => (int) $p->max()];
    }
}
