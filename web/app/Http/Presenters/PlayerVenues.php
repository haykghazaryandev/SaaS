<?php

namespace App\Http\Presenters;

use App\Domain\Booking\AfterBooking\Attendance;
use App\Domain\Booking\AfterBooking\BookingTeam;
use App\Domain\Booking\AfterBooking\Penalties;
use App\Domain\Venues\Availability;
use App\Domain\Venues\PriceCalculator;
use App\Models\Booking;
use App\Models\Field;
use App\Models\OpeningHour;
use App\Models\User;
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
            'id' => $f->id, 'size' => $f->size->value, 'sport' => $f->sport()->value, 'format' => $f->format ?: $f->size->format(), 'surface' => $f->surface ?: 'turf',
            'indoor' => (bool) $f->indoor, 'players' => $f->max_players ?: $f->size->maxPlayers(),
            'durations' => array_values($f->durations ?? [60]), 'defaultDuration' => $f->default_duration,
            'price' => $range,
            // a training on this kind: price per hour, null when the place has none
            'trainingPrice' => $f->relationLoaded('venue') ? $f->venue->trainingPrice($f->size->value) : null,
            // prices by length (15 min / 30 min / 1 h …) instead of per hour; empty: per hour
            'lengths' => $f->relationLoaded('venue') ? $f->venue->lengthPrices($f->size->value) : [],
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
     * The courts players choose between: one entry per kind (a football field alone, all ping-pong tables together),
     * keyed by the first court of the kind, which stands for the whole group in the page and the requests.
     *
     * @return Collection<int, Collection<int, Field>>
     */
    public function groups(Collection $fields): Collection
    {
        return $fields->where('active', true)->sortBy(fn (Field $f) => [$f->sort, $f->id])
            ->groupBy(fn (Field $f) => $f->size->value)->map->values()->values()->keyBy(fn ($g) => $g->first()->id);
    }

    /**
     * Free start times and half-hour prices of a place's courts on a day (place page + JSON endpoint), per group:
     * a time is free when at least one court of the group is free ("left": how many tables are still free).
     * $fieldId / $minutes narrow the answer to one group / one duration.
     */
    public function slots(Venue $venue, CarbonImmutable $day, ?int $fieldId = null, ?int $minutes = null): array
    {
        $venue->loadMissing(['openingHours', 'fields.priceRules']);
        $groups = $this->groups($venue->fields);
        if ($fieldId) {
            $groups = $groups->filter(fn ($g) => $g->contains('id', $fieldId));
        }
        $all = $groups->flatten(1);
        // a time others only asked for is still open to ask (the place picks); accepted bookings block it
        $busy = $this->availability->busyMany($all, $day, acceptedOnly: true);
        $hours = $this->availability->hours($venue, $day);
        $key = fn ($h) => (string) round($h, 2);

        $out = $groups->map(function (Collection $group) use ($venue, $day, $busy, $hours, $minutes, $key) {
            $f = $group->first()->setRelation('venue', $venue);
            $byLength = $venue->lengthPrices($f->size->value) !== [];
            $costs = [];
            $starts = [];
            $taken = [];
            $left = [];
            foreach ($minutes ? [$minutes] : ($f->durations ?? []) as $m) {
                $ok = in_array($m, $f->durations ?? [], true);
                $free = [];
                $seen = [];
                foreach ($ok ? $group : [] as $court) {
                    foreach ($this->availability->freeStarts($court, $day, $m, true, $busy[$court->id] ?? []) as $h) {
                        $free[$key($h)] = ($free[$key($h)] ?? 0) + 1;
                        $seen[$key($h)] = $h;
                    }
                    // taken start times are shown greyed out, so the player sees the whole day
                    foreach ($this->availability->takenStarts($court, $day, $m, true, $busy[$court->id] ?? []) as $h) {
                        $seen[$key($h)] = $h;
                    }
                }
                ksort($seen, SORT_NUMERIC);
                // priced by length: what each start costs for this length (the band at the start), from PriceCalculator
                if ($byLength) {
                    $costs[(string) $m] = (object) array_map(fn ($h) => $this->prices->price($f, LocalTime::at($day, $h), $m), $seen);
                }
                $starts[(string) $m] = array_values(array_map(fn ($k) => $seen[$k], array_keys(array_intersect_key($seen, $free))));
                $taken[(string) $m] = array_values(array_map(fn ($k) => $seen[$k], array_keys(array_diff_key($seen, $free))));
                if ($group->count() > 1) {
                    $left[(string) $m] = (object) $free;
                }
            }
            // price per hour of every half hour (PriceCalculator charges each half hour by its own rule)
            $rates = [];
            if ($hours) {
                for ($h = $hours[0]; $h < $hours[1] - 1e-9; $h += 0.5) {
                    $rates[] = [round($h, 2), $this->prices->hourlyAt($f->priceRules, LocalTime::at($day, $h))];
                }
            }

            return ['id' => $f->id, 'count' => $group->count(), 'starts' => $starts, 'taken' => (object) $taken, 'left' => (object) $left, 'next' => [], 'rates' => $rates, 'costs' => (object) $costs];
        });

        // nothing free this day for a duration: the first free start on a later day ("Fully booked. Next free: Fri 18:00"), any court of the group
        $need = [];
        foreach ($out as $row) {
            foreach ($row['starts'] as $m => $list) {
                if (! $list) {
                    foreach ($groups[$row['id']] as $court) {
                        $need[$court->id][] = (int) $m;
                    }
                }
            }
        }
        $next = $need ? $this->availability->nextFreeMany($all->whereIn('id', array_keys($need))->values(), $day->addDay(), $need, 13) : [];
        $nextOf = function (int $repId) use ($groups, $next) {
            $best = [];
            foreach ($groups[$repId] as $court) {
                foreach ($next[$court->id] ?? [] as $m => $at) {
                    if ($at && (! isset($best[$m]) || [$at['date'], $at['start']] < [$best[$m]['date'], $best[$m]['start']])) {
                        $best[$m] = $at;
                    }
                }
            }

            return $best;
        };

        return [
            'date' => $day->toDateString(),
            'hours' => $hours,
            'fields' => $out->values()->map(fn ($row) => [...$row, 'starts' => (object) $row['starts'], 'next' => (object) $nextOf($row['id'])])->all(),
        ];
    }

    /** A booking of the player (My bookings, confirmation). */
    /** $viewer: who looks at it (the booker sees the team controls and the invite link, a team member sees "Leave"). */
    public function booking(Booking $b, ?User $viewer = null): array
    {
        $start = $b->starts_at->toImmutable()->setTimezone(LocalTime::tz());
        $end = $b->ends_at->toImmutable()->setTimezone(LocalTime::tz());
        $venue = $b->venue;
        $holding = $b->status->holdsTime();

        $row = [
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
            // accepted and inside the free-cancellation window: the place may ask to pay
            // less than 2 h before an accepted game: a late cancel (remembered in the player's record here)
            'lateCancel' => $b->status->value === 'accepted' && $b->starts_at->lessThan(now()->addHours(Penalties::SHORT_CANCEL_HOURS)),
        ];

        // My bookings: team, "running late", arrival (overrides canCancel for team members)
        return $viewer ? array_replace($row, $this->afterBooking($b, $viewer)) : $row;
    }

    /** Team, "running late" and arrival for My bookings. */
    private function afterBooking(Booking $b, User $viewer): array
    {
        $b->loadMissing('players.user', 'user');
        $booker = $b->user_id === $viewer->id;
        $mine = $b->players->firstWhere('user_id', $viewer->id);
        $team = $b->players->whereIn('status', ['invited', 'joined', 'declined'])->filter(fn ($p) => $p->user)->values()->map(fn ($p) => [
            'id' => $p->id, 'status' => $p->status,
            'user' => ['id' => $p->user->id, 'name' => $p->user->name, 'avatar' => $p->user->avatarUrl()],
        ])->all();

        return [
            'canCancel' => $booker && $b->status->holdsTime() && $b->starts_at->isFuture(),
            // booker | member (joined) | invited (a friend asked, waiting for my answer)
            'role' => $booker ? 'booker' : ($mine?->status === 'invited' ? 'invited' : 'member'),
            'booker' => $booker ? null : ['name' => $b->user?->name, 'avatar' => $b->user?->avatarUrl(), 'id' => $b->user_id],
            'team' => $team,
            'teamMax' => max(1, (int) ($b->field->max_players ?: 22) - 1),
            'inviteUrl' => $booker && $b->status->holdsTime() ? app(BookingTeam::class)->link($b) : null,
            'canLate' => Attendance::canSayLate($b),
            'lateOptions' => Attendance::LATE_OPTIONS,
            'lateMinutes' => $b->late_minutes,
            'arrived' => $b->arrived_at !== null,
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
            // one row per kind of court: a ping-pong place's tables together ("any free table")
            $fields = $this->groups($v->fields->whereIn('size.value', $sizes))->values()->map(function (Collection $group) use ($free, $next, $time, $perField) {
                $f = $group->first();
                $all = [];
                foreach ($group as $court) {
                    foreach ($free[$court->id] ?? [] as $h) {
                        $all[(string) round($h, 2)] = $h;
                    }
                }
                ksort($all, SORT_NUMERIC);
                $starts = array_values(array_filter($all, fn ($h) => self::inTime($h, $time)));
                $nexts = $group->map(fn ($court) => $next[$court->id][$court->default_duration] ?? null)->filter()->sortBy(fn ($n) => [$n['date'], $n['start']]);

                return [
                    ...$this->field($f), 'count' => $group->count(), 'starts' => array_slice($starts, 0, $perField), 'more' => max(0, count($starts) - $perField),
                    'next' => $nexts->first(),
                ];
            });

            return [
                ...$this->venueBasics($v),
                'hours' => $hours,
                'from' => (int) $fields->min('price.min'),
                // priced by length (ping-pong): "from 1 200 ֏ / 15 min" instead of per hour
                'fromLen' => ($len = $v->fields->where('active', true)->map(fn ($f) => $v->lengthPrices($f->size->value)[0] ?? null)->filter()->sortBy('day')->first())
                    ? ['p' => $len['day'], 'min' => $len['min']] : null,
                'fields' => $fields->all(),
                'allFields' => $v->fields->where('active', true)->map(fn ($f) => ['size' => $f->size->value, 'indoor' => (bool) $f->indoor, 'surface' => $f->surface])->values()->all(),
                'sports' => array_map(fn ($sp) => $sp->value, $v->sports()),
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
