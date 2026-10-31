<?php

use App\Domain\Booking\Actions\RequestBooking;
use App\Domain\Chat\Chat;
use App\Domain\Notifications\BookingNotification;
use App\Domain\Notifications\TelegramChannel;
use App\Models\Friendship;
use App\Models\User;
use App\Notifications\Notice;
use Illuminate\Support\Facades\Http;

function viaTelegram(User $u, Notice $n): bool
{
    return in_array(TelegramChannel::class, $n->via($u), true);
}

it('sends social news by Telegram only while the app is not open; booking news always', function () {
    $u = User::factory()->create(['telegram_chat_id' => 7001, 'last_seen_at' => now()->subMinutes(10)]);
    expect(viaTelegram($u, new Notice('friend_accepted', ['name' => 'Aram'])))->toBeTrue();   // away: Telegram

    $u->forceFill(['last_seen_at' => now()])->save();
    expect(viaTelegram($u, new Notice('friend_accepted', ['name' => 'Aram'])))->toBeFalse()   // in the app: bell + toast
        ->and(viaTelegram($u, new Notice('booking_reminder_hour')))->toBeTrue();               // bookings always
});

it('respects the switches in Profile → Notifications', function () {
    $u = User::factory()->create(['telegram_chat_id' => 7002]);
    $this->actingAs($u)->patch('/me/notifications', ['key' => 'friends', 'on' => false])->assertSessionHasNoErrors();
    $this->actingAs($u)->patch('/me/notifications', ['key' => 'bookings', 'on' => false]);
    $u->refresh();
    $u->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();   // away (the requests above marked them active)

    expect(viaTelegram($u, new Notice('friend_request', ['name' => 'A'])))->toBeFalse()
        ->and(viaTelegram($u, new Notice('booking_reminder_day')))->toBeFalse()
        ->and(viaTelegram($u, new Notice('game_invite', ['name' => 'A', 'format' => '5×5'])))->toBeTrue()
        ->and((new BookingNotification(app(RequestBooking::class)->handle($u, makeVenue()->fields->first(), at(20), 60), 'accepted', 'player'))->via($u))->toBe([]);
    // the bell keeps everything
    expect((new Notice('friend_request', ['name' => 'A']))->via($u))->toContain('database');

    $this->actingAs($u)->patch('/me/notifications', ['key' => 'nonsense', 'on' => false])->assertSessionHasErrors('key');
    $this->actingAs($u)->patch('/me/notifications', ['key' => 'sound', 'on' => false]);
    $this->actingAs($u)->get('/me/profile')->assertInertia(fn ($p) => $p->where('notify.sound', false)->where('notify.friends', false)->where('notify.games', true)->where('auth.user.sound', false));
});

it('does not send chat messages by Telegram when chats are switched off', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    config(['services.telegram.bot_token' => 'test-token', 'app.url' => 'https://bookball.am']);
    $a = User::factory()->create(['telegram_chat_id' => 7101]);
    $b = User::factory()->create(['telegram_chat_id' => 7102, 'notify_prefs' => ['chats' => false]]);
    Friendship::create(['user_id' => $a->id, 'friend_id' => $b->id, 'status' => 'accepted', 'accepted_at' => now()]);
    $c = app(Chat::class)->direct($a, $b);
    app(Chat::class)->send($c, $a, 'hello');

    Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] == 7102);
});
