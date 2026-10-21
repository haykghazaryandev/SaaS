<?php

use App\Domain\Notifications\TelegramUpdateHandler;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.telegram.bot_token' => 'test-token']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
});

it('shows the language choice on /language and saves it for the bot and the website', function () {
    $u = User::factory()->create(['telegram_chat_id' => 777001, 'locale' => 'hy']);
    app(TelegramUpdateHandler::class)->handle(['update_id' => 1, 'message' => ['from' => ['id' => 777001], 'chat' => ['id' => 777001, 'type' => 'private'], 'text' => '/language']]);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && ($r['reply_markup']['inline_keyboard'][0][1]['callback_data'] ?? '') === 'lang:ru');

    app(TelegramUpdateHandler::class)->handle(['update_id' => 2, 'callback_query' => ['id' => 'q1', 'from' => ['id' => 777001], 'data' => 'lang:ru', 'message' => ['message_id' => 5, 'chat' => ['id' => 777001]]]]);
    expect($u->fresh()->locale)->toBe('ru');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'editMessageText') && str_contains($r['text'], 'русск'));
});

it('asks unknown chats to log in before choosing a language', function () {
    app(TelegramUpdateHandler::class)->handle(['update_id' => 3, 'callback_query' => ['id' => 'q2', 'from' => ['id' => 777002], 'data' => 'lang:en', 'message' => ['message_id' => 6, 'chat' => ['id' => 777002]]]]);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'answerCallbackQuery') && ($r['show_alert'] ?? false));
});

it('adds the language button under the login message', function () {
    $token = $this->postJson('/login/telegram')->json('token');
    $from = ['id' => 777003, 'first_name' => 'Ani'];
    app(TelegramUpdateHandler::class)->handle(['update_id' => 4, 'message' => ['from' => $from, 'chat' => ['id' => 777003, 'type' => 'private'], 'text' => "/start login_{$token}"]]);
    app(TelegramUpdateHandler::class)->handle(['update_id' => 5, 'message' => ['from' => $from, 'chat' => ['id' => 777003, 'type' => 'private'], 'contact' => ['user_id' => 777003, 'phone_number' => '+37494555001']]]);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && collect($r['reply_markup']['inline_keyboard'] ?? [])->flatten(1)->contains(fn ($b) => ($b['callback_data'] ?? '') === 'lang:menu'));
});
