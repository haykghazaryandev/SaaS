<?php

use App\Domain\Chat\Chat;
use App\Domain\Social\Friends;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/** "online" / "last seen" in chats: a and b are friends with a direct chat; c is a stranger */
beforeEach(function () {
    $this->chat = app(Chat::class);
    [$this->a, $this->b, $this->c] = User::factory()->count(3)->create(['last_seen_at' => null]);
    app(Friends::class)->accept($this->b, app(Friends::class)->request($this->a, $this->b));
    $this->dm = $this->chat->direct($this->a, $this->b);
});

function presenceGame(array $users): Conversation
{
    $c = Conversation::create(['type' => 'game', 'last_message_at' => now()]);
    foreach ($users as $u) {
        app(Chat::class)->join($c, $u->id);
    }

    return $c;
}

it('moves last_seen_at on requests, at most once a minute, without touching updated_at', function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    $this->a->forceFill(['last_seen_at' => now()->subSeconds(30), 'updated_at' => now()->subDay()])->save();

    $this->actingAs($this->a)->get('/messages')->assertOk();
    expect($this->a->fresh()->last_seen_at->toDateTimeString())->toBe('2026-10-24 11:59:30');   // 30 s ago: no write

    Carbon::setTestNow('2026-10-24 12:00:31');   // 61 s since the last write
    $this->actingAs($this->a->fresh())->get('/messages')->assertOk();
    $a = $this->a->fresh();
    expect($a->last_seen_at->toDateTimeString())->toBe('2026-10-24 12:00:31')
        ->and($a->updated_at->toDateTimeString())->toBe('2026-10-23 12:00:00');

    Carbon::setTestNow();
});

it('does nothing for guests', function () {
    $this->get('/')->assertOk();
    expect(User::whereNotNull('last_seen_at')->count())->toBe(0);
});

it('marks the reader / sender seen more often than the middleware (every 10 s)', function () {
    Carbon::setTestNow('2026-10-24 12:00:00');
    $msg = $this->chat->send($this->dm, $this->b, 'Hi!');
    $this->a->forceFill(['last_seen_at' => now()->subSeconds(20)])->save();

    $this->actingAs($this->a)->postJson(route('messages.read', $this->dm), ['id' => $msg->id])->assertOk();
    expect($this->a->fresh()->last_seen_at->toDateTimeString())->toBe('2026-10-24 12:00:00');

    Carbon::setTestNow('2026-10-24 12:00:15');
    $this->actingAs($this->a->fresh())->postJson(route('messages.send', $this->dm), ['body' => 'Yes'])->assertCreated();
    expect($this->a->fresh()->last_seen_at->toDateTimeString())->toBe('2026-10-24 12:00:15');

    Carbon::setTestNow();
});

it('shows the other person online or their last seen time in a direct chat', function () {
    $this->b->forceFill(['last_seen_at' => now()->subMinute()])->save();
    $this->actingAs($this->a)->get(route('messages.show', $this->dm))->assertInertia(fn (Assert $p) => $p
        ->where('chat.other.online', true)
        ->where('chat.other.lastSeen', $this->b->fresh()->last_seen_at->toIso8601String())
        ->where('chat.online', null));

    $seen = now()->subMinutes(10)->startOfSecond();
    $this->b->forceFill(['last_seen_at' => $seen])->save();
    $this->actingAs($this->a)->get(route('messages.show', $this->dm))->assertInertia(fn (Assert $p) => $p
        ->where('chat.other.online', false)
        ->where('chat.other.lastSeen', $seen->toIso8601String()));

    // the chat list: the green dot on the avatar
    $this->b->forceFill(['last_seen_at' => now()])->save();
    $this->actingAs($this->a)->get('/messages')->assertInertia(fn (Assert $p) => $p->where('chats.0.other.online', true));
});

it('counts the other members of a game chat online now', function () {
    [$d, $e] = User::factory()->count(2)->create(['last_seen_at' => now()]);   // online
    $this->b->forceFill(['last_seen_at' => now()->subMinutes(5)])->save();    // offline
    $game = presenceGame([$this->a, $this->b, $d, $e]);
    $this->chat->leave($game, $e->id);                                         // left: not counted

    $this->actingAs($this->a)->get(route('messages.show', $game))->assertInertia(fn (Assert $p) => $p->where('chat.online', 1));   // d (not me)
    $this->actingAs($this->a)->getJson(route('messages.presence', $game))->assertOk()->assertExactJson(['other' => null, 'online' => 1]);
});

it('refreshes presence for members only', function () {
    $this->b->forceFill(['last_seen_at' => now()])->save();
    $this->actingAs($this->a)->getJson(route('messages.presence', $this->dm))->assertOk()
        ->assertJsonPath('other.online', true)
        ->assertJsonPath('online', null);

    // a stranger learns nothing about a or b
    $this->actingAs($this->c)->getJson(route('messages.presence', $this->dm))->assertForbidden()->assertJsonMissingPath('other');
    $this->actingAs($this->c)->get(route('messages.show', $this->dm))->assertForbidden();
});

it('tells the chat when a member read it (live "seen" ticks) and gives the read positions to the page', function () {
    Illuminate\Support\Facades\Event::fake([App\Domain\Chat\Events\MessagesRead::class]);
    $a = App\Models\User::factory()->create();
    $b = App\Models\User::factory()->create();
    App\Models\Friendship::create(['user_id' => $a->id, 'friend_id' => $b->id, 'status' => 'accepted', 'accepted_at' => now()]);
    $chat = app(App\Domain\Chat\Chat::class);
    $c = $chat->direct($a, $b);
    $m = $chat->send($c, $a, 'hi');

    $chat->markRead($c, $b, $m->id);
    Illuminate\Support\Facades\Event::assertDispatched(App\Domain\Chat\Events\MessagesRead::class, fn ($e) => $e->userId === $b->id && $e->messageId === $m->id && $e->conversationId === $c->id);
    $this->actingAs($a)->get("/messages/{$c->id}")->assertInertia(fn ($p) => $p->where("chat.reads.{$b->id}", $m->id));
});

it('uses the live list of open apps for "online" when Reverb answers', function () {
    $u = App\Models\User::factory()->create(['last_seen_at' => now()]);   // seen a moment ago…
    $this->mock(App\Domain\Social\Presence::class, fn ($m) => $m->shouldReceive('onlineIds')->andReturn([]));
    expect($u->isActiveNow())->toBeFalse();   // …but the app is closed: offline at once
});
