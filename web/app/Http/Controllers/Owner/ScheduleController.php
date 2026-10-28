<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Venues\Availability;
use App\Enums\FieldSize;
use App\Models\Booking;
use App\Models\Closure;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

/** Schedule: day (two fields side by side), week (7 days × 2 fields) or list (search + status filter). */
class ScheduleController extends OwnerController
{
    public function __invoke(Request $request, Availability $availability, VenueStats $stats)
    {
        $data = $request->validate([
            'date' => 'nullable|date_format:Y-m-d',
            'view' => 'nullable|in:day,week,list',
        ]);
        $venue = $this->venue($request)->load(['fields', 'openingHours']);
        $view = $data['view'] ?? 'day';
        $today = VenueStats::today();
        $date = isset($data['date']) ? LocalTime::day($data['date']) : $today;

        // date strip: a week back and four weeks ahead (around the chosen date when it is far away)
        $stripFrom = $date->lessThan($today->subDays(7)) || $date->greaterThan($today->addDays(27)) ? $date->subDays(7) : $today->subDays(7);
        $pendingDays = Booking::query()->where('venue_id', $venue->id)->where('status', 'pending')->where('starts_at', '>', now())
            ->where('starts_at', '>=', $stripFrom->utc())->where('starts_at', '<', $stripFrom->addDays(35)->utc())
            ->pluck('starts_at')->map(fn ($t) => $t->setTimezone(LocalTime::tz())->toDateString())->unique()->values();
        $strip = collect(range(0, 34))->map(fn ($i) => $stripFrom->addDays($i)->toDateString());

        $props = [
            'view' => $view,
            'date' => $date->toDateString(),
            'today' => $today->toDateString(),
            'nowHour' => round(LocalTime::hourOf(LocalTime::now()), 3),
            'strip' => $strip,
            'pendingDays' => $pendingDays,
        ];

        if ($view === 'day') {
            $props['day'] = $this->day($venue, $date, $availability, $stats);
        } elseif ($view === 'week') {
            $props['week'] = $this->week($venue, $date, $availability, $stats);
        } else {
            $rows = $stats->bookings($venue, $today->subDays(7), $today->addDays(31));
            // upcoming first (soonest on top), then the past (latest on top)
            [$past, $next] = $rows->partition(fn (Booking $b) => $b->ends_at->isPast());
            $props['list'] = [
                'from' => $today->subDays(7)->toDateString(),
                'to' => $today->addDays(30)->toDateString(),
                'rows' => $next->concat($past->reverse())->take(800)->map(fn ($b) => BookingPresenter::row($b))->values(),
            ];
        }

        return Inertia::render('owner/Schedule', $props);
    }

    private function day($venue, CarbonImmutable $date, Availability $availability, VenueStats $stats): array
    {
        $hours = $availability->hours($venue, $date);
        $bookings = $stats->bookings($venue, $date, $date->addDay());
        $rows = $bookings->map(fn ($b) => BookingPresenter::row($b))->values();
        // the grid covers the opening hours and every booking of the day
        [$open, $close] = $hours ?? [8.0, 24.0];
        foreach ($rows as $r) {
            if ($r['date'] === $date->toDateString()) {
                $open = min($open, floor($r['start']));
                $close = max($close, min(24, ceil($r['end'])));
            }
        }
        $closures = self::closures($venue, $date, 1);

        // per lane (a field, or all tables together) how busy it is through the day: "Free · + Book" and "2/3" rows
        $lanes = self::lanes($venue);
        $busy = $availability->busyMany($venue->fields->where('active', true)->values(), $date);
        $load = $lanes->mapWithKeys(fn ($l) => [$l['id'] => $availability->groupLoad($l['models'], $date, $busy)])->all();

        return [
            'open' => (float) $open,
            'lanes' => $lanes->map(fn ($l) => collect($l)->except('models')->all())->values(),
            'load' => (object) $load,
            'close' => (float) $close,
            'closed' => $hours === null,
            'hours' => $hours,
            'bookings' => $rows,
            'closures' => $closures,
            'step' => max(1, (int) $venue->rule('step_min')) / 60,
        ];
    }

