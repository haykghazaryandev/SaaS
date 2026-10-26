<?php

namespace App\Domain\Venues;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Closure;
use App\Models\Field;
use App\Models\OpeningHour;
use App\Models\Venue;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Free and busy time of fields. Works in local (Yerevan) time with decimal hours: 21.5 = 21:30.
 */
class Availability
{
    /** [open, close] in hours for a local day, or null when closed. */
    public function hours(Venue $venue, CarbonImmutable $day): ?array
    {
        $oh = ($venue->relationLoaded('openingHours') ? $venue->openingHours : $venue->openingHours()->get())
            ->firstWhere('weekday', $day->dayOfWeek);

        return $oh ? [OpeningHour::toHours($oh->opens_at), OpeningHour::toHours($oh->closes_at)] : null;
    }

    /** Busy blocks of a field on a local day: holding bookings + closures, as [[from, to, bookingId|null], …] in hours. */
    public function busy(Field $field, CarbonImmutable $day, ?int $ignoreBookingId = null): array
    {
        $from = $day->startOfDay();
        $to = $from->addDay();
        $blocks = Booking::query()->holding()->overlapping($field->id, $from->utc(), $to->utc())
            ->when($ignoreBookingId, fn ($q) => $q->where('id', '!=', $ignoreBookingId))
            ->get(['id', 'starts_at', 'ends_at'])
            ->map(fn ($b) => [max(0, LocalTime::hourOf($b->starts_at->toImmutable(), $from)), min(24, LocalTime::hourOf($b->ends_at->toImmutable(), $from)), $b->id])
            ->all();
        $closures = Closure::query()->where('venue_id', $field->venue_id)
            ->where(fn ($q) => $q->whereNull('field_id')->orWhere('field_id', $field->id))
            ->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())->get();
        foreach ($closures as $c) {
            $blocks[] = [max(0, LocalTime::hourOf($c->starts_at->toImmutable(), $from)), min(24, LocalTime::hourOf($c->ends_at->toImmutable(), $from)), null];
        }

