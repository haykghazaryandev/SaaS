<?php

namespace App\Domain\Access;

use Illuminate\Support\Facades\Log;
use MaxMind\Db\Reader;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Country lookups in a local .mmdb file (DB-IP or MaxMind country format): no network call per request.
 * Never throws: anything it cannot answer is null.
 */
class MmdbIpCountry implements IpCountry
{
    private ?Reader $reader = null;

    private bool $opened = false;

    public function __construct(private readonly string $path) {}

    public function countryOf(?string $ip): ?string
    {
        if ($ip === null || ! filter_var($ip, FILTER_VALIDATE_IP) || IpUtils::isPrivateIp($ip)) {
            return null;
        }

        $reader = $this->reader();
        if ($reader === null) {
            return null;
        }

        try {
            $record = $reader->get($ip);
        } catch (Throwable) {
            return null;
        }

        $code = is_array($record) ? ($record['country']['iso_code'] ?? null) : null;

        return is_string($code) && preg_match('/^[A-Za-z]{2}$/', $code) ? strtoupper($code) : null;
    }

    private function reader(): ?Reader
    {
        if (! $this->opened) {
            $this->opened = true;
            try {
                $this->reader = is_file($this->path) ? new Reader($this->path) : null;
            } catch (Throwable $e) {
                $this->reader = null;
                Log::warning('access.geoip.unreadable', ['error' => $e::class]);
            }
            if ($this->reader === null && config('access.countries') !== []) {
                Log::warning('access.geoip.missing: ALLOWED_COUNTRIES is set but there is no country database, so it is not enforced');
            }
        }

        return $this->reader;
    }
}
