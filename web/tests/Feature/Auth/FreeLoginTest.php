<?php

use App\Domain\Notifications\TelegramUpdateHandler;
use App\Enums\BookingSource;
use App\Mail\LoginCodeMail;
use App\Models\Booking;
use App\Models\TelegramLogin;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config(['services.telegram.bot_token' => 'test-token', 'services.telegram.bot_username' => 'BookballBot']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
});

/** what Telegram would post to the webhook */
function tgUpdate(array $message): void
{
    app(TelegramUpdateHandler::class)->handle(['update_id' => random_int(1, 1e9), 'message' => $message + ['chat' => ['id' => $message['from']['id'], 'type' => 'private']]]);
}

function startTelegramLogin($test): string
{
    $res = $test->postJson('/login/telegram')->assertOk()->assertJsonStructure(['token', 'url']);
    expect($res->json('url'))->toBe('https://t.me/BookballBot?start=login_'.$res->json('token'));

    return $res->json('token');
}

it('logs in with Telegram: the bot asks for the phone, Telegram confirms it, the browser logs in', function () {
    $token = startTelegramLogin($this);
    $this->getJson("/login/telegram/{$token}")->assertJson(['status' => 'pending']);

    $from = ['id' => 555001, 'first_name' => 'Gor', 'last_name' => 'Avetisyan', 'language_code' => 'ru'];
    tgUpdate(['from' => $from, 'text' => "/start login_{$token}"]);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && ($r['reply_markup']['keyboard'][0][0]['request_contact'] ?? false));
    $this->getJson("/login/telegram/{$token}")->assertJson(['status' => 'pending']);

    tgUpdate(['from' => $from, 'contact' => ['user_id' => 555001, 'phone_number' => '37494112233', 'first_name' => 'Gor', 'last_name' => 'Avetisyan']]);
    $this->getJson("/login/telegram/{$token}")->assertJson(['status' => 'approved']);

    // registered at once with the Telegram name: no welcome form
    $this->postJson("/login/telegram/{$token}/complete")->assertOk()->assertJson(['redirect' => route('home')]);
    $this->assertAuthenticated();
    $u = User::where('phone', '+37494112233')->first();
    expect($u->phone_verified_at)->not->toBeNull()->and($u->telegram_chat_id)->toBe(555001)
        ->and($u->name)->toBe('Gor Avetisyan')->and($u->locale)->toBe('ru')->and($u->isRegistered())->toBeTrue();
    // a login can be used once
    auth()->logout();
    expect($this->postJson("/login/telegram/{$token}/complete")->status())->toBeIn([404, 422]);
});

it('does not accept somebody else\'s contact as confirmation', function () {
    $token = startTelegramLogin($this);
    $from = ['id' => 555002, 'first_name' => 'X'];
    tgUpdate(['from' => $from, 'text' => "/start login_{$token}"]);
    tgUpdate(['from' => $from, 'contact' => ['user_id' => 999999, 'phone_number' => '+37491000001', 'first_name' => 'Owner']]);
    $this->getJson("/login/telegram/{$token}")->assertJson(['status' => 'pending']);
    expect(User::where('phone', '+37491000001')->exists())->toBeFalse();
});

it('approves at once when the Telegram chat already belongs to an account', function () {
    User::factory()->create(['phone' => '+37494000001', 'telegram_chat_id' => 555003, 'telegram_verified_at' => now()]);
    $token = startTelegramLogin($this);
    tgUpdate(['from' => ['id' => 555003, 'first_name' => 'A'], 'text' => "/start login_{$token}"]);
    $this->getJson("/login/telegram/{$token}")->assertJson(['status' => 'approved']);
    $this->postJson("/login/telegram/{$token}/complete", ['context' => 'booking'])->assertOk()->assertJson(['redirect' => null]);
    $this->assertAuthenticated();
});

it('does not log in one tap through a Telegram link that was never confirmed by its phone', function () {
    User::factory()->create(['phone' => '+37494000002', 'telegram_chat_id' => 555004]);   // linked the old way, unconfirmed
    $token = startTelegramLogin($this);
    tgUpdate(['from' => ['id' => 555004, 'first_name' => 'A'], 'text' => "/start login_{$token}"]);
    $this->getJson("/login/telegram/{$token}")->assertJson(['status' => 'pending']);   // the bot asks for the phone instead
});