        return $blocks;
    }

    /**
     * Why a time can't be booked, or null when it is free.
     * $forPlayer applies the player rules (minimum notice, horizon); the owner can book any free time.
     */
    public function problem(Field $field, CarbonImmutable $start, int $minutes, bool $forPlayer = true, ?int $ignoreBookingId = null): ?string
    {
        $venue = $field->loadMissing('venue.openingHours')->venue;
        $start = $start->setTimezone(LocalTime::tz());
        $day = $start->startOfDay();
        $hours = $this->hours($venue, $day);
        $from = LocalTime::hourOf($start, $day);
        $to = $from + $minutes / 60;
        if (! $field->active || $venue->status !== 'active') {
            return 'closed';
        }
        if (! $hours || $from < $hours[0] || $to > $hours[1] + 1e-9) {
            return 'outside_hours';
        }
        if (! in_array($minutes, $field->durations, true) && $forPlayer) {
            return 'bad_duration';
        }
        $now = LocalTime::now();
        if ($start->lessThan($forPlayer ? $now : $this->ownerEarliest($venue, $now))) {
            return 'past';
        }
        if ($forPlayer) {
            if ($start->lessThan($now->addMinutes($venue->rule('min_notice_min')))) {
                return 'too_soon';
            }
            if ($start->greaterThan($now->startOfDay()->addDays($venue->rule('horizon_days')))) {
                return 'too_far';
            }
        }
        foreach ($this->busy($field, $day, $ignoreBookingId) as [$a, $b]) {
            if ($a < $to - 1e-9 && $b > $from + 1e-9) {
                return 'taken';
            }
        }

        return null;
    }

    /** The place may still book the time step that has already begun (a team walks in at 20:40 and plays from 20:30). */
    public function ownerEarliest(Venue $venue, CarbonImmutable $now): CarbonImmutable
    {
        $step = max(1, (int) $venue->rule('step_min'));
        $midnight = $now->startOfDay();

        return $midnight->addMinutes(intdiv((int) $midnight->diffInMinutes($now), $step) * $step);
    }

    /**
     * The courts players book as one (same place, same kind, active): a ping-pong place's tables, a football field alone.
     * Ordered by the place's sort, so the first free table is given first.
     */
    public function group(Field $field): Collection
    {
        $venue = $field->loadMissing('venue')->venue;
        $all = $venue->relationLoaded('fields') ? $venue->fields : $venue->fields()->get();
        $group = $all->filter(fn (Field $f) => $f->active && $f->size === $field->size)->sortBy(fn (Field $f) => [$f->sort, $f->id])->values();
        $group->each(fn (Field $f) => $f->setRelation('venue', $venue));

        return $group->isEmpty() ? collect([$field]) : $group;
    }

    /**
     * A court of the group that is free for this time: the asked one when free, else the next free one of its kind.
     * Returns [field, null] or [null, the asked court's problem].
     */
    public function freeInGroup(Field $field, CarbonImmutable $start, int $minutes, bool $forPlayer = true): array
    {
        $problem = $this->problem($field, $start, $minutes, $forPlayer);
        if ($problem !== 'taken') {
            return $problem === null ? [$field, null] : [null, $problem];
        }
        foreach ($this->group($field) as $other) {
            if ($other->id !== $field->id && $this->problem($other, $start, $minutes, $forPlayer) === null) {
                return [$other, null];
            }
        }

        return [null, 'taken'];
    }

    public function isFree(Field $field, CarbonImmutable $start, int $minutes, bool $forPlayer = true, ?int $ignoreBookingId = null): bool
    {
        return $this->problem($field, $start, $minutes, $forPlayer, $ignoreBookingId) === null;
    }

    /**
     * Busy blocks of many fields on one local day, with two queries in total (for lists like the home page).
     * Returns [fieldId => [[from, to, bookingId|null], …]], the same shape as busy().
     */
    public function busyMany(Collection $fields, CarbonImmutable $day): array
    {
        $from = $day->startOfDay();
        $to = $from->addDay();
        $out = $fields->mapWithKeys(fn ($f) => [$f->id => []])->all();
        if (! $out) {
            return [];
        }
        $span = fn ($a, $b) => [max(0, LocalTime::hourOf($a->toImmutable(), $from)), min(24, LocalTime::hourOf($b->toImmutable(), $from))];
        Booking::query()->holding()->whereIn('field_id', array_keys($out))
            ->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())
            ->get(['id', 'field_id', 'starts_at', 'ends_at'])
            ->each(function ($b) use (&$out, $span) {
                $out[$b->field_id][] = [...$span($b->starts_at, $b->ends_at), $b->id];
            });
        $closures = Closure::query()->whereIn('venue_id', $fields->pluck('venue_id')->unique()->all())
            ->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())->get();
        foreach ($closures as $c) {
            foreach ($fields as $f) {
                if ($f->venue_id === $c->venue_id && ($c->field_id === null || $c->field_id === $f->id)) {
                    $out[$f->id][] = [...$span($c->starts_at, $c->ends_at), null];
                }
            }
        }

        return $out;
    }

    /** Free start times of many fields on one local day: [fieldId => [hours…]]. $minutes null = each field's default duration. */
    public function freeStartsMany(Collection $fields, CarbonImmutable $day, ?int $minutes = null, bool $forPlayer = true): array
    {
        $busy = $this->busyMany($fields, $day);

        return $fields->mapWithKeys(fn ($f) => [$f->id => $this->freeStarts($f, $day, $minutes ?? $f->default_duration, $forPlayer, $busy[$f->id] ?? [])])->all();
    }

    /**
     * Free start times (decimal hours) of a field on a local day for a duration.
     * $busy can be passed in (from busyMany) to avoid one query per field.
     */
    public function freeStarts(Field $field, CarbonImmutable $day, int $minutes, bool $forPlayer = true, ?array $busy = null): array
    {
        $venue = $field->loadMissing('venue.openingHours')->venue;
        $hours = $this->hours($venue, $day);
        if (! $hours) {
            return [];
        }
        $step = $venue->rule('step_min') / 60;
        $busy ??= $this->busy($field, $day);
        $now = LocalTime::now();
        $earliest = $forPlayer ? $now->addMinutes($venue->rule('min_notice_min')) : $this->ownerEarliest($venue, $now);
        if ($forPlayer && $day->greaterThan($now->startOfDay()->addDays($venue->rule('horizon_days')))) {
            return [];
        }
        $out = [];
        for ($h = $hours[0]; $h + $minutes / 60 <= $hours[1] + 1e-9; $h += $step) {
            if (LocalTime::at($day, $h)->lessThan($earliest)) {
                continue;
            }
            $end = $h + $minutes / 60;
            $clash = false;
            foreach ($busy as [$a, $b]) {
                if ($a < $end - 1e-9 && $b > $h + 1e-9) {
                    $clash = true;
                    break;
                }
            }
            if (! $clash) {
                $out[] = round($h, 2);
            }
        }

        return $out;
    }

    /**
     * Start times that fall inside opening hours and are not in the past (nor inside the minimum notice), but clash
     * with a booking or closure. Together with freeStarts() this is every start time a player can see on a day.
     */
    public function takenStarts(Field $field, CarbonImmutable $day, int $minutes, bool $forPlayer = true, ?array $busy = null): array
    {
        $venue = $field->loadMissing('venue.openingHours')->venue;
        $hours = $this->hours($venue, $day);
        $now = LocalTime::now();
        if (! $hours || ($forPlayer && $day->greaterThan($now->startOfDay()->addDays($venue->rule('horizon_days'))))) {
            return [];
        }
        $step = $venue->rule('step_min') / 60;
        $busy ??= $this->busy($field, $day);
        $earliest = $forPlayer ? $now->addMinutes($venue->rule('min_notice_min')) : $this->ownerEarliest($venue, $now);
        $out = [];
        for ($h = $hours[0]; $h + $minutes / 60 <= $hours[1] + 1e-9; $h += $step) {
            if (LocalTime::at($day, $h)->lessThan($earliest)) {
                continue;
            }
            $end = $h + $minutes / 60;
            foreach ($busy as [$a, $b]) {
                if ($a < $end - 1e-9 && $b > $h + 1e-9) {
                    $out[] = round($h, 2);
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * The first free start of each field on or after a local day, looking $days days ahead (two queries per day).
     * $durations: [fieldId => [minutes, …]]; a field without an entry uses its default duration.
     * Returns [fieldId => [minutes => ['date' => 'Y-m-d', 'start' => hours] | null]].
     */
    public function nextFreeMany(Collection $fields, CarbonImmutable $fromDay, array $durations = [], int $days = 14, bool $forPlayer = true): array
    {
        $out = [];
        $todo = [];
        foreach ($fields as $f) {
            foreach ($durations[$f->id] ?? [$f->default_duration] as $m) {
                $out[$f->id][(int) $m] = null;
                $todo[$f->id][] = (int) $m;
            }
        }
        $day = $fromDay->startOfDay();
        for ($k = 0; $k < $days && $todo; $k++, $day = $day->addDay()) {
            $pending = $fields->filter(fn ($f) => isset($todo[$f->id]))->values();
            $busy = $this->busyMany($pending, $day);
            foreach ($pending as $f) {
                foreach ($todo[$f->id] as $i => $m) {
                    $starts = $this->freeStarts($f, $day, $m, $forPlayer, $busy[$f->id] ?? []);
                    if ($starts) {
                        $out[$f->id][$m] = ['date' => $day->toDateString(), 'start' => $starts[0]];
                        unset($todo[$f->id][$i]);
                    }
                }
                if (! $todo[$f->id]) {
                    unset($todo[$f->id]);
                }
            }
        }

        return $out;
    }

    /**
     * Free time of a field on a local day for the place, as [[from, to], …] in hours, at least one time step long.
     * Opening hours minus holding bookings and closures; on today it starts at the time step that has begun.
     */
    public function freeGaps(Field $field, CarbonImmutable $day, ?array $busy = null): array
    {
        $venue = $field->loadMissing('venue.openingHours')->venue;
        $hours = $this->hours($venue, $day);
        if (! $hours || ! $field->active) {
            return [];
        }
        $step = max(1, (int) $venue->rule('step_min')) / 60;
        $from = max($hours[0], LocalTime::hourOf($this->ownerEarliest($venue, LocalTime::now()), $day->startOfDay()));
        $blocks = $busy ?? $this->busy($field, $day);
        usort($blocks, fn ($a, $b) => $a[0] <=> $b[0]);
        $out = [];
        $at = $from;
        foreach ($blocks as [$a, $b]) {
            if ($a > $at + 1e-9) {
                $out[] = [$at, min($a, $hours[1])];
            }
            $at = max($at, $b);
        }
        if ($at < $hours[1] - 1e-9) {
            $out[] = [$at, $hours[1]];
        }

        return array_values(array_map(fn ($g) => [round($g[0], 2), round($g[1], 2)], array_filter($out, fn ($g) => $g[1] - $g[0] >= $step - 1e-9)));
    }

    /**
     * How busy a group of courts is through a local day (owner schedule): [[from, to, busy], …] over the opening hours,
     * busy = how many of the courts are taken then (bookings or closures). On today it starts at the time step that has begun.
     * One court: busy is 0 or 1 (the free gaps); three tables: 0…3 ("2/3").
     */
    public function groupLoad(Collection $courts, CarbonImmutable $day, array $busy): array
    {
        $venue = $courts->first()?->loadMissing('venue.openingHours')->venue;
        $hours = $venue ? $this->hours($venue, $day) : null;
        if (! $hours) {
            return [];
        }
        $from = max($hours[0], LocalTime::hourOf($this->ownerEarliest($venue, LocalTime::now()), $day->startOfDay()));
        if ($from >= $hours[1] - 1e-9) {
            return [];
        }
        $cuts = [$from, $hours[1]];
        foreach ($courts as $c) {
            foreach ($busy[$c->id] ?? [] as [$a, $b]) {
                foreach ([$a, $b] as $x) {
                    if ($x > $from + 1e-9 && $x < $hours[1] - 1e-9) {
                        $cuts[] = $x;
                    }
                }
            }
        }
        $cuts = array_values(array_unique(array_map(fn ($x) => round($x, 4), $cuts)));
        sort($cuts);
        $out = [];
        for ($i = 0; $i < count($cuts) - 1; $i++) {
            [$a, $b] = [$cuts[$i], $cuts[$i + 1]];
            $n = 0;
            foreach ($courts as $c) {
                foreach ($busy[$c->id] ?? [] as [$x, $y]) {
                    if ($x < $b - 1e-9 && $y > $a + 1e-9) {
                        $n++;
                        break;
                    }
                }
            }
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last][2] === $n && abs($out[$last][1] - $a) < 1e-9) {
                $out[$last][1] = $b;
            } else {
                $out[] = [$a, $b, $n];
            }
        }

        return array_map(fn ($s) => [round($s[0], 2), round($s[1], 2), $s[2]], $out);
    }

    /** All bookings of a place for a local day (owner schedule), grouped by field id. */
    public function daySchedule(Venue $venue, CarbonImmutable $day, bool $withInactive = false): Collection
    {
        $from = $day->startOfDay();

        return Booking::query()->where('venue_id', $venue->id)
            ->where('starts_at', '<', $from->addDay()->utc())->where('ends_at', '>', $from->utc())
            ->when(! $withInactive, fn ($q) => $q->whereIn('status', [...BookingStatus::HOLDING, 'completed', 'no_show']))
            ->with('user:id,name,phone,no_show_count')
            ->orderBy('starts_at')->get()->groupBy('field_id');
    }
}
