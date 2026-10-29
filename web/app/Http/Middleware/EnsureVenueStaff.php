<?php

namespace App\Http\Middleware;

use App\Enums\Sport;
use App\Http\Controllers\Owner\BookingPresenter;
use App\Models\Booking;
use App\Models\Venue;
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
        $picked = $venues->firstWhere('id', $id) ?? $venues->first();
        // loaded on its own: a place taken from a list of several can't load its relations later (lazy loading),
        // which broke pages for owners of two places
        $venue = Venue::findOrFail($picked->id)->setRelation('pivot', $picked->pivot);
        $request->session()->put('venue_id', $venue->id);
        // back in the dashboard: the home page brings them here again (not the player app)
        $request->session()->forget('player_mode');
        // an owner may look at the panel the way their staff see it (the switch); staff are always staff
        $real = $venue->pivot->role;
        $role = $real === 'owner' && $request->session()->get('as_staff') ? 'staff' : $real;
        $request->attributes->set('venue', $venue);
        $request->attributes->set('venueRole', $role);

        // shared with every owner page: the layout (place switcher, pending badge) and the booking form (fields, hours)
        Inertia::share('owner', fn () => [
            'venue' => ['id' => $venue->id, 'name' => $venue->name, 'slug' => $venue->slug],
            'role' => $role,
            'realRole' => $real,
            'venues' => $venues->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->values(),
            'pending' => Booking::query()->where('venue_id', $venue->id)->where('status', 'pending')->where('starts_at', '>', now())->count(),
            'fields' => BookingPresenter::fields($venue->loadMissing(['fields', 'openingHours'])),
            'hours' => BookingPresenter::hours($venue),
            'today' => LocalTime::now()->toDateString(),
            // which club pages the place has (admin → place → features) and its main sport (ratings)
            'features' => $venue->featureList(),
            // the schedule's colours chosen by the place (null: the default ones)
            'colors' => $venue->rules['grid_colors'] ?? null,
            'sport' => ($venue->sports()[0] ?? Sport::Football)->value,
            'nowHour' => round(LocalTime::hourOf(LocalTime::now()), 3),
        ]);

        return $next($request);
    }
}
