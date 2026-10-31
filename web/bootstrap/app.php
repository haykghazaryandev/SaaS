<?php

use App\Http\Middleware\AllowedCountriesOnly;
use App\Http\Middleware\EnsureVenueMenu;
use App\Http\Middleware\EnsureVenueOwner;
use App\Http\Middleware\EnsureVenueStaff;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequirePhone;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\StaffStayInPanel;
use App\Http\Middleware\TouchLastSeen;
use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\TrustProxies as BaseTrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

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
            // accounts without a phone (password, Google, email) add it on the welcome page before anything else
            RequirePhone::class,
            // people who run a place stay in the owner panel (no player pages)
            StaffStayInPanel::class,
        ]);
        // behind ngrok / a load balancer: trust X-Forwarded-* so links use https and the right host.
        // Our TrustProxies also trusts Railway's edge hop, so $request->ip() is the visitor, not Railway.
        $middleware->trustProxies(at: '*');
        $middleware->replace(BaseTrustProxies::class, TrustProxies::class);
        // only visitors from ALLOWED_COUNTRIES (off when unset), after TrustProxies has found their address
        $middleware->append(AllowedCountriesOnly::class);
        $middleware->alias(['venue.staff' => EnsureVenueStaff::class, 'venue.owner' => EnsureVenueOwner::class, 'venue.menu' => EnsureVenueMenu::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // pages (not JSON, not the admin panel): our error page instead of the framework's white one; while
        // debugging, a crash still shows the details
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();
            $page = in_array($status, [403, 404], true) || (in_array($status, [500, 503], true) && ! config('app.debug'));
            // the admin panel keeps its own pages, except "no access" for people who aren't admins
            $admin = $request->is('admin', 'admin/*', 'livewire/*') && ! ($status === 403 && ! $request->user()?->is_admin);
            if (! $page || $request->expectsJson() || $admin) {
                return $response;
            }

            return Inertia::render('Error', ['status' => $status])->toResponse($request)->setStatusCode($status);
        });
    })->create();
