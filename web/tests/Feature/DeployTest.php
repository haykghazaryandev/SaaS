<?php

use App\Models\User;
use App\Models\Venue;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
});

it('migrates, seeds and registers the bot on an https deploy', function () {
    config(['app.url' => 'https://staging.bookball.am', 'services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'hook-secret']);

    $this->artisan('app:deploy')->assertSuccessful();

    expect(Venue::count())->toBe(9)->and(User::where('is_admin', true)->count())->toBe(1);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/setWebhook') && $r['url'] === 'https://staging.bookball.am/telegram/webhook/hook-secret');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/setMyCommands'));
});

it('can run again without doubling data', function () {
    config(['app.url' => 'http://localhost']);
    $this->artisan('app:deploy')->assertSuccessful();
    $users = User::count();

    $this->artisan('app:deploy')->assertSuccessful();

    expect(User::count())->toBe($users)->and(Venue::count())->toBe(9);
});

it('leaves the bot alone on http (local polling keeps working)', function () {
    config(['app.url' => 'http://localhost:8000', 'services.telegram.bot_token' => 'test-token', 'app.seed_on_deploy' => false]);

    $this->artisan('app:deploy')->expectsOutputToContain('APP_URL is not https')->assertSuccessful();

    Http::assertNothingSent();
    expect(Venue::count())->toBe(0);
});

it('does not fail the deploy when Telegram is down', function () {
    Http::swap(new Factory);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Gateway'], 502)]);
    config(['app.url' => 'https://staging.bookball.am', 'services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'hook-secret', 'app.seed_on_deploy' => false]);

    $this->artisan('app:deploy')->assertSuccessful();
});
