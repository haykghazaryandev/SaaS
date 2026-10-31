<?php

namespace App\Domain\Auth;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A 4-digit code sent by email and kept hashed in the cache (EmailCodes: login, EmailVerification: confirming an address).
 * Limits: 3 codes per address per 10 minutes, 10 per IP per hour, 5 tries.
 */
final class MailedCode
{
    private const TRIES = 5;

    /**
     * @param  string  $prefix  cache key prefix of the stored codes
     * @param  string  $toLimit  rate-limit prefix per address
     * @param  string  $ipLimit  rate-limit prefix per IP (shared with the phone codes for the login)
     */
    public function __construct(
        private string $prefix,
        private string $toLimit,
        private string $ipLimit,
        public readonly int $minutes,
    ) {}

    /**
     * Sends a new code to $email, kept under $key until check().
     *
     * @param  callable(string): Mailable  $mail
     */
    public function send(string $key, string $email, string $ip, callable $mail, ?string $locale = null): void
    {
        $limits = [$this->toLimit.':'.$email => [3, 600], $this->ipLimit.':'.$ip => [10, 3600]];
        foreach ($limits as $limit => [$max]) {
            if (RateLimiter::tooManyAttempts($limit, $max)) {
                throw ValidationException::withMessages(['email' => __('auth.code.too_many', ['min' => ceil(RateLimiter::availableIn($limit) / 60)])]);
            }
        }
        $code = (string) random_int(1000, 9999);
        // sent first: when sending fails, the code sent before still works
        try {
            Mail::to($email)->locale($locale ?? app()->getLocale())->send($mail($code));
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['email' => __('auth.code.provider_error')]);
        }
        Cache::put($this->prefix.':'.$key, ['email' => $email, 'hash' => Hash::make($code), 'tries' => 0], now()->addMinutes($this->minutes));
        if (config('app.debug')) {
            Cache::put('dev-otp:'.$email, $code, now()->addMinutes($this->minutes));   // shown on screen in development
        }
        foreach ($limits as $limit => [, $decay]) {
            RateLimiter::hit($limit, $decay);
        }
    }

    /** The address waiting under $key for its code (null: none, or it expired). */
    public function pending(string $key): ?string
    {
        return Cache::get($this->prefix.':'.$key)['email'] ?? null;
    }

    /** Checks the code; returns the address it was sent to (and forgets it). */
    public function check(string $key, string $code): string
    {
        $cacheKey = $this->prefix.':'.$key;
        $data = Cache::get($cacheKey);
        if (! $data) {
            throw ValidationException::withMessages(['code' => __('auth.code.expired')]);
        }
        if ($data['tries'] >= self::TRIES) {
            Cache::forget($cacheKey);
            throw ValidationException::withMessages(['code' => __('auth.code.too_many_tries')]);
        }
        if (! Hash::check($code, $data['hash'])) {
            $data['tries']++;
            Cache::put($cacheKey, $data, now()->addMinutes($this->minutes));
            throw ValidationException::withMessages(['code' => __('auth.code.wrong', ['n' => self::TRIES - $data['tries']])]);
        }
        Cache::forget($cacheKey);
        Cache::forget('dev-otp:'.$data['email']);

        return $data['email'];
    }
}
