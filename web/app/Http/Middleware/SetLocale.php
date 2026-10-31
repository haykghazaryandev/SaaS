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
        $locale = self::detect($request);
        // ?lang=… is a choice: remembered for the visitor and saved on the account
        if ($request->query('lang') === $locale) {
            $request->session()->put('locale', $locale);
            $user = $request->user();
            if ($user && ($user->locale !== $locale || ! $user->locale_chosen_at)) {
                $user->forceFill(['locale' => $locale, 'locale_chosen_at' => now()])->save();
            }
        }
        app()->setLocale($locale);

        return $next($request);
    }

    /** The language for this request, saving nothing. FriendlyErrors uses it too: errors can come before this middleware. */
    public static function detect(Request $request): string
    {
        $locales = config('app.locales');
        $lang = $request->query('lang');
        $session = $request->hasSession() ? $request->session() : null;
        $locale = (in_array($lang, $locales, true) ? $lang : null)
            ?? ($session ? $request->user()?->preferredLocale() : null)   // the account lives in the session
            ?? $session?->get('locale')
            ?? Locales::fromHeader($request->header('Accept-Language'))
            ?? 'hy';   // not config('app.locale'): setLocale() changes it during a request

        return in_array($locale, $locales, true) ? $locale : 'hy';
    }
}
