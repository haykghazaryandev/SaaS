<?php

namespace App\Http\Controllers\Owner;

use App\Models\Booking;
use App\Support\LocalTime;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Today: KPIs, requests waiting, next games and the occupancy of the next 7 days. */
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
        $requests = $pending->with('user:id,name,phone,no_show_count')->orderBy('starts_at')->limit(6)->get();

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
            'requests' => $requests->map(fn ($b) => BookingPresenter::row($b))->values(),
            'next' => $holding->filter(fn (Booking $b) => $b->ends_at->isFuture())->take(7)->map(fn ($b) => BookingPresenter::row($b))->values(),
            'chart' => $chart,
            'nowHour' => round(LocalTime::hourOf(LocalTime::now()), 3),
        ]);
    }
}
