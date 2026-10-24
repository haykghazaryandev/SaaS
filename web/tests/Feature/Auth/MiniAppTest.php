<?php

use App\Domain\Auth\TelegramWebApp;
use App\Domain\Chat\Chat;
use App\Models\ConversationParticipant;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.telegram.bot_token' => 'test-token', 'services.telegram.bot_username' => 'BookballBot', 'services.telegram.webhook_secret' => 'hook-secret', 'app.url' => 'https://bookball.am']);
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
});

/** initData exactly as Telegram signs it for our bot. */
function initData(array $user, array $extra = [], string $token = 'test-token', ?int $authDate = null): string
{
    $fields = ['auth_date' => (string) ($authDate ?? time()), 'query_id' => 'AAH', 'user' => json_encode($user), 'signature' => 'sig'] + $extra;
    ksort($fields);
    $check = implode("\n", array_map(fn ($k, $v) => "$k=$v", array_keys($fields), $fields));
    $fields['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', $token, 'WebAppData', true));

    return http_build_query($fields);
}

// ---------- the signature ----------

it('accepts only data signed for our bot, and not too old', function () {
    $app = app(TelegramWebApp::class);
    $u = ['id' => 555, 'first_name' => 'Aram'];
    expect($app->verify(initData($u))['user']['id'])->toBe(555)
        ->and($app->verify(initData($u, [], 'other-bot-token')))->toBeNull()
        ->and($app->verify(str_replace('Aram', 'Mallory', initData($u))))->toBeNull()   // changed after signing
        ->and($app->verify(initData($u, [], 'test-token', time() - 2 * 86400)))->toBeNull()
        ->and($app->verify(initData($u, ['start_param' => 'chat_12']))['start_param'])->toBe('chat_12');
});

it('maps startapp parameters to pages, and nothing else', function () {
    expect(TelegramWebApp::startPath('join_abcDEF1234567890'))->toBe('/join/abcDEF1234567890')
        ->and(TelegramWebApp::startPath('chat_12'))->toBe('/messages/12')
        ->and(TelegramWebApp::startPath('place_zangak'))->toBe('/places/zangak')
        ->and(TelegramWebApp::startPath('//evil.example'))->toBe('/')
        ->and(TelegramWebApp::startPath(null))->toBe('/');
});

// ---------- logging in ----------

it('logs a known Telegram account in at once and keeps it on the page', function () {
    $u = User::factory()->create(['telegram_chat_id' => 555]);
    $this->postJson('/tg/app/login', ['init_data' => initData(['id' => 555]), 'path' => '/games'])
        ->assertOk()->assertJson(['status' => 'ok', 'user_id' => $u->id, 'redirect' => '/games']);
    $this->assertAuthenticatedAs($u);
});

it('opens the page of a t.me link (startapp)', function () {
    User::factory()->create(['telegram_chat_id' => 555]);
    $this->postJson('/tg/app/login', ['init_data' => initData(['id' => 555], ['start_param' => 'chat_7']), 'path' => '/'])
        ->assertJson(['redirect' => '/messages/7']);
});

it('refuses forged data', function () {
    User::factory()->create(['telegram_chat_id' => 555]);
    $this->postJson('/tg/app/login', ['init_data' => initData(['id' => 555], [], 'stolen-guess')])->assertStatus(422);
    $this->assertGuest();
});

it('asks a new Telegram account for its phone once, then logs it in', function () {
    $res = $this->postJson('/tg/app/login', ['init_data' => initData(['id' => 888, 'first_name' => 'Vahe']), 'path' => '/me/bookings'])
        ->assertOk()->assertJson(['status' => 'need_contact']);
    $token = $res->json('token');

    // Telegram.WebApp.requestContact(): the contact reaches our bot
    $this->postJson('/telegram/webhook/hook-secret', ['update_id' => 1, 'message' => ['message_id' => 1, 'chat' => ['id' => 888, 'type' => 'private'],
        'from' => ['id' => 888, 'first_name' => 'Vahe'], 'contact' => ['user_id' => 888, 'phone_number' => '37493888777', 'first_name' => 'Vahe']]], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret']);

    $this->getJson("/login/telegram/{$token}")->assertJson(['status' => 'approved']);
    $this->postJson("/login/telegram/{$token}/complete", ['context' => 'login'])->assertJson(['redirect' => url('/me/bookings')]);
    expect(auth()->user()->phone)->toBe('+37493888777');
});

it('sets the bot menu button to open Bookball', function () {
    $this->artisan('telegram:setup')->assertSuccessful();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/setChatMenuButton') && $r['menu_button']['web_app']['url'] === 'https://bookball.am/');
});

// ---------- chat messages by Telegram ----------

function chatPair(): array
{
    $a = User::factory()->create(['telegram_chat_id' => 7101, 'name' => 'Aram']);
    $b = User::factory()->create(['telegram_chat_id' => 7102, 'name' => 'Davit']);
    Friendship::create(['user_id' => $a->id, 'friend_id' => $b->id, 'status' => 'accepted', 'accepted_at' => now()]);

    return [$a, $b, app(Chat::class)->direct($a, $b)];
}

function chatTelegrams(int $chatId): int
{
    return collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/sendMessage') && $p[0]['chat_id'] == $chatId)->count();
}

it('tells an unread chat member by Telegram, with a button that opens the chat', function () {
    [$a, $b, $c] = chatPair();
    app(Chat::class)->send($c, $a, 'Who brings the ball?');

    expect(chatTelegrams(7102))->toBe(1)->and(chatTelegrams(7101))->toBe(0);   // not the sender
    Http::assertSent(fn (Request $r) => $r['chat_id'] == 7102 && str_contains($r['text'], 'Who brings the ball?')
        && $r['reply_markup']['inline_keyboard'][0][0]['web_app']['url'] === "https://bookball.am/messages/{$c->id}");
});

it('groups a busy chat: one Telegram message per 5 minutes', function () {
    [$a, , $c] = chatPair();
    foreach (['one', 'two', 'three'] as $t) {
        app(Chat::class)->send($c, $a, $t);
    }
    expect(chatTelegrams(7102))->toBe(1);
    $this->travel(6)->minutes();
    app(Chat::class)->send($c, $a, 'four');
    expect(chatTelegrams(7102))->toBe(2);
    Http::assertSent(fn (Request $r) => $r['chat_id'] == 7102 && str_contains($r['text'], 'four') && str_contains($r['text'], '+3'));
});

it('does not tell people who read it or muted the chat', function () {
    [$a, $b, $c] = chatPair();
    ConversationParticipant::where('conversation_id', $c->id)->where('user_id', $b->id)->update(['muted_until' => now()->addDay()]);
    app(Chat::class)->send($c, $a, 'muted');
    expect(chatTelegrams(7102))->toBe(0);
});

it('goes back to the page that needed a login', function () {
    User::factory()->create(['telegram_chat_id' => 555]);
    $this->get('/me/bookings')->assertRedirect(route('login'));   // a guest is sent to /login first
    $this->postJson('/tg/app/login', ['init_data' => initData(['id' => 555]), 'path' => '/login'])->assertJson(['redirect' => '/me/bookings']);
});
