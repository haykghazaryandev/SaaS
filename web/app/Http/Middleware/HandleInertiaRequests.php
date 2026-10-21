<?php

namespace App\Http\Middleware;

use App\Http\Presenters\SocialCounts;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'locale' => app()->getLocale(),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'avatar' => $user->avatarUrl(),
                    'phone' => Phone::format($user->phone),
                    'username' => $user->username,
                    'registered' => $user->isRegistered(),
                    'telegram' => $user->hasTelegram(),
                    'isAdmin' => (bool) $user->is_admin,
                    'isOwner' => fn () => $user->venues()->exists(),
                ] : null,
            ],
            // header badges: unread notifications, unread chat messages, friend requests waiting (lazy: only computed when sent)
            'counts' => fn () => $user ? app(SocialCounts::class)->for($user) : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            // development only: the last login code, so you can log in without Telegram
            'devCode' => function () use ($request) {
                if (! config('app.debug')) {
                    return null;
                }
                $key = $request->session()->get('otp_email') ?? (config('services.otp_driver') === 'log' ? $request->session()->get('otp_phone') : null);

                return $key ? Cache::get('dev-otp:'.$key) : null;
            },
        ];
    }
}
