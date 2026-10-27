<?php

use App\Domain\Notifications\TelegramUpdateHandler;
use App\Models\User;
use Illuminate\Support\Facades\Http;

// The language is chosen in the app (EN / ՀԱՅ / РУ) or follows the phone; the bot only sends notifications in it.

beforeEach(function () {
    config(['services.telegram.bot_token' => 'test-token']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
});

it('has no language menu in the bot any more: /language just points to the app', function () {
    User::factory()->create(['telegram_chat_id' => 777001, 'locale' => 'hy']);
    app(TelegramUpdateHandler::class)->handle(['update_id' => 1, 'message' => ['from' => ['id' => 777001], 'chat' => ['id' => 777001, 'type' => 'private'], 'text' => '/language']]);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && ! isset($r['reply_markup']) && str_contains($r['text'], 'ZoneSpot'));
});

it('still understands the language buttons of old messages, as the person\'s own choice', function () {
    $u = User::factory()->create(['telegram_chat_id' => 777001, 'locale' => 'hy']);
    app(TelegramUpdateHandler::class)->handle(['update_id' => 2, 'callback_query' => ['id' => 'q1', 'from' => ['id' => 777001], 'data' => 'lang:ru', 'message' => ['message_id' => 5, 'chat' => ['id' => 777001]]]]);
    expect($u->fresh()->locale)->toBe('ru')->and($u->fresh()->locale_chosen_at)->not->toBeNull();
});

it('sends the login message without app or language buttons (the bot is for notifications)', function () {
    $token = $this->postJson('/login/telegram')->json('token');
    $from = ['id' => 777003, 'first_name' => 'Ani'];
    app(TelegramUpdateHandler::class)->handle(['update_id' => 4, 'message' => ['from' => $from, 'chat' => ['id' => 777003, 'type' => 'private'], 'text' => "/start login_{$token}"]]);
    app(TelegramUpdateHandler::class)->handle(['update_id' => 5, 'message' => ['from' => $from, 'chat' => ['id' => 777003, 'type' => 'private'], 'contact' => ['user_id' => 777003, 'phone_number' => '+37494555001']]]);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'sendMessage') && collect($r['reply_markup']['inline_keyboard'] ?? [])->isNotEmpty());
});

it('follows the phone language in the Mini App until the person picks one; notifications use it', function () {
    config(['app.url' => 'https://bookball.am']);
    $u = User::factory()->create(['telegram_chat_id' => 555, 'telegram_verified_at' => now(), 'locale' => 'ru']);
    $data = (function () {
        $f = ['auth_date' => (string) time(), 'user' => json_encode(['id' => 555, 'language_code' => 'ru'])];
        ksort($f);
        $f['hash'] = hash_hmac('sha256', implode("\n", array_map(fn ($k, $v) => "$k=$v", array_keys($f), $f)), hash_hmac('sha256', 'test-token', 'WebAppData', true));

        return http_build_query($f);
    })();

    // the phone is in Armenian (Telegram itself has no Armenian and says "ru")
    $this->postJson('/tg/app/login', ['init_data' => $data, 'device_lang' => 'hy-AM'])->assertJson(['locale' => 'hy']);
    expect($u->fresh()->preferredLocale())->toBe('hy');

    // they switch to English in the app: the page and the bot follow, the phone no longer decides
    $this->get('/games?lang=en');
    $this->postJson('/tg/app/login', ['init_data' => $data, 'device_lang' => 'hy-AM'])->assertJson(['locale' => 'en']);
    expect($u->fresh()->preferredLocale())->toBe('en');
});

it('uses the browser language for guests', function () {
    $this->withHeader('Accept-Language', 'ru-RU,ru;q=0.9,en;q=0.8')->get('/')->assertInertia(fn ($p) => $p->where('locale', 'ru'));
    $this->withHeader('Accept-Language', 'de-DE,de;q=0.9')->get('/')->assertInertia(fn ($p) => $p->where('locale', 'hy'));
});
