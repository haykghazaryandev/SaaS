<?php

namespace App\Mail;

use App\Domain\Auth\EmailCodes;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The 4-digit login code by email (sent at once, not queued: the person is waiting for it). */
class LoginCodeMail extends Mailable
{
    use Queueable;

    public function __construct(public string $code, public int $minutes = EmailCodes::CODE_MINUTES) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('auth.email.subject', ['code' => $this->code]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.login-code-html', text: 'mail.login-code');
    }
}
