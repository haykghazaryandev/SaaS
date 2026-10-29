<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Venues\Availability;
use App\Models\Booking;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Schedule, made to read at a glance: from now on (the default: playing now and which courts are free, the next hour,
 * later today, tomorrow, later this week) or one day picked (from the 7 days on top, or the calendar): its bookings in
 * time order and each court's free time. On top: how full each of the next 7 days is. Only what the place said yes to:
 * requests waiting for an answer are on Requests.
 */
class ScheduleController extends OwnerController
{
    public function __invoke(Request $request, Availability $availability, VenueStats $stats)
    {
        $data = $request->validate(['date' => 'nullable|date_format:Y-m-d']);
        $venue = $this->venue($request)->load(['fields', 'openingHours']);
        $today = VenueStats::today();
        $date = isset($data['date']) ? LocalTime::day($data['date']) : null;
        // the place's answer given (or played, a walk-in …); not the open requests
        $said = fn (Booking $b) => in_array(BookingPresenter::kind($b), ['accepted', 'owner'], true);

        // the next 7 days: how full each one is
        $week = $stats->bookings($venue, $today, $today->addDays(7));
        $days = collect(range(0, 6))->map(fn ($i) => $today->addDays($i))->map(fn ($d) => [
            'date' => $d->toDateString(),
            'occupancy' => $venue->fields->mapWithKeys(fn ($f) => [$f->id => $stats->occupancy($venue, $f->id, $d, $week)])->all(),
            'count' => $week->filter($said)->filter(fn (Booking $b) => $b->starts_at->setTimezone(LocalTime::tz())->toDateString() === $d->toDateString())->count(),
        ])->values();

        $props = [
            'view' => $date ? 'day' : 'now',
            'date' => ($date ?? $today)->toDateString(),
            'today' => $today->toDateString(),
            'nowHour' => round(LocalTime::hourOf(LocalTime::now()), 3),
            'days' => $days,
        ];
        if ($date) {
            $props['day'] = [
                'rows' => $stats->bookings($venue, $date, $date->addDay())->filter($said)->map(fn ($b) => BookingPresenter::row($b))->values(),
                'hours' => $availability->hours($venue, $date),
            ];
        } else {
            $props['upcoming'] = [
                'rows' => $week->filter($said)->map(fn ($b) => BookingPresenter::row($b))->values(),
                'hours' => $availability->hours($venue, $today),
            ];
        }

        return Inertia::render('owner/Schedule', $props);
    }
}
