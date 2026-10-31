{{-- Error pages (404, 419, 429) in the app's dark + lime look, in the visitor's language (App\Exceptions\FriendlyErrors).
     Self-contained: no Vite build needed, so they show even when the app's assets do not. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0a0d0b">
    <meta name="robots" content="noindex">
    <title>@yield('title') · ZoneSpot</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: #0a0d0b; color: #eef5ec;
            font-family: Manrope, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Noto Sans Armenian', Arial, sans-serif; }
        main { width: 100%; max-width: 420px; text-align: center; }
        .logo { display: inline-flex; align-items: center; gap: 12px; font-weight: 700; font-size: 20px; color: #eef5ec; text-decoration: none; margin-bottom: 24px; }
        .logo img { width: 44px; height: 44px; border-radius: 11px; display: block; }
        .card { background: #141915; border: 1px solid rgba(220, 255, 220, 0.12); border-radius: 22px; padding: 32px 24px; }
        .code { display: inline-block; margin-bottom: 12px; padding: 4px 12px; border-radius: 999px; background: rgba(200, 255, 61, 0.1); color: #c8ff3d; font-weight: 800; font-size: 13px; letter-spacing: 0.08em; }
        h1 { margin: 0 0 10px; font-size: 24px; line-height: 1.25; }
        p { margin: 0 0 24px; color: #9aa69b; font-size: 15px; line-height: 1.6; }
        .acts { display: flex; flex-direction: column; gap: 10px; }
        .btn { display: block; width: 100%; padding: 14px 20px; border-radius: 999px; font-weight: 800; font-size: 15px; text-decoration: none; border: 0; cursor: pointer; font-family: inherit; }
        .btn.main { background: linear-gradient(135deg, #d9ff5c 0%, #5ef0a8 100%); color: #0d1404; }
        .btn.ghost { background: transparent; color: #eef5ec; border: 1px solid rgba(220, 255, 220, 0.15); }
    </style>
</head>
<body>
    <main>
        <a class="logo" href="/"><img src="/images/email/logo-mark@2x.png" alt="">ZoneSpot</a>
        <div class="card">
            <span class="code">@yield('code')</span>
            <h1>@yield('title')</h1>
            <p>@yield('text')</p>
            <div class="acts">@yield('actions')</div>
        </div>
    </main>
</body>
</html>
