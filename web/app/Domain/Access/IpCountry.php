<?php

namespace App\Domain\Access;

/** The country of an IP address. */
interface IpCountry
{
    /** ISO 3166-1 alpha-2 code (upper case), or null when unknown: private, malformed or unlisted address, no database. */
    public function countryOf(?string $ip): ?string;
}
