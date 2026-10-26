<?php

namespace App\Domain\Venues;

use App\Models\Field;
use App\Models\OpeningHour;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * Price of a booking: every quarter hour is charged by the price rule that matches its weekday and time
 * (e.g. day hours cheaper than evenings and weekends). The highest-priority rule wins.
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
        $rules = $field->relationLoaded('priceRules') ? $field->priceRules : $field->priceRules()->get();
        $start = $start->setTimezone(LocalTime::tz());
        $sum = 0.0;
        for ($m = 0; $m < $minutes; $m += 15) {
            $t = $start->addMinutes($m);
            $sum += $this->hourlyAt($rules, $t) / 4;
        }

        return (int) round($sum);
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
