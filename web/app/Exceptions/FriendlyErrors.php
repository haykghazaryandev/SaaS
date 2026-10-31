<?php

namespace App\Exceptions;

use App\Http\Middleware\SetLocale;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Never Laravel's bare error pages for the common cases, always in the visitor's language:
 * - 404 (no such page / record): a page in the app's look; inside the app (Inertia) a link to it opens it as a page,
 *   an action on it (a form) stays on the page with a toast;
 * - 419 (a form open too long: the session / CSRF token expired): back to the page, refreshed, with a toast;
 * - 429 (a route's request limit): a toast in the app, a page on a full page load.
 * These can answer before SetLocale runs (the CSRF check, the route bindings), so the language is worked out here.
 * JSON requests get {message}. The admin panel (Livewire) keeps its own handling.
 */
class FriendlyErrors
{
    public static function render(Throwable $e, Request $request): ?Response
    {
        $status = match (true) {
            $e instanceof ThrottleRequestsException => 429,
            $e instanceof TokenMismatchException => 419,
            $e instanceof ModelNotFoundException => 404,
            $e instanceof HttpExceptionInterface && in_array($e->getStatusCode(), [404, 419, 429], true) => $e->getStatusCode(),
            default => null,
        };
        if (! $status || $request->hasHeader('X-Livewire')) {
            return null;
        }
        app()->setLocale(SetLocale::detect($request));
        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];
        $text = match ($status) {
            429 => __('common.too_many', ['min' => max(1, (int) ceil(((int) ($headers['Retry-After'] ?? 60)) / 60))]),
            419 => __('common.page_expired'),
            404 => __('common.not_found'),
        };

        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return response()->json(['message' => $text], $status, $headers);
        }
        // 303: a PUT / PATCH / DELETE comes back as a GET (these answers come before Inertia's middleware could fix it)
        if ($status === 419 && ! $request->isMethod('GET')) {
            return back(303)->with('error', $text);
        }
        if ($request->hasHeader('X-Inertia')) {
            // a link inside the app: open the 404 as a page, not in Inertia's pop-up
            if ($status === 404 && $request->isMethod('GET')) {
                return Inertia::location($request->fullUrl());
            }

            return back(303)->withErrors([$status === 429 ? 'throttle' : 'error' => $text]);
        }

        return response()->view('errors.'.$status, ['text' => $text], $status, $headers);
    }
}
