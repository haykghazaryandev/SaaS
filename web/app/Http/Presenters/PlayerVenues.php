<?php

namespace App\Http\Presenters;

use App\Domain\Venues\Availability;
use App\Domain\Venues\PriceCalculator;
use App\Models\Booking;
use App\Models\Field;
use App\Models\OpeningHour;
use App\Models\Venue;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Turns places, fields and bookings into the arrays the player pages need.
 * No business rules here: free times come from Availability, prices from PriceCalculator.
 */
class PlayerVenues
{
    public function __construct(private Availability $availability, private PriceCalculator $prices) {}

    /** The bookable days of the strip (today + 13), in local time. */
    public function days(int $count = 14): array
    {
        $today = LocalTime::now()->startOfDay();

        return collect(range(0, $count - 1))->map(fn ($k) => $this->dayInfo($today->addDays($k), $k))->all();
    }

    public function dayInfo(CarbonImmutable $d, int $k): array
    {
        return ['date' => $d->toDateString(), 'k' => $k, 'wd' => $d->dayOfWeek, 'day' => $d->day, 'month' => $d->month - 1, 'weekend' => in_array($d->dayOfWeek, [0, 6], true)];
    }

    /** A date from the query (Y-m-d) inside the strip, or today. */
    public function pickDay(?string $date, int $count = 14): CarbonImmutable
    {
        $today = LocalTime::now()->startOfDay();
        if ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $d = LocalTime::day($date);
            if ($d->greaterThanOrEqualTo($today) && $d->lessThan($today->addDays($count))) {
                return $d;
            }
        }

