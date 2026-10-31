<?php

namespace App\Http\Middleware;

use App\Domain\Venues\Menus;
use App\Enums\VenueRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After venue.staff: pages and actions only for the place's owner (money, ZoneSpot fee, the shop's products, settings,
 * the team, making / changing / cancelling tournaments). With a menu and a level ("venue.owner:shop,edit") staff get in
 * too when the owner gave them that much in Settings → Menus. Staff opening such a page go back to their start.
 */
class EnsureVenueOwner
{
    public function handle(Request $request, Closure $next, ?string $menu = null, ?string $level = null): Response
    {
        if ($request->attributes->get('venueRole') === VenueRole::Owner->value
            || ($menu && Menus::staffMay($request->attributes->get('venue'), $menu, $level))) {
            return $next($request);
        }
        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            return redirect()->route('owner.start')->with('error', __('owner.error.owner_only'));
        }
        abort(403, __('owner.error.owner_only'));
    }
}
