<?php

namespace App\Domain\Notifications;

/**
 * Checks a browser's push subscription before it is saved (PushController). The server later POSTs to the endpoint,
 * so it must be a public https host, not an address inside our network (blind SSRF). No allowlist of push services:
 * browsers use more of them than we would list, and a missing one would silently break push.
 */
class PushEndpoint
{
    public static function valid(string $endpoint): bool
    {
        $u = parse_url($endpoint);
        if (! is_array($u) || ($u['scheme'] ?? '') !== 'https' || empty($u['host']) || isset($u['user']) || isset($u['pass'])) {
            return false;
        }
        $host = strtolower(rtrim($u['host'], '.'));
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) || ! str_contains($host, '.')) {
            return false;   // an IP address (private or not) or "localhost", "web", …
        }
        // names that only mean something inside a network (Railway's private network is *.railway.internal)
        foreach (['localhost', 'local', 'internal', 'localdomain', 'lan', 'home', 'intranet', 'corp', 'test', 'invalid'] as $tld) {
            if ($host === $tld || str_ends_with($host, '.'.$tld)) {
                return false;
            }
        }
        // a hostname written as numbers (0x7f.1, 2130706433.x): no real push service looks like that
        if (preg_match('/^[0-9.x]+$/', $host) || preg_match('/^0x[0-9a-f]+\./', $host)) {
            return false;
        }

        return (bool) preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host);
    }

    /** p256dh: an uncompressed P-256 public key (65 bytes, 0x04 first). Base64url or base64. */
    public static function validKey(string $p256dh): bool
    {
        $key = self::decode($p256dh);

        return $key !== null && strlen($key) === 65 && $key[0] === "\x04";
    }

    /** auth: the 16-byte secret. */
    public static function validSecret(string $auth): bool
    {
        $secret = self::decode($auth);

        return $secret !== null && strlen($secret) === 16;
    }

    private static function decode(string $s): ?string
    {
        $b64 = strtr(rtrim($s, '='), '-_', '+/');
        $out = base64_decode($b64.str_repeat('=', (4 - strlen($b64) % 4) % 4), true);

        return $out === false ? null : $out;
    }
}
