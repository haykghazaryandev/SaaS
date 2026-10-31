<?php

namespace App\Domain\Auth;

use App\Mail\EmailConfirmMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * An email is saved only after the 4-digit code sent to it was typed: nothing holds an address its owner did not prove.
 * - Sign-up (email + password): the account is created when the code is right.
 * - Profile → add / change the email: the new address replaces the old one when the code is right.
 * The address waits next to its code, under "signup:<token>" or "user:<id>". Limits in MailedCode; 60 minutes to use it.
 * Older accounts may still have an unconfirmed email: "Your email" sends it a code the same way.
 */
class EmailVerification
{
    public const CODE_MINUTES = 60;

    private MailedCode $codes;

    public function __construct(private PasswordLogin $login)
    {
        $this->codes = new MailedCode('email-verify', 'email-verify-to', 'email-verify-ip', self::CODE_MINUTES);
    }

    /**
     * Sign-up, step 1: a code to $email. Nothing is saved: returns what the session keeps until the code comes back.
     *
     * @return array{token: string, email: string, password: string}
     */
    public function startSignup(string $email, string $password, string $ip): array
    {
        $signup = ['token' => Str::random(32), 'email' => PasswordLogin::normalize($email), 'password' => Hash::make($password)];
        $this->resendSignup($signup, $ip);

        return $signup;
    }

    /** @param  array{token: string, email: string}  $signup */
    public function resendSignup(array $signup, string $ip): void
    {
        $this->send('signup:'.$signup['token'], $signup['email'], $ip);
    }

    /**
     * Sign-up, step 2: the right code creates the account, its email confirmed.
     *
     * @param  array{token: string, email: string, password: string}  $signup
     */
    public function finishSignup(array $signup, string $code): User
    {
        $email = $this->codes->check('signup:'.$signup['token'], $code);
        if (User::withTrashed()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['code' => __('auth.password.email_taken')]);
        }

        return $this->login->register($email, $signup['password']);
    }

    /** Whether the sign-up still has a code to type (false: used, expired or too many tries). */
    public function signupPending(array $signup): bool
    {
        return $this->codes->pending('signup:'.$signup['token']) !== null;
    }

    /** The address waiting for its code in Profile (null: none, or the code expired). */
    public function pendingChange(User $user): ?string
    {
        return $this->codes->pending('user:'.$user->id);
    }

    /** Profile: send a code to a new address (or to the account's own unconfirmed one). Nothing changes yet. */
    public function startChange(User $user, string $email, string $ip): void
    {
        $email = PasswordLogin::normalize($email);
        self::ensureFree($email, $user);
        $this->send('user:'.$user->id, $email, $ip, $user->preferredLocale());
    }

    /** Profile: the right code → the address becomes the account's email, confirmed. */
    public function finishChange(User $user, string $code): void
    {
        $email = $this->codes->check('user:'.$user->id, $code);
        self::ensureFree($email, $user);   // someone else may have taken it meanwhile
        $user->forceFill(['email' => $email, 'email_verified_at' => now()])->save();
    }

    /** No other account (deleted ones too: they can come back) may have this address. */
    private static function ensureFree(string $email, User $except): void
    {
        if (User::withTrashed()->where('email', $email)->whereKeyNot($except->id)->exists()) {
            throw ValidationException::withMessages(['email' => __('profile.email.taken')]);
        }
    }

    /** The development code for $email (APP_DEBUG only). */
    public static function devCode(?string $email): ?string
    {
        return $email && config('app.debug') ? Cache::get('dev-otp:'.$email) : null;
    }

    private function send(string $key, string $email, string $ip, ?string $locale = null): void
    {
        $this->codes->send($key, $email, $ip, fn (string $code) => new EmailConfirmMail($code), $locale);
    }
}
