<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#0a0d0b">
        <title inertia>Bookball</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Unbounded:wght@500;600;700&family=Manrope:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500&family=Noto+Sans+Armenian:wght@400;500;600;700&display=swap">
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
