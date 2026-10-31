<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#0a0d0b">
        <title inertia>ZoneSpot</title>
        {{-- installable from the phone browser ("Add to Home Screen"): the manifest, the icons, full screen on iOS; see resources/js/lib/pwa.ts --}}
        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="ZoneSpot">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Unbounded:wght@500;600;700&family=Manrope:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500&family=Noto+Sans+Armenian:wght@400;500;600;700&display=swap">
        {{-- the theme picked on this device (Profile: Dark / Light / Auto = the phone's), set before the page paints --}}
        <script>
            try {
                var t = localStorage.getItem('zs.theme') || 'dark';
                if (t === 'auto') t = matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
                if (t === 'light') {
                    document.documentElement.dataset.theme = 'light';
                    document.querySelector('meta[name=theme-color]').setAttribute('content', '#f6f8f3');
                }
            } catch (e) {}
        </script>
        {{-- Telegram Mini App: Telegram opens us with #tgWebAppData…; only then load its script (before the app starts) --}}
        <script>
            try {
                if (/tgWebAppData|tgWebAppPlatform/.test(location.hash) || sessionStorage.getItem('bb.tg')) {
                    sessionStorage.setItem('bb.tg', '1');
                    document.write('<script src="https://telegram.org/js/telegram-web-app.js"><\/script>');
                }
            } catch (e) {}
        </script>
        {{-- the interface texts (the translations table): the app fetches them before it mounts; cached by version --}}
        <link rel="preload" as="fetch" crossorigin href="{{ route('lang', app()->getLocale()) }}?v={{ app(\App\Support\Translations::class)->version() }}">
        @routes
        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
