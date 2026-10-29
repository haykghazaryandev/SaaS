<?php

namespace App\Http\Middleware;

use App\Enums\VenueRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After venue.staff: pages and actions only for the place's owner (money, ZoneSpot fee, all cards, the shop's products,
 * settings, the team, making / changing / cancelling tournaments). Staff opening such a page go back to their start.
 */
class EnsureVenueOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('venueRole') === VenueRole::Owner->value) {
            return $next($request);
        }
        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            return redirect()->route('owner.start')->with('error', __('owner.error.owner_only'));
        }
        abort(403, __('owner.error.owner_only'));
    }
}
