<?php

use App\Domain\Auth\AccountEraser;
use App\Domain\Auth\LoginLinks;
use App\Domain\Booking\Actions\RequestBooking;
use App\Domain\Notifications\BookingNotification;
use App\Domain\Notifications\TelegramChannel;
use App\Models\Booking;
use App\Models\Friendship;
use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    config(['services.telegram.bot_token' => 'test-token', 'services.telegram.bot_username' => 'BookballBot', 'services.telegram.webhook_secret' => 'hook-secret']);
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    foreach (['127.0.0.1', '::1'] as $ip) {
        RateLimiter::clear('otp-ip:'.$ip);
    }
});

// ---------- links that log in ----------

it('opens the page logged in from a bot link, once', function () {
    $u = User::factory()->create();
    $link = app(LoginLinks::class)->make($u, '/me/bookings');

    $this->get($link)->assertRedirect('/me/bookings');
    $this->assertAuthenticatedAs($u);

    // used: a logged-out browser goes through the login and comes back to the page
    auth()->logout();
    $this->get($link)->assertRedirect(route('login', ['redirect' => '/me/bookings']));
    $this->assertGuest();
});

it('refuses changed, expired and foreign links', function () {
    $u = User::factory()->create();
    $other = User::factory()->create();
    $link = app(LoginLinks::class)->make($u, '/');

    $this->get(str_replace("/l/{$u->id}", "/l/{$other->id}", $link))->assertRedirect(route('login', ['redirect' => '/']));
    $this->travel(31)->minutes();
    $this->get($link)->assertRedirect(route('login', ['redirect' => '/']));
    $this->assertGuest();
    expect(LoginLinks::safePath('//evil.example'))->toBe('/')->and(LoginLinks::safePath('https://evil.example'))->toBe('/');
});

it('switches the browser to the person of the link', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $this->actingAs($a)->get(app(LoginLinks::class)->make($b, '/games'))->assertRedirect('/games');
    $this->assertAuthenticatedAs($b);
});

it('opens Bookball inside Telegram from the bot buttons after a Telegram login', function () {
    config(['app.url' => 'https://bookball.am']);
    $token = $this->postJson('/login/telegram')->json('token');
    $from = ['id' => 777001, 'first_name' => 'Hamo'];
    $this->postJson('/telegram/webhook/hook-secret', ['update_id' => 1, 'message' => ['message_id' => 1, 'chat' => ['id' => 777001, 'type' => 'private'], 'from' => $from, 'text' => "/start login_{$token}"]], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret']);
    $this->postJson('/telegram/webhook/hook-secret', ['update_id' => 2, 'message' => ['message_id' => 2, 'chat' => ['id' => 777001, 'type' => 'private'], 'from' => $from, 'contact' => ['user_id' => 777001, 'phone_number' => '37494000111', 'first_name' => 'Hamo']]], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret']);

    $apps = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'))
        ->flatMap(fn (Request $r) => collect($r['reply_markup']['inline_keyboard'] ?? [])->flatten(1))->pluck('web_app.url')->filter()->values();
    expect($apps)->not->toBeEmpty()->and($apps->first())->toStartWith('https://bookball.am/');
});

it('adds an "open" button to booking messages that opens the Mini App on My bookings', function () {
    config(['app.url' => 'https://bookball.am']);
    $venue = makeVenue();
    $player = User::factory()->create(['telegram_chat_id' => 4242]);
    $b = app(RequestBooking::class)->handle($player, $venue->fields->first(), at(20), 60);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    app(TelegramChannel::class)->send($player, new BookingNotification($b, 'accepted', 'player'));

    Http::assertSent(fn (Request $r) => ($r['reply_markup']['inline_keyboard'][0][0]['web_app']['url'] ?? '') === 'https://bookball.am/me/bookings');
});

// ---------- delete completely ----------

it('deletes a person completely so the phone can register again', function () {
    $u = User::factory()->create(['phone' => '+37494369202']);
    $f = User::factory()->create();
    Friendship::create(['user_id' => $u->id, 'friend_id' => $f->id, 'status' => 'accepted', 'accepted_at' => now()]);
    $u->delete();   // even a soft-deleted account

    $this->artisan('user:erase', ['phone' => '094369202', '--force' => true])->assertSuccessful();

    expect(User::withTrashed()->where('phone', '+37494369202')->exists())->toBeFalse()
        ->and(Friendship::count())->toBe(0)->and(User::find($f->id))->not->toBeNull();
});

it('keeps bookings for the place when the account is deleted', function () {
    $venue = makeVenue();
    $u = User::factory()->create();
    $b = app(RequestBooking::class)->handle($u, $venue->fields->first(), at(20), 60);

    app(AccountEraser::class)->erase($u);

    expect(Booking::find($b->id))->not->toBeNull()->and(Booking::find($b->id)->user_id)->toBeNull();
});

it('makes ADMIN_PHONE the admin again after registering anew', function () {
    config(['app.admin_phone' => '+37494369202']);
    $token = $this->postJson('/login/telegram')->json('token');
    $from = ['id' => 888001, 'first_name' => 'Hamo'];
    $this->postJson('/telegram/webhook/hook-secret', ['update_id' => 1, 'message' => ['message_id' => 1, 'chat' => ['id' => 888001, 'type' => 'private'], 'from' => $from, 'text' => "/start login_{$token}"]], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret']);
    $this->postJson('/telegram/webhook/hook-secret', ['update_id' => 2, 'message' => ['message_id' => 2, 'chat' => ['id' => 888001, 'type' => 'private'], 'from' => $from, 'contact' => ['user_id' => 888001, 'phone_number' => '37494369202', 'first_name' => 'Hamo']]], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret']);

    expect(User::where('phone', '+37494369202')->first()->is_admin)->toBeTrue();
});

// ---------- test accounts on staging ----------

it('lets only the demo accounts log in with an on-screen code on staging', function () {
    config(['app.debug' => false, 'services.otp_driver' => 'log']);
    app()->detectEnvironment(fn () => 'staging');
    $this->withoutMiddleware(Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

    $this->get('/login')->assertInertia(fn ($p) => $p->where('phoneCode', true)->where('testAccounts', true));
    $this->post('/login/code', ['phone' => '94 369 202'])->assertSessionHasErrors('phone');

    $this->post('/login/code', ['phone' => '93 000 100'])->assertSessionHasNoErrors();
    $code = null;
    $this->get('/login')->assertInertia(function ($p) use (&$code) {
        $code = $p->toArray()['props']['devCode'];

        return $p;
    });
    expect($code)->toMatch('/^\d{4}$/');
    $this->post('/login/verify', ['code' => $code])->assertSessionHasNoErrors();
    expect(auth()->user()->phone)->toBe('+37493000100');
});

it('has no test accounts in production', function () {
    config(['app.debug' => false, 'services.otp_driver' => 'log']);
    app()->detectEnvironment(fn () => 'production');
    $this->withoutMiddleware(Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

    $this->get('/login')->assertInertia(fn ($p) => $p->where('phoneCode', false));
    $this->post('/login/code', ['phone' => '93 000 100'])->assertNotFound();
});
