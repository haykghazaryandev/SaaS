<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A logged-in account without a phone (username + password, Google, email code) must add it on the welcome page
 * before anything else: booking, games, chats. The place calls this number about a booking.
 * Open: the welcome page itself, logging in / out, the interface texts, the admin panel and the Telegram webhook.
 */
class RequirePhone
{
    private const OPEN = ['welcome', 'welcome.store', 'logout', 'lang', 'login.', 'telegram.webhook', 'filament.', 'livewire.', 'default-livewire.'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $name = (string) $request->route()?->getName();
        if (! $user || $user->phone || $name === '' || self::isOpen($name)) {
            return $next($request);
        }
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => __('auth.phone.required'), 'redirect' => route('welcome')], 409);
        }
        // back to this page (or, after a form, to the page the form was on) once the phone is added
        $request->session()->put('url.intended', $request->isMethod('GET') ? $request->fullUrl() : url()->previous());

        return redirect()->route('welcome')->with('error', __('auth.phone.required'));
    }

    private static function isOpen(string $name): bool
    {
        foreach (self::OPEN as $open) {
            if ($name === $open || (str_ends_with($open, '.') && str_starts_with($name, $open))) {
                return true;
            }
        }

        return false;
    }
}
