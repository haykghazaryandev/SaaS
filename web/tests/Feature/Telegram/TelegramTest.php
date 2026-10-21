<?php

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\DecideBooking;
use App\Domain\Booking\Actions\RequestBooking;
use App\Domain\Notifications\TelegramLinker;
use App\Enums\BookingStatus;
use App\Models\NotificationLog;
use App\Models\TelegramLinkToken;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.telegram.bot_token' => 'test-token', 'services.telegram.bot_username' => 'BookballBot', 'services.telegram.webhook_secret' => 'hook-secret']);
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);

    $this->venue = makeVenue();
    $this->small = $this->venue->fields->firstWhere('size.value', 'small');
    $this->player = User::factory()->create(['locale' => 'en', 'telegram_chat_id' => 5001]);
    $this->owner = User::factory()->create(['locale' => 'en', 'telegram_chat_id' => 7001, 'name' => 'Gor Owner']);
    $this->venue->staff()->attach($this->owner->id, ['role' => 'owner']);
});

function webhook($test, array $update, string $secret = 'hook-secret', ?string $header = 'hook-secret')
{
    return $test->postJson('/telegram/webhook/'.$secret, $update, $header ? ['X-Telegram-Bot-Api-Secret-Token' => $header] : []);
}

function sentTo(int $chatId, string $method = 'sendMessage'): array
{
    return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/'.$method) && ($r['chat_id'] ?? null) == $chatId)->map(fn ($p) => $p[0])->values()->all();
}

it('creates a link token and returns the deep link', function () {
    $user = User::factory()->create();
    $res = $this->actingAs($user)->postJson('/telegram/link')->assertOk();
    $token = TelegramLinkToken::where('user_id', $user->id)->firstOrFail();
    expect($res->json('url'))->toBe('https://t.me/BookballBot?start='.$token->token)
        ->and($token->expires_at->between(now()->addMinutes(29), now()->addMinutes(31)))->toBeTrue();
    $this->getJson('/telegram/status')->assertExactJson(['linked' => false]);
});

it('needs a login for the link and the status', function () {
    $this->postJson('/telegram/link')->assertUnauthorized();
    $this->getJson('/telegram/status')->assertUnauthorized();
});

it('links the chat on /start <token> and says hello in the user language', function () {
    $user = User::factory()->create(['locale' => 'ru', 'name' => 'Aram']);
    $token = app(TelegramLinker::class)->createToken($user);

    webhook($this, ['update_id' => 1, 'message' => ['message_id' => 1, 'chat' => ['id' => 9001, 'type' => 'private'], 'from' => ['id' => 9001, 'language_code' => 'en'], 'text' => '/start '.$token->token]])->assertOk();

    expect($user->fresh()->telegram_chat_id)->toBe(9001)->and($token->fresh()->used_at)->not->toBeNull();
    $sent = sentTo(9001);
    expect($sent)->toHaveCount(1)->and($sent[0]['text'])->toContain('Telegram подключён');
    $this->actingAs($user->fresh())->getJson('/telegram/status')->assertExactJson(['linked' => true]);
});

it('refuses an expired or used token', function () {
    $user = User::factory()->create();
    $token = TelegramLinkToken::create(['user_id' => $user->id, 'token' => 'old-token', 'expires_at' => now()->subMinute()]);
    webhook($this, ['update_id' => 2, 'message' => ['chat' => ['id' => 9002, 'type' => 'private'], 'from' => ['id' => 9002, 'language_code' => 'en'], 'text' => '/start old-token']])->assertOk();
    expect($user->fresh()->telegram_chat_id)->toBeNull()->and(sentTo(9002)[0]['text'])->toContain('expired');
});

it('rejects webhook calls without the right secret', function () {
    webhook($this, ['update_id' => 3], 'wrong')->assertNotFound();
    webhook($this, ['update_id' => 3], 'hook-secret', 'wrong-header')->assertNotFound();
    webhook($this, ['update_id' => 3], 'hook-secret', null)->assertNotFound();
});

it('tells the player and the owner about a new request, then sends the code when accepted', function () {
    $b = app(RequestBooking::class)->handle($this->player, $this->small, at(20), 60);

    $toPlayer = sentTo(5001);
    expect($toPlayer)->toHaveCount(1)->and($toPlayer[0]['text'])->toContain('Request sent')->toContain('Small field 5×5')->toContain('20:00–21:00')->toContain('20 000 ֏');
    $toOwner = sentTo(7001);
    expect($toOwner)->toHaveCount(1)->and($toOwner[0]['text'])->toContain('New request')
        ->and($toOwner[0]['reply_markup']['inline_keyboard'][0][0]['callback_data'])->toBe('accept:'.$b->id)
        ->and($toOwner[0]['reply_markup']['inline_keyboard'][0][1]['callback_data'])->toBe('decline:'.$b->id);

    app(DecideBooking::class)->accept($b, $this->owner);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] == 5001 && str_contains($r['text'], $b->code) && str_contains($r['text'], 'Confirmed'));
    expect(NotificationLog::where('user_id', $this->player->id)->pluck('type')->all())->toBe(['booking.requested', 'booking.accepted'])
        ->and(NotificationLog::where('status', 'sent')->count())->toBe(3);
});

it('writes in Armenian for Armenian users, with Yerevan time', function () {
    $this->player->forceFill(['locale' => 'hy'])->save();
    app(RequestBooking::class)->handle($this->player->fresh(), $this->small, at(20), 60);
    expect(sentTo(5001)[0]['text'])->toContain('Հայտն ուղարկվեց')->toContain('Փոքր դաշտ 5×5')->toContain('20:00–21:00');
});

