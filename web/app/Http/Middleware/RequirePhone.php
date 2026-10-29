<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Booking needs a confirmed phone (the place calls this number; no-shows are counted on it): booking, starting or
 * joining a game, registering for a tournament, getting a club card. An account without one (email, Google) can look
 * around freely; these actions send it to "Confirm your phone" (with Telegram), and back to the page afterwards.
 */
class RequirePhone
{
    public const NEEDS = ['bookings.store', 'games.store', 'games.join', 'games.book', 'tournament.register', 'places.card'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $name = (string) $request->route()?->getName();
        if (! $user || $user->phone_verified_at || ! in_array($name, self::NEEDS, true)) {
            return $next($request);
        }
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => __('auth.phone.confirm_first'), 'redirect' => route('phone.confirm')], 409);
        }
        // back to the page the action was on once the phone is confirmed
        $request->session()->put('url.intended', $request->isMethod('GET') ? $request->fullUrl() : url()->previous());

        return redirect()->route('phone.confirm');
    }
}
