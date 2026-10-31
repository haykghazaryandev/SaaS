<?php

namespace App\Http\Middleware;

use App\Domain\Access\IpCountry;
use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only visitors from config('access.countries') get in; everyone else gets a 403 page in hy / ru / en.
 * Off while the list is empty. An address whose country is unknown (private network, not in the database,
 * no database) is let through: blocking on "unknown" would lock out the health check and real users alike.
 * Runs on every request (global middleware), after TrustProxies has found the client's address.
 */
class AllowedCountriesOnly
{
    public function __construct(private readonly IpCountry $countries) {}

    public function handle(Request $request, Closure $next): Response
    {
        $allowed = (array) config('access.countries');
        if ($allowed === [] || $request->is(...(array) config('access.except'))) {
            return $next($request);
        }

        $country = $this->countries->countryOf($request->ip());
        if ($country === null || in_array($country, $allowed, true)) {
            return $next($request);
        }

        $first = Locales::fromHeader($request->header('Accept-Language')) ?? 'hy';
        $locales = array_values(array_unique([$first, 'hy', 'ru', 'en']));

        return response()
            ->view('access.blocked', ['locales' => $locales], 403)
            ->header('Cache-Control', 'no-store, private');
    }
}
