<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "Forgot password?": the link to set a new password (sent at once, not queued: the person is waiting for it). */
class PasswordResetMail extends Mailable
{
    use Queueable;

    public function __construct(public string $url, public int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('auth.reset.mail_subject'));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.password-reset');
    }
}
