<?php

namespace App\Domain\Auth;

use App\Models\User;
use Laravel\Socialite\Contracts\User as GoogleUser;

/**
 * "Continue with Google": finds the account of this Google account, else the account with the same email
 * (only when Google confirmed the email), else creates one. New accounts finish on the welcome page (phone).
 */
class GoogleLogin
{
    public function user(GoogleUser $google): User
    {
        $id = (string) $google->getId();
        $email = $google->getEmail() ? mb_strtolower(trim($google->getEmail())) : null;
        $verified = $email && (bool) ($google->getRaw()['email_verified'] ?? false);

        $user = User::withTrashed()->where('google_id', $id)->first()
            ?? ($verified ? User::withTrashed()->where('email', $email)->first() : null);

        if (! $user) {
            $user = new User(['locale' => app()->getLocale(), 'name' => $google->getName() ?: null]);
            // an unconfirmed email that another account already uses stays with that account
            if ($email && ($verified || ! User::withTrashed()->where('email', $email)->exists())) {
                $user->email = $email;
            }
        }
        if ($user->trashed()) {
            $user->restore();
        }
        $user->forceFill([
            'google_id' => $id,
            'email_verified_at' => $user->email_verified_at ?? ($verified && $user->email === $email ? now() : null),
            'last_seen_at' => now(),
        ])->save();

        return $user;
    }
}
