<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "online" / "last seen" in chats: every web request of a logged-in user moves users.last_seen_at,
 * but writes at most once a minute (User::touchLastSeen compares the loaded value, no extra query).
 */
class TouchLastSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->user()?->touchLastSeen();

        return $next($request);
    }
}
