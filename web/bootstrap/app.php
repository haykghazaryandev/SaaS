<?php

use App\Http\Middleware\EnsureVenueStaff;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            Illuminate\Support\Facades\Route::middleware('web')->group(base_path('routes/owner.php'));
            Illuminate\Support\Facades\Route::middleware('web')->group(base_path('routes/telegram.php'));
            foreach (['social', 'games', 'chat', 'alerts'] as $file) {
                Illuminate\Support\Facades\Route::middleware('web')->group(base_path("routes/{$file}.php"));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
        // behind ngrok / a load balancer: trust X-Forwarded-* so links use https and the right host
        $middleware->trustProxies(at: '*');
        $middleware->alias(['venue.staff' => EnsureVenueStaff::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
