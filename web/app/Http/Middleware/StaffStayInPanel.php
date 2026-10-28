<?php

namespace App\Http\Middleware;

use App\Models\Tournament;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * People who run a place (owners and staff, admins with a place too) work only in the owner panel: the player pages
 * (home, places, bookings, games, friends, tournaments, cards, profiles) send them there. A link to a tournament of
 * their own place opens its owner page. Messages, the profile settings, the bell and the log-in pages stay open.
 */
class StaffStayInPanel
{
    /** Route names (or their beginnings) of the player app. */
    public const PLAYER = ['home', 'alerts.', 'bookings.', 'friends.', 'games.', 'me.bookings', 'me.cards', 'me.tournaments', 'places.', 'rate.', 'tournament.', 'users.show'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $name = (string) $request->route()?->getName();
        // the chat pages: in the panel (its menu stays)
        if ($user && in_array($name, ['messages.index', 'messages.show'], true) && $request->isMethod('GET') && $user->venues()->exists()) {
            return $name === 'messages.index' ? redirect()->route('owner.messages') : redirect()->route('owner.messages.show', $request->route('conversation'));
        }
        if (! $user || $name === '' || ! self::isPlayerRoute($name) || ! $user->venues()->exists()) {
            return $next($request);
        }
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => __('owner.panel_only')], 403);
        }
        // their own tournament's link (shared in Instagram, in a notification): its page in the panel
        if (str_starts_with($name, 'tournament.') && ($t = Tournament::where('code', (string) $request->route('code'))->first())
            && $user->venues()->whereKey($t->venue_id)->exists()) {
            $request->session()->put('venue_id', $t->venue_id);

            return redirect()->route('owner.tournaments.show', $t->id);
        }

        return redirect()->route('owner.start');
    }

    public static function isPlayerRoute(string $name): bool
    {
        foreach (self::PLAYER as $p) {
            if ($name === $p || (str_ends_with($p, '.') && str_starts_with($name, $p))) {
                return true;
            }
        }

        return false;
    }
}
