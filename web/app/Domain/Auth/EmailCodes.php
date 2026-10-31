<?php

namespace App\Domain\Auth;

use App\Mail\LoginCodeMail;
use App\Models\User;

/**
 * Backup login for people without Telegram: a 4-digit code by email (free with Resend / Brevo / SES).
 * Same limits as the phone codes (MailedCode); 10 minutes to use it.
 */
class EmailCodes
{
    public const CODE_MINUTES = 10;

    private MailedCode $codes;

    public function __construct()
    {
        $this->codes = new MailedCode('email-otp', 'otp-email', 'otp-ip', self::CODE_MINUTES);
    }

    public function send(string $email, string $ip): void
    {
        $email = PasswordLogin::normalize($email);
        $this->codes->send($email, $email, $ip, fn (string $code) => new LoginCodeMail($code));
    }

    /** Checks the code; returns the account of this email (created on the first login). */
    public function verify(string $email, string $code): User
    {
        $email = $this->codes->check(PasswordLogin::normalize($email), $code);
        $user = User::withTrashed()->firstOrCreate(['email' => $email], ['locale' => app()->getLocale()]);
        if ($user->trashed()) {
            $user->restore();
        }
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now(), 'last_seen_at' => now()])->save();

        return $user;
    }
}
