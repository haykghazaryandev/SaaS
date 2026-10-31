<?php

// ALLOWED_COUNTRIES (config/access.php): who gets in by the country of their address, and which address that is
// behind Railway (App\Http\Middleware\TrustProxies). Railway's real shape, measured on staging: REMOTE_ADDR is the
// internal proxy (100.64.0.0/10) and X-Forwarded-For is "<client>, <edge>".

use App\Domain\Access\IpCountry;
use App\Domain\Access\MmdbIpCountry;

const RAILWAY_PROXY = '100.64.0.12';
const RAILWAY_EDGE = '79.127.178.81';
const ARMENIAN = '5.10.20.30';
const FRENCH = '51.15.25.35';

/** Answers from a fixed map and remembers which addresses it was asked about. */
function fakeCountries(array $map): object
{
    $fake = new class($map) implements IpCountry
    {
        public array $asked = [];

        public function __construct(private readonly array $map) {}

        public function countryOf(?string $ip): ?string
        {
            $this->asked[] = $ip;

            return $this->map[$ip] ?? null;
        }
    };
    app()->instance(IpCountry::class, $fake);

    return $fake;
}

function throughRailway($test, string $client)
{
    return $test->withServerVariables(['REMOTE_ADDR' => RAILWAY_PROXY])
        ->withHeaders(['X-Forwarded-For' => $client.', '.RAILWAY_EDGE]);
}

beforeEach(function () {
    config(['access.countries' => ['AM']]);
    $this->countries = fakeCountries([ARMENIAN => 'AM', FRENCH => 'FR', RAILWAY_EDGE => 'FR']);
});

it('lets a visitor from Armenia in, judged by their own address and not Railway\'s edge', function () {
    throughRailway($this, ARMENIAN)->get('/lang/en.json')->assertOk();

    expect($this->countries->asked)->toBe([ARMENIAN]);
});

it('turns a visitor from another country away with a page in every language', function () {
    $res = throughRailway($this, FRENCH)->withHeaders(['Accept-Language' => 'ru-RU,ru;q=0.9'])->get('/lang/en.json');

    $res->assertForbidden()
        ->assertSee('Доступно только в Армении')
        ->assertSee('Հասանելի է միայն Հայաստանում')
        ->assertSee('Available only in Armenia')
        ->assertSee('<html lang="ru">', false);
    expect($res->headers->get('Cache-Control'))->toContain('no-store');
});

it('turns them away on every kind of page, the admin included', function () {
    throughRailway($this, FRENCH)->get('/')->assertForbidden();
    throughRailway($this, FRENCH)->get('/admin')->assertForbidden();
    throughRailway($this, FRENCH)->post('/login/password')->assertForbidden();
});

it('cannot be fooled by a forged X-Forwarded-For from outside Railway\'s network', function () {
    $this->withServerVariables(['REMOTE_ADDR' => FRENCH])
        ->withHeaders(['X-Forwarded-For' => ARMENIAN.', '.RAILWAY_EDGE])
        ->get('/lang/en.json')
        ->assertForbidden();

    expect($this->countries->asked)->toBe([FRENCH]);
});

it('keeps trusting a proxy on this machine (ngrok in development)', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeaders(['X-Forwarded-For' => FRENCH])
        ->get('/lang/en.json')
        ->assertForbidden();

    expect($this->countries->asked)->toBe([FRENCH]);
});

it('is off while no country is configured', function () {
    config(['access.countries' => []]);

    throughRailway($this, FRENCH)->get('/lang/en.json')->assertOk();
    expect($this->countries->asked)->toBe([]);
});

it('lets an address of unknown country through', function () {
    throughRailway($this, '9.9.9.9')->get('/lang/en.json')->assertOk();
});

it('never stops the health check or the Telegram webhook (Telegram calls from abroad)', function () {
    throughRailway($this, FRENCH)->get('/up')->assertOk();
    // the wrong secret answers the controller's own 404, not the country page
    throughRailway($this, FRENCH)->postJson('/telegram/webhook/wrong-secret', [])->assertNotFound();

    expect($this->countries->asked)->toBe([]);
});

it('reads no country from a missing or broken database, a private or a malformed address', function () {
    $missing = new MmdbIpCountry(storage_path('framework/testing/no-such-file.mmdb'));
    expect($missing->countryOf('8.8.8.8'))->toBeNull();

    $broken = tempnam(sys_get_temp_dir(), 'mmdb');
    file_put_contents($broken, 'not a database');
    expect((new MmdbIpCountry($broken))->countryOf('8.8.8.8'))->toBeNull();
    unlink($broken);

    foreach ([null, '', 'garbage', '10.0.0.1', RAILWAY_PROXY, '127.0.0.1', '::1'] as $ip) {
        expect($missing->countryOf($ip))->toBeNull();
    }
});