        return $today;
    }

    public function field(Field $f): array
    {
        $range = $this->prices->range($f);

        return [
            'id' => $f->id, 'size' => $f->size->value, 'format' => $f->format ?: $f->size->format(), 'surface' => $f->surface ?: 'turf',
            'indoor' => (bool) $f->indoor, 'players' => $f->max_players ?: $f->size->maxPlayers(),
            'durations' => array_values($f->durations ?? [60]), 'defaultDuration' => $f->default_duration,
            'price' => $range,
        ];
    }

    public function venueBasics(Venue $v): array
    {
        return [
            'id' => $v->id, 'slug' => $v->slug, 'name' => $v->name, 'district' => $v->district,
            'address' => $v->tr('address'), 'rating' => $v->rating_avg ? round($v->rating_avg, 1) : null, 'reviews' => (int) $v->rating_count,
        ];
    }

    /** Opening hours per weekday: [[weekday, open, close] …] in decimal hours. */
    public function weekHours(Venue $v): array
    {
        return $v->openingHours->sortBy('weekday')->map(fn ($oh) => [$oh->weekday, OpeningHour::toHours($oh->opens_at), OpeningHour::toHours($oh->closes_at)])->values()->all();
    }

    /**
     * Free start times and half-hour prices of a place's fields on a day (place page + JSON endpoint).
     * $fieldId / $minutes narrow the answer to one field / one duration.
     */
    public function slots(Venue $venue, CarbonImmutable $day, ?int $fieldId = null, ?int $minutes = null): array
    {
        $venue->loadMissing(['openingHours', 'fields.priceRules']);
        $fields = $venue->fields->where('active', true)->when($fieldId, fn ($c) => $c->where('id', $fieldId))->values();
        $busy = $this->availability->busyMany($fields, $day);
        $hours = $this->availability->hours($venue, $day);

        $out = $fields->map(function (Field $f) use ($day, $busy, $hours, $minutes) {
            $starts = [];
            $taken = [];
            foreach ($minutes ? [$minutes] : ($f->durations ?? []) as $m) {
                $ok = in_array($m, $f->durations ?? [], true);
                $starts[(string) $m] = $ok ? $this->availability->freeStarts($f, $day, $m, true, $busy[$f->id] ?? []) : [];
                // taken start times are shown greyed out, so the player sees the whole day
                $taken[(string) $m] = $ok ? $this->availability->takenStarts($f, $day, $m, true, $busy[$f->id] ?? []) : [];
            }
            // price per hour of every half hour (PriceCalculator charges each half hour by its own rule)
            $rates = [];
            if ($hours) {
                for ($h = $hours[0]; $h < $hours[1] - 1e-9; $h += 0.5) {
                    $rates[] = [round($h, 2), $this->prices->hourlyAt($f->priceRules, LocalTime::at($day, $h))];
                }
            }

            return ['id' => $f->id, 'starts' => $starts, 'taken' => (object) $taken, 'next' => [], 'rates' => $rates];
        });

        // nothing free this day for a duration: the first free start on a later day ("Fully booked. Next free: Fri 18:00")
        $need = [];
        foreach ($out as $row) {
            foreach ($row['starts'] as $m => $list) {
                if (! $list) {
                    $need[$row['id']][] = (int) $m;
                }
            }
        }
        $next = $need ? $this->availability->nextFreeMany($fields->whereIn('id', array_keys($need))->values(), $day->addDay(), $need, 13) : [];

        return [
            'date' => $day->toDateString(),
            'hours' => $hours,
            'fields' => $out->map(fn ($row) => [...$row, 'starts' => (object) $row['starts'], 'next' => (object) array_filter($next[$row['id']] ?? [])])->all(),
        ];
    }

    /** A booking of the player (My bookings, confirmation). */
    public function booking(Booking $b): array
    {
        $start = $b->starts_at->toImmutable()->setTimezone(LocalTime::tz());
        $end = $b->ends_at->toImmutable()->setTimezone(LocalTime::tz());
        $venue = $b->venue;
        $holding = $b->status->holdsTime();
        $freeHours = (int) $venue->rule('free_cancel_hours');

        return [
            'id' => $b->id, 'code' => $b->code, 'status' => $b->status->value,
            'venue' => [
                'slug' => $venue->slug, 'name' => $venue->name, 'district' => $venue->district, 'address' => $venue->tr('address'),
                'lat' => $venue->lat !== null ? (float) $venue->lat : null, 'lng' => $venue->lng !== null ? (float) $venue->lng : null, 'phone' => $venue->phone ?: null,
            ],
            'field' => ['id' => $b->field_id, 'size' => $b->field->size->value, 'format' => $b->field->format ?: $b->field->size->format(), 'indoor' => (bool) $b->field->indoor, 'surface' => $b->field->surface],
            'date' => $start->toDateString(), 'start' => $start->format('H:i'), 'end' => $end->format('H:i') === '00:00' && ! $end->isSameDay($start) ? '24:00' : $end->format('H:i'),
            'startsAt' => $start->toIso8601String(), 'endsAt' => $end->toIso8601String(),
            'minutes' => $b->durationMinutes(), 'price' => $b->price,
            'teamName' => $b->team_name, 'note' => $b->note_player,
            'declineReason' => $b->decline_reason, 'cancelReason' => $b->cancel_reason, 'cancelledBy' => $b->cancelled_by,
            'canCancel' => $holding && $b->starts_at->isFuture(),
            'freeCancelHours' => $freeHours,
            // accepted and inside the free-cancellation window: the place may ask to pay
            'lateCancel' => $b->status->value === 'accepted' && $b->starts_at->lessThan(now()->addHours($freeHours)),
        ];
    }

    /** Places for the home page with the next free start times of their fields. */
    public function homeCards(Collection $venues, CarbonImmutable $day, array $sizes, ?string $time, int $perField): array
    {
        $fields = $venues->flatMap(fn ($v) => $v->fields->where('active', true)->whereIn('size.value', $sizes));
        $free = $this->availability->freeStartsMany($fields, $day);
        // fields with nothing free this day: the first free start on a later day ("Next free: Thu 19:00")
        $empty = $time ? collect() : $fields->filter(fn ($f) => empty($free[$f->id]))->values();
        $next = $empty->isEmpty() ? [] : $this->availability->nextFreeMany($empty, $day->addDay(), [], 13);

        return $venues->map(function (Venue $v) use ($free, $next, $sizes, $time, $perField, $day) {
            $hours = $this->availability->hours($v, $day);
            $fields = $v->fields->where('active', true)->whereIn('size.value', $sizes)->values()->map(function (Field $f) use ($free, $next, $time, $perField) {
                $starts = array_values(array_filter($free[$f->id] ?? [], fn ($h) => self::inTime($h, $time)));

                return [
                    ...$this->field($f), 'starts' => array_slice($starts, 0, $perField), 'more' => max(0, count($starts) - $perField),
                    'next' => $next[$f->id][$f->default_duration] ?? null,
                ];
            });

            return [
                ...$this->venueBasics($v),
                'hours' => $hours,
                'from' => (int) $fields->min('price.min'),
                'fields' => $fields->all(),
                'allFields' => $v->fields->where('active', true)->map(fn ($f) => ['size' => $f->size->value, 'indoor' => (bool) $f->indoor, 'surface' => $f->surface])->values()->all(),
                'free' => $fields->sum(fn ($f) => count($f['starts'])) > 0,
            ];
        })->all();
    }

    /** Time filter of the finder: morning / day / evening, or a start time "19:00" (that time and up to 2 h later). */
    public static function inTime(float $h, ?string $time): bool
    {
        return match (true) {
            ! $time => true,
            $time === 'morning' => $h < 12,
            $time === 'day' => $h >= 12 && $h < 18,
            $time === 'evening' => $h >= 18,
            (bool) preg_match('/^(\d{2}):(\d{2})$/', $time, $m) => $h >= (int) $m[1] + (int) $m[2] / 60 - 1e-9 && $h <= (int) $m[1] + (int) $m[2] / 60 + 2 + 1e-9,
            default => true,
        };
    }
}
