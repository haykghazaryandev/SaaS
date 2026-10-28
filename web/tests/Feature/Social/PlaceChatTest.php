<?php

use App\Domain\Chat\Chat;
use App\Http\Presenters\SocialCounts;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\User;
use App\Notifications\Notice;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->venue = makeVenue();
    $this->owner = User::factory()->create(['name' => 'Gor Owner']);
    $this->venue->staff()->attach($this->owner->id, ['role' => 'owner']);
    $this->player = User::factory()->create(['name' => 'Aram']);
});

it('lets a player write to a place; its staff read and answer in their messages', function () {
    $this->actingAs($this->player)->get(route('places.show', $this->venue->slug))->assertInertia(fn (Assert $p) => $p->where('venue.canMessage', true));
    $this->actingAs($this->player)->post(route('messages.venue', $this->venue->slug))->assertRedirect();
    $c = Conversation::where('type', 'venue')->where('venue_id', $this->venue->id)->firstOrFail();
    // one chat per player and place
    $this->actingAs($this->player)->post(route('messages.venue', $this->venue->slug));
    expect(Conversation::where('type', 'venue')->count())->toBe(1);

    $this->actingAs($this->player)->postJson(route('messages.send', $c->id), ['body' => 'Do you have balls?'])->assertCreated();
    // the owner sees it (with the player's name) and answers for the place
    $this->actingAs($this->owner)->get(route('messages.index'))->assertInertia(fn (Assert $p) => $p->where('chats.0.type', 'venue')
        ->where('chats.0.venue.staff', true)->where('chats.0.venue.player.name', 'Aram'));
    $this->actingAs($this->owner)->postJson(route('messages.send', $c->id), ['body' => 'Yes'])->assertCreated();
    $this->actingAs($this->player)->get(route('messages.show', $c->id))->assertInertia(fn (Assert $p) => $p->where('chat.venue.name', $this->venue->name)
        ->where('chat.venue.staff', false)->has('messages', 2));
    // the place's own staff don't open player pages at all
    $this->actingAs($this->owner)->get(route('places.show', $this->venue->slug))->assertRedirect(route('owner.start'));
});

it('shows in a group chat who is a friend, so a member can be added from there', function () {
    $c = Conversation::create(['type' => 'venue', 'venue_id' => $this->venue->id, 'direct_key' => 'x']);
    $friend = User::factory()->create();
    foreach ([$this->player, $this->owner, $friend] as $u) {
        $c->participants()->create(['user_id' => $u->id, 'joined_at' => now()]);
    }
    Friendship::create(['user_id' => $this->player->id, 'friend_id' => $friend->id, 'status' => 'accepted', 'accepted_at' => now()]);
    $this->actingAs($this->player)->get(route('messages.show', $c->id))->assertInertia(fn (Assert $p) => $p
        ->where("chat.people.{$friend->id}", 'friends')->where("chat.people.{$this->owner->id}", 'none')->missing("chat.people.{$this->player->id}"));
    $this->actingAs($this->player)->post(route('friends.request', $this->owner->id))->assertRedirect();
    $this->actingAs($this->player)->get(route('messages.show', $c->id))->assertInertia(fn (Assert $p) => $p->where("chat.people.{$this->owner->id}", 'sent'));
});

