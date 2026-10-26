<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Owner\BookingPresenter;
use App\Models\Booking;
use App\Support\LocalTime;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Owner web: only users who own or work at a place. The current place is kept in the session. */
class EnsureVenueStaff
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->guest(route('login', ['to' => 'owner']));
        }
        $venues = $user->venues()->get();
        if ($venues->isEmpty()) {
            abort(403, __('owner.no_access'));
        }
        $id = (int) $request->session()->get('venue_id');
        $venue = $venues->firstWhere('id', $id) ?? $venues->first();
        $request->session()->put('venue_id', $venue->id);
        $request->attributes->set('venue', $venue);
        $request->attributes->set('venueRole', $venue->pivot->role);

        // shared with every owner page: the layout (place switcher, pending badge) and the booking form (fields, hours)
        Inertia::share('owner', fn () => [
            'venue' => ['id' => $venue->id, 'name' => $venue->name, 'slug' => $venue->slug],
            'role' => $venue->pivot->role,
            'venues' => $venues->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->values(),
            'pending' => Booking::query()->where('venue_id', $venue->id)->where('status', 'pending')->where('starts_at', '>', now())->count(),
            'fields' => BookingPresenter::fields($venue->loadMissing(['fields', 'openingHours'])),
            'hours' => BookingPresenter::hours($venue),
            'today' => LocalTime::now()->toDateString(),
            // which club pages the place has (admin → place → features) and its main sport (ratings)
            'features' => $venue->featureList(),
            'sport' => ($venue->sports()[0] ?? \App\Enums\Sport::Football)->value,
            'nowHour' => round(LocalTime::hourOf(LocalTime::now()), 3),
        ]);

        return $next($request);
    }
}
