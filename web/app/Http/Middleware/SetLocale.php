<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Language: ?lang= (remembered in the session) → the user's setting → Armenian. */
class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locales = config('app.locales');
        if (in_array($request->query('lang'), $locales, true)) {
            $request->session()->put('locale', $request->query('lang'));
            if ($request->user() && $request->user()->locale !== $request->query('lang')) {
                $request->user()->forceFill(['locale' => $request->query('lang')])->save();
            }
        }
        $locale = $request->session()->get('locale') ?? $request->user()?->locale ?? config('app.locale');
        app()->setLocale(in_array($locale, $locales, true) ? $locale : 'hy');

        return $next($request);
    }
}
