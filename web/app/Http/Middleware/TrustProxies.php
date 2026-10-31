<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as BaseTrustProxies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Which proxies may tell us the client's address, so that $request->ip() is the person, not Railway.
 *
 * On Railway a request goes client → public edge → internal proxy → this app (measured on staging, 2026-10-31):
 * REMOTE_ADDR is the internal proxy (100.64.0.0/10) and the edge sends "X-Forwarded-For: <client>, <edge>",
 * replacing any X-Forwarded-For the client sent. Trusting only REMOTE_ADDR (Laravel's "*") made ip() the EDGE,
 * so every visitor looked like the same address in France.
 *
 * - peer on the proxy network: the peer and the right-most X-Forwarded-For entry (the edge) are trusted, so ip()
 *   is the entry before it. A forged entry can only ever sit further left.
 * - peer on loopback (a local proxy such as ngrok in development): the peer is trusted, as before.
 * - any other peer: nothing is trusted, ip() is the peer whatever headers it sends.
 */
class TrustProxies extends BaseTrustProxies
{
    private const LOOPBACK = ['127.0.0.0/8', '::1'];

    protected function setTrustedProxyIpAddressesToTheCallingIp(Request $request)
    {
        $request->setTrustedProxies($this->trustedHops($request), $this->getTrustedHeaderNames());
    }

    /** @return list<string> */
    private function trustedHops(Request $request): array
    {
        $peer = (string) $request->server->get('REMOTE_ADDR');
        if (! filter_var($peer, FILTER_VALIDATE_IP)) {
            return [];
        }

        $network = (string) config('access.proxy_network');
        if ($network !== '' && IpUtils::checkIp($peer, $network)) {
            $hops = array_values(array_filter(array_map('trim', explode(',', (string) $request->headers->get('X-Forwarded-For')))));
            $edge = count($hops) >= 2 ? end($hops) : null;

            return $edge !== null && filter_var($edge, FILTER_VALIDATE_IP) ? [$peer, $edge] : [$peer];
        }

        return IpUtils::checkIp($peer, self::LOOPBACK) ? [$peer] : [];
    }
}
