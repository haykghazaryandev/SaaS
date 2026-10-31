<?php

use App\Domain\Chat\Chat;
use App\Filament\Support\Options;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Venue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->a = makeVenue();
    $this->b = makeVenue();
    $this->a->staff()->attach($this->owner->id, ['role' => 'owner']);
    $this->b->staff()->attach($this->owner->id, ['role' => 'owner']);
});

it('shows in the place panel only the chats of the place picked, with their unread count', function () {
    $chat = app(Chat::class);
    $player = User::factory()->create();
    $toA = $chat->withVenue($player, $this->a);
    $toB = $chat->withVenue($player, $this->b);
    $chat->send($toA, $player, 'Hi A');
    $chat->send($toB, $player, 'Hi B');
    $chat->send($toB, $player, 'Still B');
    // the person's own chat with a friend is not the place's
    $dm = Conversation::create(['type' => 'direct', 'direct_key' => 'test', 'last_message_at' => now()]);
    $chat->join($dm, $this->owner->id);
    $chat->join($dm, $player->id);

    $this->actingAs($this->owner)->withSession(['venue_id' => $this->a->id])->get('/owner/messages')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->has('chats', 1)->where('chats.0.id', $toA->id)->where('owner.unreadChats', 1));
    $this->actingAs($this->owner)->withSession(['venue_id' => $this->b->id])->get('/owner/messages')
        ->assertInertia(fn (Assert $p) => $p->has('chats', 1)->where('chats.0.id', $toB->id)->where('owner.unreadChats', 2));
    // the mini chat asks for one place; a place the person doesn't run: all of theirs
    expect($this->actingAs($this->owner)->getJson(route('messages.list', ['venue' => $this->a->id]))->json('chats'))->toHaveCount(1);
    $other = makeVenue();
    expect(count($this->actingAs($this->owner)->getJson(route('messages.list', ['venue' => $other->id]))->json('chats')))->toBeGreaterThan(1);

    // the badge adds up like the list: a closed chat and a muted one don't count
    $toB->update(['status' => 'archived']);
    $this->actingAs($this->owner)->withSession(['venue_id' => $this->b->id])->get('/owner/messages')->assertInertia(fn (Assert $p) => $p->where('owner.unreadChats', 0));
    $chat->mute($toA, $this->owner, true);
    $this->actingAs($this->owner)->withSession(['venue_id' => $this->a->id])->get('/owner/messages')->assertInertia(fn (Assert $p) => $p->where('owner.unreadChats', 0));
});

it('has no rating for football, even with the feature switched on', function () {
    $this->a->update(['features' => [...Venue::DEFAULT_FEATURES, 'wallet', 'rating']]);
    expect($this->a->fresh()->hasFeature('rating'))->toBeFalse()->and($this->a->fresh()->featureList())->not->toContain('rating');
    $player = User::factory()->create();
    $this->actingAs($this->owner)->withSession(['venue_id' => $this->a->id])->post(route('owner.players.rating', $player->id), ['rating' => 1500])->assertNotFound();
});

it('shows our error page for a page that is not there, and JSON stays JSON', function () {
    $this->get('/no-such-page')->assertNotFound()->assertInertia(fn (Assert $p) => $p->component('Error')->where('status', 404));
    $this->getJson('/no-such-page')->assertNotFound()->assertJsonStructure(['message']);
});

it('gives each of the 7 days how full it is', function () {
    $this->actingAs($this->owner)->withSession(['venue_id' => $this->a->id])->get('/owner/schedule')
        ->assertInertia(fn (Assert $p) => $p->has('days', 7)->has('days.0.full')->where('days.0.full', fn ($v) => $v >= 0 && $v <= 100));
});

it('gives every sport the club tools by default: cards, rating and tournaments only for ping-pong', function () {
    $field = makeVenue();
    expect($field->featureList())->toContain('desk', 'shop', 'trainings', 'money', 'booking')->not->toContain('wallet', 'rating', 'tournaments');
    $club = makeClub(1);
    expect($club->featureList())->toContain('desk', 'wallet', 'rating', 'tournaments', 'shop', 'money');
    // the admin can still give a football place cards
    $field->update(['features' => [...Venue::DEFAULT_FEATURES, 'desk', 'wallet']]);
    expect($field->fresh()->hasFeature('wallet'))->toBeTrue();
    // the admin form explains where the sport matters
    expect(Options::featureHelp()['wallet'])->toContain(__('admin.feature.wallet_sport'));
});
