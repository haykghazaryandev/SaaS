<?php

namespace App\Http\Controllers\Owner;

use App\Models\Booking;
use App\Support\LocalTime;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

/** Today: KPIs, the two-lane "today" strip (one lane per field), requests waiting and the occupancy of the next 7 days. */
class TodayController extends OwnerController
{
    public function __invoke(Request $request, VenueStats $stats)
    {
        $venue = $this->venue($request)->load(['fields', 'openingHours']);
        $today = VenueStats::today();
        $todays = $stats->bookings($venue, $today, $today->addDay());
        $holding = $todays->filter(fn (Booking $b) => $b->status->holdsTime());

        // revenue = accepted and played bookings (Mon–Sun for the week)
        $paid = ['accepted', 'completed'];
        $weekStart = $today->startOfWeek(CarbonInterface::MONDAY);
        $revenueWeek = (int) Booking::query()->where('venue_id', $venue->id)->whereIn('status', $paid)
            ->where('starts_at', '>=', $weekStart->utc())->where('starts_at', '<', $weekStart->addWeek()->utc())->sum('price');

        $pending = $stats->pendingQuery($venue);
        $pendingCount = (clone $pending)->count();
        $requests = (clone $pending)->with('user:id,name,phone,no_show_count')->orderBy('starts_at')->limit(6)->get();
        // every open request, to flag requests that clash with another one (same field, or the same player on both fields)
        $open = $pending->get(['id', 'field_id', 'user_id', 'contact_phone', 'contact_name', 'team_name', 'starts_at', 'ends_at']);

        $week = $stats->bookings($venue, $today, $today->addDays(7), BookingPresenter::USED);
        $chart = collect(range(0, 6))->map(function ($d) use ($venue, $today, $week, $stats) {
            $day = $today->addDays($d);

            return [
                'date' => $day->toDateString(),
                'fields' => $venue->fields->mapWithKeys(fn ($f) => [$f->id => $stats->occupancy($venue, $f->id, $day, $week)])->all(),
            ];
        });

        return Inertia::render('owner/Today', [
            'date' => $today->toDateString(),
            'me' => $request->user()->name,
            'kpi' => [
                'today' => $holding->count(),
                'todayAccepted' => $holding->where('status.value', 'accepted')->count(),
                'todayPending' => $holding->where('status.value', 'pending')->count(),
                'pending' => $pendingCount,
                'occupancy' => $venue->fields->mapWithKeys(fn ($f) => [$f->id => $stats->occupancy($venue, $f->id, $today, $todays)])->all(),
                'revenueToday' => (int) $todays->filter(fn ($b) => in_array($b->status->value, $paid, true))->sum('price'),
                'revenueWeek' => $revenueWeek,
            ],
            'requests' => (function () use ($requests, $open, $venue) {
                $choose = BookingPresenter::choosing($requests, $venue);

                return $requests->map(fn ($b) => [...BookingPresenter::row($b), 'conflicts' => $this->conflicts($b, $open)] + ($choose[$b->id] ?? []))->values();
            })(),
            // the today strip: what holds time on each field today (expired requests left out)
            'lanes' => $todays->filter(fn (Booking $b) => in_array(BookingPresenter::kind($b), ['pending', 'accepted', 'owner', 'noshow'], true))
                ->map(fn ($b) => BookingPresenter::row($b))->values(),
            'chart' => $chart,
            'nowHour' => round(LocalTime::hourOf(LocalTime::now()), 3),
        ]);
    }

    /** Other open requests that overlap this one on the same field, or come from the same player (account or phone). */
    private function conflicts(Booking $b, Collection $open): array
    {
        return $open->filter(fn (Booking $o) => $o->id !== $b->id
            && $o->starts_at < $b->ends_at && $o->ends_at > $b->starts_at
            && ($o->field_id === $b->field_id
                || ($b->user_id && $o->user_id === $b->user_id)
                || ($b->contact_phone && $o->contact_phone === $b->contact_phone)))
            ->map(fn (Booking $o) => [
                'id' => $o->id,
                'field_id' => $o->field_id,
                'name' => $o->team_name ?: $o->contact_name,
                'start' => round(LocalTime::hourOf($o->starts_at->toImmutable()->setTimezone(LocalTime::tz())), 2),
            ])->values()->all();
    }

    /** JSON: the requests waiting for an answer (the bell at the top of the owner panel), soonest first. */
    public function requests(Request $request)
    {
        $venue = $this->venue($request);
        $rows = Booking::where('venue_id', $venue->id)->where('status', 'pending')->where('starts_at', '>', now())
            ->with('user', 'field')->orderBy('starts_at')->limit(30)->get();

        $choose = BookingPresenter::choosing($rows, $venue);

        return response()->json(['requests' => $rows->map(fn ($b) => BookingPresenter::row($b) + ['field' => BookingPresenter::fieldLabel($b->field)] + ($choose[$b->id] ?? []))->values()]);
    }
}
