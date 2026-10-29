<?php

use App\Http\Middleware\EnsureVenueOwner;
use App\Http\Middleware\EnsureVenueStaff;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\StaffStayInPanel;
use App\Http\Middleware\TouchLastSeen;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            Route::middleware('web')->group(base_path('routes/owner.php'));
            Route::middleware('web')->group(base_path('routes/telegram.php'));
            foreach (['social', 'games', 'chat', 'alerts'] as $file) {
                Route::middleware('web')->group(base_path("routes/{$file}.php"));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            SetLocale::class,
            TouchLastSeen::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // people who run a place stay in the owner panel (no player pages)
            StaffStayInPanel::class,
        ]);
        // behind ngrok / a load balancer: trust X-Forwarded-* so links use https and the right host
        $middleware->trustProxies(at: '*');
        $middleware->alias(['venue.staff' => EnsureVenueStaff::class, 'venue.owner' => EnsureVenueOwner::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
