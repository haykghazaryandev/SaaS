<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Admin panel language: ?lang= → the admin's own language → English. */
class AdminLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locales = config('app.locales');
        if (in_array($request->query('lang'), $locales, true)) {
            $request->session()->put('admin_locale', $request->query('lang'));
        }
        $locale = $request->session()->get('admin_locale') ?? $request->user()?->locale ?? 'en';
        app()->setLocale(in_array($locale, $locales, true) ? $locale : 'en');

        return $next($request);
    }
}