it('only lets the browser that started the login finish it', function () {
    $token = TelegramLogin::create(['token' => str_repeat('a', 40), 'expires_at' => now()->addMinutes(5)])->token;
    $this->getJson("/login/telegram/{$token}")->assertNotFound();
    $this->postJson("/login/telegram/{$token}/complete")->assertNotFound();
});

it('gives the confirmed phone to its real owner, not to an email account that only claimed it', function () {
    $claimer = User::factory()->create(['phone' => '+37494777777', 'phone_verified_at' => null, 'email' => 'a@b.am']);
    $token = startTelegramLogin($this);
    $from = ['id' => 555004, 'first_name' => 'Real'];
    tgUpdate(['from' => $from, 'text' => "/start login_{$token}"]);
    tgUpdate(['from' => $from, 'contact' => ['user_id' => 555004, 'phone_number' => '+37494777777']]);
    $owner = User::where('phone', '+37494777777')->first();
    expect($owner->id)->not->toBe($claimer->id)->and($claimer->fresh()->phone)->toBeNull();
});

it('lets a guest log in with Telegram in the booking sheet and then book', function () {
    $venue = makeVenue();
    $small = $venue->fields->firstWhere('size.value', 'small');
    $token = startTelegramLogin($this);
    $from = ['id' => 555005, 'first_name' => 'Davit'];
    tgUpdate(['from' => $from, 'text' => "/start login_{$token}"]);
    tgUpdate(['from' => $from, 'contact' => ['user_id' => 555005, 'phone_number' => '+37493556677', 'first_name' => 'Davit']]);
    $this->postJson("/login/telegram/{$token}/complete", ['context' => 'booking'])->assertOk();
    $this->post('/bookings', ['field_id' => $small->id, 'date' => at(0)->toDateString(), 'start' => '20:00', 'minutes' => 60])->assertSessionHasNoErrors();
    $b = Booking::first();
    expect($b->source)->toBe(BookingSource::Player)->and($b->contact_phone)->toBe('+37493556677')->and($b->contact_name)->toBe('Davit');
});

it('logs in by email, asks for the name, then to confirm the phone with Telegram', function () {
    Mail::fake();
    $this->post('/login/email', ['email' => 'Narek@Mail.am'])->assertSessionHasNoErrors();
    $code = Cache::get('dev-otp:narek@mail.am');
    expect($code)->toHaveLength(4);
    Mail::assertSent(LoginCodeMail::class);
    $this->post('/login/email/verify', ['code' => $code])->assertRedirect(route('welcome'));
    $this->assertAuthenticated();
    $u = User::where('email', 'narek@mail.am')->first();
    expect($u->phone)->toBeNull();

    // no typed phone any more: the phone comes confirmed from Telegram ("Confirm your phone")
    $this->post('/welcome', ['name' => 'Narek'])->assertRedirect(route('phone.confirm'));
    expect($u->fresh()->name)->toBe('Narek')->and($u->fresh()->phone)->toBeNull();
});

it('simulates Telegram in development only when there is no bot token', function () {
    config(['services.telegram.bot_token' => null, 'app.debug' => true]);
    $token = startTelegramLogin($this);
    $this->postJson("/login/telegram/{$token}/simulate", ['phone' => '93000100'])->assertOk();
    $this->postJson("/login/telegram/{$token}/complete")->assertOk();
    $this->assertAuthenticated();

    config(['services.telegram.bot_token' => 'x']);
    auth()->logout();
    $token2 = startTelegramLogin($this);
    $this->postJson("/login/telegram/{$token2}/simulate", ['phone' => '93000100'])->assertNotFound();
});

it('removes the "Share my phone number" button once the phone is shared', function () {
    $token = startTelegramLogin($this);
    $from = ['id' => 555090, 'first_name' => 'Gor'];
    tgUpdate(['from' => $from, 'text' => "/start login_{$token}"]);
    tgUpdate(['from' => $from, 'contact' => ['user_id' => 555090, 'phone_number' => '37494555090', 'first_name' => 'Gor']]);

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] == 555090 && ($r['reply_markup']['remove_keyboard'] ?? false) === true);
});
