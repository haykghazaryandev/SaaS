<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Venues\Availability;
use App\Models\Booking;
use App\Models\Closure;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
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
        $closures = Closure::query()->where('venue_id', $venue->id)
            ->where('starts_at', '<', $date->addDay()->utc())->where('ends_at', '>', $date->utc())->get()
            ->map(fn (Closure $c) => [
                'id' => $c->id, 'field_id' => $c->field_id, 'reason' => $c->reason,
                'start' => max(0, round(LocalTime::hourOf($c->starts_at->toImmutable(), $date), 2)),
                'end' => min(24, round(LocalTime::hourOf($c->ends_at->toImmutable(), $date), 2)),
            ])->values();

        // free gaps per field: the "Free 16:30–17:30 · + Book" rows (same rules as the booking page, without the player limits)
        $active = $venue->fields->where('active', true)->values();
        $busy = $availability->busyMany($active, $date);
        $free = $active->mapWithKeys(fn ($f) => [$f->id => $availability->freeGaps($f, $date, $busy[$f->id] ?? [])])->all();

        return [
            'open' => (float) $open,
            'free' => (object) $free,
            'close' => (float) $close,
            'closed' => $hours === null,
            'hours' => $hours,
            'bookings' => $rows,
            'closures' => $closures,
        ];
    }

    private function week($venue, CarbonImmutable $date, Availability $availability, VenueStats $stats): array
    {
        $bookings = $stats->bookings($venue, $date, $date->addDays(7));
        $active = $venue->fields->where('active', true)->values();
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $date->addDays($i);
            $key = $day->toDateString();
            $busy = $availability->busyMany($active, $day);
            $list = $bookings->filter(fn (Booking $b) => $b->starts_at->setTimezone(LocalTime::tz())->toDateString() === $key);
            $fields = [];
            foreach ($venue->fields as $f) {
                $mine = $list->where('field_id', $f->id);
                // a request nobody answered before its start is not holding the time any more
                $holding = $mine->filter(fn (Booking $b) => in_array($b->status->value, BookingPresenter::USED, true) && BookingPresenter::kind($b) !== 'expired');
                $fields[$f->id] = [
                    'blocks' => $holding->map(fn ($b) => BookingPresenter::row($b))
                        ->map(fn ($r) => ['id' => $r['id'], 'start' => $r['start'], 'end' => $r['end'], 'kind' => $r['kind'], 'name' => $r['team'] ?: $r['name']])->values(),
                    'count' => $holding->count(),
                    'pending' => $holding->filter(fn (Booking $b) => BookingPresenter::kind($b) === 'pending')->count(),
                    'pct' => $stats->occupancy($venue, $f->id, $day, $bookings),
                    'free' => $f->active ? $availability->freeGaps($f, $day, $busy[$f->id] ?? []) : [],
                ];
            }
            $days[] = ['date' => $key, 'hours' => $availability->hours($venue, $day), 'fields' => $fields];
        }

        return ['days' => $days];
    }
}
