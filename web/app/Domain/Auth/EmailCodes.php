<?php

namespace App\Domain\Auth;

use App\Mail\LoginCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Backup login for people without Telegram: a 4-digit code by email (free with Resend / Brevo / SES).
 * Same limits as the phone codes: 3 codes per address per 10 min, 10 per IP per hour, 5 tries, 10 minutes to use it.
 */
class EmailCodes
{
    public function send(string $email, string $ip): void
    {
        $email = strtolower(trim($email));
        foreach (['otp-email:'.$email => [3, 600], 'otp-ip:'.$ip => [10, 3600]] as $key => [$max, $decay]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages(['email' => __('auth.code.too_many', ['min' => ceil(RateLimiter::availableIn($key) / 60)])]);
            }
            RateLimiter::hit($key, $decay);
        }
        $code = (string) random_int(1000, 9999);
        Cache::put('email-otp:'.$email, ['hash' => Hash::make($code), 'tries' => 0], now()->addMinutes(10));
        if (config('app.debug')) {
            Cache::put('dev-otp:'.$email, $code, now()->addMinutes(10));   // shown on the login page in development
        }
        Mail::to($email)->locale(app()->getLocale())->send(new LoginCodeMail($code));
    }

    /** Checks the code; returns the account of this email (created on the first login). */
    public function verify(string $email, string $code): User
    {
        $email = strtolower(trim($email));
        $data = Cache::get('email-otp:'.$email);
        if (! $data) {
            throw ValidationException::withMessages(['code' => __('auth.code.expired')]);
        }
        if ($data['tries'] >= 5) {
            Cache::forget('email-otp:'.$email);
            throw ValidationException::withMessages(['code' => __('auth.code.too_many_tries')]);
        }
        if (! Hash::check($code, $data['hash'])) {
            $data['tries']++;
            Cache::put('email-otp:'.$email, $data, now()->addMinutes(10));
            throw ValidationException::withMessages(['code' => __('auth.code.wrong', ['n' => 5 - $data['tries']])]);
        }
        Cache::forget('email-otp:'.$email);
        $user = User::withTrashed()->firstOrCreate(['email' => $email], ['locale' => app()->getLocale()]);
        if ($user->trashed()) {
            $user->restore();
        }
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now(), 'last_seen_at' => now()])->save();

        return $user;
    }
}
