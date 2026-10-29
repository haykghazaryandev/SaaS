<?php

namespace App\Http\Controllers\Owner;

use App\Models\Booking;
use App\Support\LocalTime;
use Database\Seeders\RequestCasesSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;

/** Today: today's bookings, the requests waiting, today's trainings and how full the courts are. Requests: their own page. */
class TodayController extends OwnerController
{
    public function __invoke(Request $request, VenueStats $stats)
    {
        $venue = $this->venue($request)->load(['fields', 'openingHours']);
        $today = VenueStats::today();
        $todays = $stats->bookings($venue, $today, $today->addDay());
        $holding = $todays->filter(fn (Booking $b) => $b->status->holdsTime());

        return Inertia::render('owner/Today', [
            'date' => $today->toDateString(),
            'me' => $request->user()->name,
            'kpi' => [
                'today' => $holding->count(),
                'todayAccepted' => $holding->where('status.value', 'accepted')->count(),
                'todayPending' => $holding->where('status.value', 'pending')->count(),
                'pending' => $stats->pendingQuery($venue)->count(),
                'trainings' => $holding->where('status.value', 'accepted')->where('purpose', 'training')->count(),
                'occupancy' => $venue->fields->mapWithKeys(fn ($f) => [$f->id => $stats->occupancy($venue, $f->id, $today, $todays)])->all(),
            ],
        ]);
    }

    /**
     * The requests waiting for an answer, soonest first; several for the same court and time together, the best
     * choice first. JSON for the bell at the top of the panel; the Requests page in the browser (every request, with
     * the requests it clashes with).
     */
    public function requests(Request $request, VenueStats $stats)
    {
        $venue = $this->venue($request);
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            $rows = Booking::where('venue_id', $venue->id)->where('status', 'pending')->where('starts_at', '>', now())
                ->with('user', 'field')->orderBy('starts_at')->limit(30)->get();
            $choose = BookingPresenter::choosing($rows, $venue);

            return response()->json(['requests' => self::bestFirst($rows, $choose)->map(fn ($b) => BookingPresenter::row($b) + ['field' => BookingPresenter::fieldLabel($b->field)] + ($choose[$b->id] ?? []))->values()]);
        }
        $pending = $stats->pendingQuery($venue);
        $rows = (clone $pending)->with('user:id,name,phone,no_show_count')->orderBy('starts_at')->limit(200)->get();
        // every open request, to flag requests that clash with another one (same field, or the same player on both fields)
        $open = $pending->get(['id', 'field_id', 'user_id', 'contact_phone', 'contact_name', 'team_name', 'starts_at', 'ends_at']);
        $choose = BookingPresenter::choosing($rows, $venue);

        return Inertia::render('owner/Requests', [
            'canDemo' => ! app()->isProduction() && $venue->slug === 'pingpong-land',
            'date' => VenueStats::today()->toDateString(),
            'requests' => self::bestFirst($rows, $choose)->map(fn ($b) => [...BookingPresenter::row($b), 'conflicts' => $this->conflicts($b, $open)] + ($choose[$b->id] ?? []))->values(),
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

    /** Local / staging: the test requests at Pingpong.land again (several for the same time, a training …). */
    public function demoRequests(Request $request)
    {
        abort_if(app()->isProduction(), 404);
        $this->ensureOwner($request);
        Artisan::call('db:seed', ['--class' => RequestCasesSeeder::class, '--force' => true]);

        return back()->with('success', __('owner.requests.demo_added'));
    }

    /** Soonest first; requests for the same court and time together, the best choice first. */
    private static function bestFirst($rows, array $choose)
    {
        return $rows->sortBy([fn ($a, $b) => $a->starts_at <=> $b->starts_at, fn ($a, $b) => $a->field_id <=> $b->field_id,
            fn ($a, $b) => ($choose[$a->id]['rank'] ?? 0) <=> ($choose[$b->id]['rank'] ?? 0)])->values();
    }
}
