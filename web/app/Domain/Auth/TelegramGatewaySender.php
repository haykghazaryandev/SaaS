<?php

namespace App\Domain\Auth;

use Illuminate\Support\Facades\Http;

/**
 * Production: Telegram Gateway API (gateway.telegram.org) delivers our code in the Telegram app,
 * about $0.01 per code. We generate the code ourselves and check it locally.
 */
class TelegramGatewaySender implements CodeSender
{
    public function send(string $phone, string $code): ?string
    {
        $token = config('services.telegram.gateway_token');
        $check = Http::withToken($token)->asJson()->post('https://gatewayapi.telegram.org/checkSendAbility', ['phone_number' => $phone])->json();
        if (! ($check['ok'] ?? false)) {
            throw new CodeSendFailed(str_contains((string) ($check['error'] ?? ''), 'PHONE_NUMBER') ? 'no_telegram' : 'provider_error');
        }
        $res = Http::withToken($token)->asJson()->post('https://gatewayapi.telegram.org/sendVerificationMessage', [
            'phone_number' => $phone,
            'request_id' => $check['result']['request_id'],
            'code' => $code,
            'ttl' => 300,
        ])->json();
        if (! ($res['ok'] ?? false)) {
            throw new CodeSendFailed('provider_error');
        }

        return $res['result']['request_id'] ?? null;
    }
}
