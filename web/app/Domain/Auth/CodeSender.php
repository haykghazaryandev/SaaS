<?php

namespace App\Domain\Auth;

/** Delivers a one-time code to a phone. Drivers: LogCodeSender (development), TelegramGatewaySender (production). */
interface CodeSender
{
    /** Returns the provider request id (or null). Throws CodeSendFailed when the code can't be delivered. */
    public function send(string $phone, string $code): ?string;
}