    /** Closed times of $days days from $date, cut per day: {id, field_id, reason, date, start, end} (hours of that day). */
    private static function closures($venue, CarbonImmutable $date, int $days): Collection
    {
        $list = Closure::query()->where('venue_id', $venue->id)
            ->where('starts_at', '<', $date->addDays($days)->utc())->where('ends_at', '>', $date->utc())->get();
        $out = collect();
        for ($i = 0; $i < $days; $i++) {
            $day = $date->addDays($i);
            foreach ($list as $c) {
                if ($c->starts_at->lt($day->addDay()) && $c->ends_at->gt($day)) {
                    $out->push(['id' => $c->id, 'field_id' => $c->field_id, 'reason' => $c->reason, 'date' => $day->toDateString(),
                        'start' => max(0, round(LocalTime::hourOf($c->starts_at->toImmutable(), $day), 2)),
                        'end' => min(24, round(LocalTime::hourOf($c->ends_at->toImmutable(), $day), 2))]);
                }
            }
        }

        return $out->values();
    }

    private function week($venue, CarbonImmutable $date, Availability $availability, VenueStats $stats): array
    {
        $bookings = $stats->bookings($venue, $date, $date->addDays(7));
        $active = $venue->fields->where('active', true)->values();
        $lanes = self::lanes($venue);
        $step = max(1, (int) $venue->rule('step_min')) / 60;
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $date->addDays($i);
            $key = $day->toDateString();
            $busy = $availability->busyMany($active, $day);
            $list = $bookings->filter(fn (Booking $b) => $b->starts_at->setTimezone(LocalTime::tz())->toDateString() === $key);
            $fields = [];
            foreach ($lanes as $l) {
                $mine = $list->whereIn('field_id', $l['courts']);
                // a request nobody answered before its start is not holding the time any more
                $holding = $mine->filter(fn (Booking $b) => in_array($b->status->value, BookingPresenter::USED, true) && BookingPresenter::kind($b) !== 'expired');
                // free time: while at least one court of the lane is free
                $gaps = [];
                foreach ($availability->groupLoad($l['models'], $day, $busy) as [$from, $to, $n]) {
                    if ($n >= $l['count']) {
                        continue;
                    }
                    $last = count($gaps) - 1;
                    if ($last >= 0 && abs($gaps[$last][1] - $from) < 1e-9) {
                        $gaps[$last][1] = $to;
                    } else {
                        $gaps[] = [$from, $to];
                    }
                }
                $fields[$l['id']] = [
                    'count' => $holding->count(),
                    'pending' => $holding->filter(fn (Booking $b) => BookingPresenter::kind($b) === 'pending')->count(),
                    'free' => array_values(array_filter($gaps, fn ($g) => $step - 1e-9 <= $g[1] - $g[0])),
                ];
            }
            $days[] = ['date' => $key, 'hours' => $availability->hours($venue, $day), 'fields' => $fields];
        }

        // the hour grid of the week: every booking and closed time of the 7 days
        return ['days' => $days, 'lanes' => $lanes->map(fn ($l) => collect($l)->except('models')->all())->values(),
            'bookings' => $bookings->map(fn ($b) => BookingPresenter::row($b))->values(),
            'closures' => self::closures($venue, $date, 7), 'step' => $step];
    }

    /**
     * The columns of the schedule: one per football field, one for all the tables of a ping-pong place ("Tables · 3").
     * models: the courts (server side only).
     */
    public static function lanes($venue): Collection
    {
        return $venue->fields->where('active', true)->sortBy(fn ($f) => [$f->sort, $f->id])
            ->groupBy(fn ($f) => $f->size->value)->values()
            ->flatMap(function ($group) {
                // football: every field is its own lane; tables: one lane for all of them
                $parts = $group->first()->size === FieldSize::Table ? [$group->values()] : $group->map(fn ($f) => collect([$f]))->all();

                return collect($parts)->map(function ($courts) {
                    $f = $courts->first();
                    $many = $courts->count() > 1;

                    return [
                        'id' => $f->id, 'size' => $f->size->value, 'sport' => $f->sport()->value, 'count' => $courts->count(),
                        'label' => $many ? __('owner.courts.kind_'.$f->size->value) : BookingPresenter::fieldLabel($f),
                        'short' => $many ? __('owner.courts.kind_'.$f->size->value) : ($f->name ?: $f->format),
                        'courts' => $courts->pluck('id')->values()->all(),
                        'models' => $courts->values(),
                    ];
                });
            })->values();
    }
}