it('mutes a chat (no badge), deletes a direct / place chat for me until someone writes, and leaves a group chat', function () {
    $this->actingAs($this->player)->post(route('messages.venue', $this->venue->slug));
    $c = Conversation::where('type', 'venue')->firstOrFail();
    $this->actingAs($this->owner)->postJson(route('messages.send', $c->id), ['body' => 'Hello'])->assertCreated();
    expect(app(SocialCounts::class)->for($this->player->fresh())['messages'])->toBe(1);
    // muted: still in the list with its number, not in the badge
    $this->actingAs($this->player)->post(route('messages.mute', $c->id), ['on' => 1]);
    expect(app(SocialCounts::class)->for($this->player->fresh())['messages'])->toBe(0);
    $this->actingAs($this->player)->get(route('messages.index'))->assertInertia(fn (Assert $p) => $p->where('chats.0.muted', true)->where('chats.0.unread', 1));
    // deleted for me: gone; a new message brings it back with only that message
    $this->actingAs($this->player)->post(route('messages.remove', $c->id))->assertRedirect(route('messages.index'));
    $this->actingAs($this->player)->get(route('messages.index'))->assertInertia(fn (Assert $p) => $p->has('chats', 0));
    $this->travel(1)->seconds();
    $this->actingAs($this->owner)->postJson(route('messages.send', $c->id), ['body' => 'Still there?'])->assertCreated();
    $this->actingAs($this->player)->get(route('messages.index'))->assertInertia(fn (Assert $p) => $p->has('chats', 1));
    $this->actingAs($this->player)->get(route('messages.show', $c->id))->assertInertia(fn (Assert $p) => $p->has('messages', 1)->where('messages.0.body', 'Still there?'));

    // a group chat that is on: leave (not just delete)
    $g = Conversation::create(['type' => 'tournament', 'last_message_at' => now()]);
    foreach ([$this->player, $this->owner] as $u) {
        $g->participants()->create(['user_id' => $u->id, 'joined_at' => now()]);
    }
    $this->actingAs($this->player)->post(route('messages.remove', $g->id))->assertSessionHas('error');
    $this->actingAs($this->player)->post(route('messages.leave', $g->id))->assertRedirect(route('messages.index'));
    expect(app(Chat::class)->isMember($g, $this->player))->toBeFalse()
        ->and($g->messages()->where('body', 'chat.sys.left_chat')->exists())->toBeTrue();
    // a finished one: just off the list
    $g->participants()->where('user_id', $this->owner->id)->update(['left_at' => null]);
    $g->update(['status' => 'archived']);
    $this->actingAs($this->owner)->post(route('messages.remove', $g->id))->assertRedirect(route('messages.index'));
    $this->actingAs($this->owner)->get(route('messages.index'))->assertInertia(fn (Assert $p) => $p->where('archived', []));
});

it('lists the members of a group chat and tells the ones picked with "@"', function () {
    Notification::fake();
    $g = Conversation::create(['type' => 'tournament', 'last_message_at' => now()]);
    $mate = User::factory()->create(['name' => 'Davit']);
    foreach ([$this->player, $mate] as $u) {
        $g->participants()->create(['user_id' => $u->id, 'joined_at' => now()]);
    }
    $this->actingAs($this->player)->get(route('messages.show', $g->id))->assertInertia(fn (Assert $p) => $p->has('chat.memberList', 2));
    $outsider = User::factory()->create();
    $this->actingAs($this->player)->postJson(route('messages.send', $g->id), ['body' => '@Davit see you at 7', 'mentions' => [$mate->id, $outsider->id, $this->player->id]])
        ->assertCreated()->assertJsonPath('message.meta.mentions', [$mate->id]);
    // even in a muted chat
    Notification::assertSentTo($mate, Notice::class, fn ($n) => $n->type === 'chat_mention');
    Notification::assertNotSentTo($outsider, Notice::class);
});

it('lets two members of a group chat write to each other (tap a member → Message)', function () {
    $g = Conversation::create(['type' => 'tournament', 'last_message_at' => now()]);
    $mate = User::factory()->create();
    $stranger = User::factory()->create();
    foreach ([$this->player, $mate] as $u) {
        $g->participants()->create(['user_id' => $u->id, 'joined_at' => now()]);
    }
    $chat = app(Chat::class);
    expect($chat->canMessage($this->player, $mate))->toBeTrue()->and($chat->canMessage($this->player, $stranger))->toBeFalse();
    $this->actingAs($this->player)->post(route('messages.direct', $mate->id))->assertRedirect();
    expect(Conversation::where('type', 'direct')->where('direct_key', Conversation::directKey($this->player->id, $mate->id))->exists())->toBeTrue();
    // after leaving the group chat: no more
    $g->participants()->where('user_id', $mate->id)->update(['left_at' => now()]);
    expect($chat->canMessage($this->player, $mate))->toBeFalse();
});
