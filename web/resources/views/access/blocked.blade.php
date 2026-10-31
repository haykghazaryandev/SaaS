{{-- 403 for a visitor outside the allowed countries (App\Http\Middleware\AllowedCountriesOnly): every language at once,
     the browser's first. Standalone: no Vite, no session, nothing that the blocked request should not reach. --}}
<!DOCTYPE html>
<html lang="{{ $locales[0] }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#0a0d0b">
        <meta name="robots" content="noindex">
        <title>ZoneSpot</title>
        <style>
            :root { color-scheme: dark; }
            * { box-sizing: border-box; }
            body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px;
                   background: #0a0d0b; color: #eef5ec; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans Armenian", sans-serif; }
            main { width: 100%; max-width: 440px; }
            .brand { margin: 0 0 24px; font-weight: 800; letter-spacing: .02em; color: #c8ff3d; }
            section { padding: 16px 0; border-top: 1px solid rgba(220, 255, 220, 0.15); }
            section:first-of-type { border-top: 0; padding-top: 0; }
            h1 { margin: 0 0 6px; font-size: 20px; line-height: 1.3; }
            h2 { margin: 0 0 4px; font-size: 16px; line-height: 1.3; color: #eef5ec; }
            p { margin: 0; color: #9aa69b; }
            footer { margin-top: 24px; font-size: 12px; color: #657066; }
            footer a { color: inherit; }
        </style>
    </head>
    <body>
        <main>
            <p class="brand">ZoneSpot</p>
            @foreach ($locales as $i => $locale)
                <section lang="{{ $locale }}">
                    @if ($i === 0)
                        <h1>{{ __('common.blocked_country.title', [], $locale) }}</h1>
                    @else
                        <h2>{{ __('common.blocked_country.title', [], $locale) }}</h2>
                    @endif
                    <p>{{ __('common.blocked_country.text', [], $locale) }}</p>
                </section>
            @endforeach
            <footer><a href="https://db-ip.com" rel="noopener">IP geolocation by DB-IP</a></footer>
        </main>
    </body>
</html>
