<?php

namespace App\Domain\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Development: the code goes to the log (and is shown on the login screen when APP_DEBUG is on). */
class LogCodeSender implements CodeSender
{
    public function send(string $phone, string $code): ?string
    {
        Log::info("Login code for {$phone}: {$code}");
        Cache::put('dev-otp:'.$phone, $code, now()->addMinutes(5));

        return null;
    }
}
