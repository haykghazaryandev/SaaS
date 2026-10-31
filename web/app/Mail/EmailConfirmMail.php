<?php

namespace App\Mail;

use App\Domain\Auth\EmailVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The 4-digit code that confirms an email (sign-up, Profile); sent at once, not queued: the person is waiting for it. */
class EmailConfirmMail extends Mailable
{
    use Queueable;

    public function __construct(public string $code, public int $minutes = EmailVerification::CODE_MINUTES) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('auth.verify.mail_subject', ['code' => $this->code]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.email-confirm-html', text: 'mail.email-confirm');
    }
}
