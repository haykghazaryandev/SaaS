<?php

/*
| Who may open the app, by the country of their IP address (App\Http\Middleware\AllowedCountriesOnly).
|
| ALLOWED_COUNTRIES: ISO 3166-1 alpha-2 codes, comma separated ("AM"). Empty or unset = no country rule at all,
| so an environment without the variable behaves exactly as before.
*/

return [

    'countries' => array_values(array_filter(array_map(
        fn (string $code) => strtoupper(trim($code)),
        explode(',', (string) env('ALLOWED_COUNTRIES', '')),
    ))),

    // Country database (.mmdb, DB-IP "IP to Country Lite", CC BY 4.0), downloaded into the image by web/Dockerfile.
    // Missing or unreadable file = the rule is not enforced (a warning is logged): refusing everyone because a lookup
    // file is missing would take the whole app offline.
    'geoip_database' => env('GEOIP_DATABASE', storage_path('app/geoip/dbip-country-lite.mmdb')),

    // Paths a country never stops: the health check, and the Telegram bot webhook (Telegram calls it from its own
    // servers abroad; the secret in the URL protects it).
    'except' => ['up', 'telegram/webhook/*'],

    // Railway's internal proxy network. A request whose immediate peer is here came through Railway's edge, which
    // writes "X-Forwarded-For: <client>, <edge>" and drops whatever the client sent (App\Http\Middleware\TrustProxies).
    'proxy_network' => env('TRUSTED_PROXY_NETWORK', '100.64.0.0/10'),

];