it('skips people without Telegram', function () {
    $this->player->forceFill(['telegram_chat_id' => null])->save();
    app(RequestBooking::class)->handle($this->player->fresh(), $this->small, at(20), 60);
    expect(sentTo(5001))->toBeEmpty()->and(sentTo(7001))->toHaveCount(1);
});

it('tells the owner when the player cancels, and the player when the place cancels', function () {
    $b = app(RequestBooking::class)->handle($this->player, $this->small, at(20), 60);
    app(CancelBooking::class)->handle($b, $this->player, 'player');
    expect(sentTo(7001))->toHaveCount(2)->and(sentTo(7001)[1]['text'])->toContain('cancelled')->and(sentTo(5001))->toHaveCount(1);

    $b2 = app(RequestBooking::class)->handle($this->player, $this->small, at(21), 60);
    app(CancelBooking::class)->handle($b2, $this->owner, 'owner', 'Rain');
    expect(collect(sentTo(5001))->last()['text'])->toContain('was cancelled')->toContain('Rain');
});

it('sends the decline reason to the player', function () {
    $b = app(RequestBooking::class)->handle($this->player, $this->small, at(20), 60);
    app(DecideBooking::class)->decline($b, $this->owner, 'Closed for a match');
    expect(collect(sentTo(5001))->last()['text'])->toContain('declined')->toContain('Closed for a match');
});

it('lets staff accept a request with the Telegram button', function () {
    $b = app(RequestBooking::class)->handle($this->player, $this->small, at(20), 60);

    webhook($this, ['update_id' => 10, 'callback_query' => [
        'id' => 'cb1', 'from' => ['id' => 7001], 'data' => 'accept:'.$b->id,
        'message' => ['message_id' => 77, 'chat' => ['id' => 7001, 'type' => 'private']],
    ]])->assertOk();

    expect($b->fresh()->status)->toBe(BookingStatus::Accepted)->and($b->fresh()->decided_by)->toBe($this->owner->id);
    $edit = sentTo(7001, 'editMessageText');
    expect($edit)->toHaveCount(1)->and($edit[0]['message_id'])->toBe(77)->and($edit[0]['text'])->toContain('Accepted by Gor Owner');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/answerCallbackQuery') && $r['callback_query_id'] === 'cb1');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] == 5001 && str_contains($r['text'], $b->code));
});

it('lets staff decline with the button', function () {
    $b = app(RequestBooking::class)->handle($this->player, $this->small, at(20), 60);
    webhook($this, ['update_id' => 11, 'callback_query' => ['id' => 'cb2', 'from' => ['id' => 7001], 'data' => 'decline:'.$b->id,
        'message' => ['message_id' => 78, 'chat' => ['id' => 7001, 'type' => 'private']]]])->assertOk();
    expect($b->fresh()->status)->toBe(BookingStatus::Declined);
});

it('does not let people who are not staff of the place answer', function () {
    $b = app(RequestBooking::class)->handle($this->player, $this->small, at(20), 60);
    $other = User::factory()->create(['telegram_chat_id' => 8001]);
    $otherVenue = makeVenue();
    $otherVenue->staff()->attach($other->id, ['role' => 'owner']);

    webhook($this, ['update_id' => 12, 'callback_query' => ['id' => 'cb3', 'from' => ['id' => 8001], 'data' => 'accept:'.$b->id,
        'message' => ['message_id' => 79, 'chat' => ['id' => 8001, 'type' => 'private']]]])->assertOk();

    expect($b->fresh()->status)->toBe(BookingStatus::Pending);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/answerCallbackQuery') && $r['callback_query_id'] === 'cb3' && $r['show_alert'] === true);
    expect(sentTo(8001, 'editMessageText'))->toBeEmpty();
});

it('shows the current status when a request was already answered', function () {
    $b = app(RequestBooking::class)->handle($this->player, $this->small, at(20), 60);
    app(DecideBooking::class)->decline($b, $this->owner);
    webhook($this, ['update_id' => 13, 'callback_query' => ['id' => 'cb4', 'from' => ['id' => 7001], 'data' => 'accept:'.$b->id,
        'message' => ['message_id' => 80, 'chat' => ['id' => 7001, 'type' => 'private']]]])->assertOk();
    expect($b->fresh()->status)->toBe(BookingStatus::Declined)->and(sentTo(7001, 'editMessageText')[0]['text'])->toContain('Declined by');
});

it('only logs messages when no bot token is set', function () {
    config(['services.telegram.bot_token' => null]);
    app(RequestBooking::class)->handle($this->player, $this->small, at(20), 60);
    Http::assertNothingSent();
    expect(NotificationLog::where('status', 'logged')->count())->toBe(2);
});

it('logs a failed send without breaking the booking', function () {
    Http::swap(new Factory);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
    $b = app(RequestBooking::class)->handle($this->player, $this->small, at(20), 60);
    expect($b->exists)->toBeTrue()->and(NotificationLog::where('status', 'failed')->first()->error)->toContain('blocked');
});

it('sets the webhook with the command', function () {
    config(['app.url' => 'https://bookball.am']);
    $this->artisan('telegram:set-webhook')->assertSuccessful();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/bottest-token/setWebhook') && $r['url'] === 'https://bookball.am/telegram/webhook/hook-secret' && $r['secret_token'] === 'hook-secret');
});
