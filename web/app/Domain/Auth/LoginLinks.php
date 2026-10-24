<?php

namespace App\Domain\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Links in our Telegram messages that open Bookball already logged in (the Telegram chat is the person's own).
 * Signed, short-lived and single-use; after use the link still opens the page, through the login if needed.
 * Login message: 30 minutes. Booking and other notices: 3 days.
 */
class LoginLinks
{
    public const LOGIN_MINUTES = 30;

    public const NOTICE_MINUTES = 3 * 24 * 60;

    /** $path: a page of this site, e.g. "/me/bookings". */
    public function make(User $user, string $path = '/', int $minutes = self::LOGIN_MINUTES): string
    {
        // the path is signed (not the host), and the link always uses APP_URL: the same from the queue, the webhook and behind proxies
        $signed = URL::temporarySignedRoute('login.link', now()->addMinutes($minutes), ['user' => $user->id, 'to' => self::safePath($path), 'n' => Str::random(12)], absolute: false);

        return rtrim((string) config('app.url'), '/').$signed;
    }

    /** The user of a valid, unused link (marks it used), or null. */
    public function consume(Request $request): ?User
    {
        if (! $request->hasValidSignature(absolute: false)) {
            return null;
        }
        $expires = (int) $request->query('expires');
        if (! Cache::add('login-link:'.$request->query('n'), 1, max(60, $expires - time()))) {
            return null;   // used already
        }
        $user = User::find($request->route('user'));

        return $user && ! $user->isBlocked() ? $user : null;
    }

    /** Only paths on this site ("/…", not "//other.site"). */
    public static function safePath(?string $path): string
    {
        $path = (string) $path;

        return str_starts_with($path, '/') && ! str_starts_with($path, '//') ? $path : '/';
    }
}
