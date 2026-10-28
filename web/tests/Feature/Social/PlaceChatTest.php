<?php

use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\User;
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
    // the place's own staff don't write to themselves
    $this->actingAs($this->owner)->get(route('places.show', $this->venue->slug))->assertInertia(fn (Assert $p) => $p->where('venue.canMessage', false));
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
