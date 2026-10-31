<?php

namespace App\Http\Middleware;

use App\Domain\Chat\Chat;
use App\Domain\Venues\Menus;
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
        // one owner per place; what staff see and may do is set by the owner (Settings → Menus)
        $role = $venue->pivot->role;
        $request->attributes->set('venue', $venue);
        $request->attributes->set('venueRole', $role);

        // shared with every owner page: the layout (place switcher, pending badge) and the booking form (fields, hours)
        Inertia::share('owner', fn () => [
            'venue' => ['id' => $venue->id, 'name' => $venue->name, 'slug' => $venue->slug],
            'role' => $role,
            // Settings → Menus: the two panels' menus and staff's levels, with the menus the place has
            'menus' => Menus::of($venue) + ['available' => Menus::available($venue)],
            'venues' => $venues->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->values(),
            // unread messages in this place's chats (the Chats badge of the panel; the player app counts all of mine)
            'unreadChats' => app(Chat::class)->unreadLoud($user, $venue->id),
            'pending' => Booking::query()->where('venue_id', $venue->id)->where('status', 'pending')->where('starts_at', '>', now())->count(),
            'fields' => BookingPresenter::fields($venue->loadMissing(['fields', 'openingHours'])),
            'hours' => BookingPresenter::hours($venue),
            'today' => LocalTime::now()->toDateString(),
            // which club pages the place has (admin → place → features) and its main sport (ratings)
            'features' => $venue->featureList(),
            'sport' => ($venue->sports()[0] ?? Sport::Football)->value,
            'nowHour' => round(LocalTime::hourOf(LocalTime::now()), 3),
        ]);

        return $next($request);
    }
}
