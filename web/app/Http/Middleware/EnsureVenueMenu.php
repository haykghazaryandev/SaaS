<?php

namespace App\Http\Middleware;

use App\Domain\Venues\Menus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After venue.staff: a menu the owner turned off for staff (Settings → Menus) is closed for them, not only hidden.
 * Staff opening it go back to their start. The owner always gets in.
 */
class EnsureVenueMenu
{
    public function handle(Request $request, Closure $next, string $menu): Response
    {
        $venue = $request->attributes->get('venue');
        if ($request->attributes->get('venueRole') === 'owner' || Menus::staffSees($venue, $menu)) {
            return $next($request);
        }
        $start = Menus::start($venue, 'staff');
        if ($request->isMethod('GET') && ! $request->expectsJson() && $start !== $request->route()?->getName()) {
            return redirect()->route($start)->with('error', __('owner.error.menu_off'));
        }
        abort(403, __('owner.error.menu_off'));
    }
}
