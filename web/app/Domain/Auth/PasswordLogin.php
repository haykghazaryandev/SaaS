<?php

namespace App\Domain\Auth;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Email + password. Signing up (email, password, then the code sent to the email) creates an account without name and phone:
 * the welcome page asks for both. "Forgot password?" emails a link (Laravel's password broker, 60 minutes);
 * it also lets an email-code account set its first password.
 * Limits: 5 wrong tries per login + IP per 10 minutes, 30 per IP per hour.
 */
class PasswordLogin
{
    /** Creates the account once the code sent to $email was right (EmailVerification): the email is confirmed. */
    public function register(string $email, string $passwordHash): User
    {
        $user = new User(['email' => self::normalize($email), 'locale' => app()->getLocale()]);
        // the "hashed" cast keeps an existing hash as it is
        $user->forceFill(['password' => $passwordHash, 'email_verified_at' => now(), 'last_seen_at' => now()])->save();

        return $user;
    }

    /** Throws "wrong email or password" (the same text whether the account exists or not). */
    public function attempt(string $email, string $password, string $ip): User
    {
        $email = self::normalize($email);
        $keys = ['pw-login:'.$email.'|'.$ip => [5, 600], 'pw-login-ip:'.$ip => [30, 3600]];
        foreach ($keys as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages(['email' => __('auth.password.too_many', ['min' => ceil(RateLimiter::availableIn($key) / 60)])]);
            }
        }

        $user = User::where('email', $email)->whereNotNull('password')->first();
        // compare against a dummy hash when there is no account, so the answer takes the same time
        $ok = Hash::check($password, $user?->password ?? '$2y$12$0000000000000000000000000000000000000000000000000000u');
        if (! $user || ! $ok) {
            foreach ($keys as $key => [, $decay]) {
                RateLimiter::hit($key, $decay);
            }
            throw ValidationException::withMessages(['email' => __('auth.password.wrong')]);
        }
        RateLimiter::clear('pw-login:'.$email.'|'.$ip);
        $user->forceFill(['last_seen_at' => now()])->save();

        return $user;
    }

    /** Emails a reset link when an account has this email; says nothing either way (no guessing who has an account). */
    public function sendResetLink(string $email): void
    {
        Password::broker()->sendResetLink(['email' => self::normalize($email)], function (User $user, string $token) {
            $url = route('login.reset', ['token' => $token, 'email' => $user->email]);
            Mail::to($user->email)->locale($user->preferredLocale())->send(new PasswordResetMail($url, (int) config('auth.passwords.users.expire')));
        });
    }

    /** Sets the new password from a reset link; the email is then confirmed too. */
    public function reset(string $email, string $token, string $password): User
    {
        $done = null;
        $status = Password::broker()->reset(
            ['email' => self::normalize($email), 'token' => $token, 'password' => $password],
            function (User $user, string $password) use (&$done) {
                $user->forceFill([
                    'password' => $password, 'remember_token' => Str::random(60),
                    'email_verified_at' => $user->email_verified_at ?? now(), 'last_seen_at' => now(),
                ])->save();
                $done = $user;
            },
        );
        if ($status !== Password::PASSWORD_RESET || ! $done) {
            throw ValidationException::withMessages(['password' => __('auth.reset.invalid')]);
        }

        return $done;
    }

    /** Emails are case-insensitive: stored in lower case. */
    public static function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
