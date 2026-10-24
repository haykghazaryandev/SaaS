<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;

/**
 * Language of the page:
 * - ?lang= (the EN / ՀԱՅ / РУ switch): remembered; for a logged-in person saved on the account as their choice;
 * - logged in: the account's language, so the app and the bot's messages always speak the same language;
 * - guest: the remembered choice, else the phone / browser language (Accept-Language), else Armenian.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locales = config('app.locales');
        $user = $request->user();
        $lang = $request->query('lang');
        if (in_array($lang, $locales, true)) {
            $request->session()->put('locale', $lang);
            if ($user && ($user->locale !== $lang || ! $user->locale_chosen_at)) {
                $user->forceFill(['locale' => $lang, 'locale_chosen_at' => now()])->save();
            }
        }
        $locale = $user?->preferredLocale()
            ?? $request->session()->get('locale')
            ?? Locales::fromHeader($request->header('Accept-Language'))
            ?? 'hy';   // not config('app.locale'): setLocale() changes it during a request
        app()->setLocale(in_array($locale, $locales, true) ? $locale : 'hy');

        return $next($request);
    }
}
