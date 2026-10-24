<?php

namespace App\Domain\Auth;

/**
 * Telegram Mini App: Telegram opens our site inside the app and gives the page "initData", a query string
 * signed with our bot token (https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app).
 * Only Telegram (and we) know the token, so a valid signature proves who the user is: no login screen.
 */
class TelegramWebApp
{
    /** initData older than this is refused (someone replaying a copied one). */
    public const MAX_AGE = 24 * 3600;

    /**
     * The checked data: ['user' => [...], 'start_param' => ?string, 'auth_date' => int], or null when the
     * signature is wrong, the data is too old or there is no bot token.
     */
    public function verify(string $initData, ?string $botToken = null): ?array
    {
        $botToken ??= (string) config('services.telegram.bot_token');
        if ($botToken === '' || $initData === '') {
            return null;
        }
        parse_str($initData, $fields);
        $hash = (string) ($fields['hash'] ?? '');
        unset($fields['hash']);
        if ($hash === '' || ! $this->signed($fields, $hash, $botToken)) {
            return null;
        }
        $age = time() - (int) ($fields['auth_date'] ?? 0);
        $user = json_decode((string) ($fields['user'] ?? ''), true);
        if ($age < -300 || $age > self::MAX_AGE || ! is_array($user) || empty($user['id'])) {
            return null;
        }

        return ['user' => $user, 'start_param' => $fields['start_param'] ?? null, 'auth_date' => (int) $fields['auth_date']];
    }

    /** "key=value" lines sorted by key, HMAC-SHA256 with the key HMAC_SHA256("WebAppData", bot token). */
    private function signed(array $fields, string $hash, string $botToken): bool
    {
        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
        ksort($fields);
        $check = implode("\n", array_map(fn ($k, $v) => $k.'='.$v, array_keys($fields), $fields));

        return hash_equals(hash_hmac('sha256', $check, $secret), $hash);
    }

    /**
     * Where a t.me/<bot>/<app>?startapp=… link should open (letters, digits, _ and - only):
     * join_<token> → the team invite, chat_<id>, game_<id>, rate_<id>, place_<slug>; anything else → home.
     */
    public static function startPath(?string $param): string
    {
        $param = (string) $param;

        return match (true) {
            (bool) preg_match('/^join_([A-Za-z0-9]{10,40})$/', $param, $m) => '/join/'.$m[1],
            (bool) preg_match('/^chat_(\d+)$/', $param, $m) => '/messages/'.$m[1],
            (bool) preg_match('/^game_(\d+)$/', $param, $m) => '/games/'.$m[1],
            (bool) preg_match('/^rate_(\d+)$/', $param, $m) => '/rate/'.$m[1],
            (bool) preg_match('/^place_([a-z0-9-]{2,60})$/', $param, $m) => '/places/'.$m[1],
            $param === 'bookings' => '/me/bookings',
            default => '/',
        };
    }
}
