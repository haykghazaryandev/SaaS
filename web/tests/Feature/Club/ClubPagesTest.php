<?php

use App\Domain\Club\Cards;
use App\Models\Booking;
use App\Models\Product;
use App\Models\Tournament;
use App\Models\Training;
use App\Models\User;
use App\Models\Venue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->club = makeVenue();
    $this->club->update(['features' => [...Venue::DEFAULT_FEATURES, 'wallet', 'rating', 'trainings', 'shop', 'tournaments', 'money']]);
    $this->owner = User::factory()->create();
    $this->club->staff()->attach($this->owner->id, ['role' => 'owner']);
    $this->player = User::factory()->create(['name' => 'Gor Petrosyan']);
    $this->card = app(Cards::class)->forPlayer($this->club, $this->player);
});

it('opens every club page for the owner, and none where the feature is off', function () {
    foreach (['/owner/players', '/owner/cards/'.$this->card->code, '/owner/trainings', '/owner/shop', '/owner/tournaments', '/owner/money'] as $url) {
        $this->actingAs($this->owner)->get($url)->assertOk();
    }
    $this->club->update(['features' => ['booking']]);
    foreach (['/owner/players', '/owner/trainings', '/owner/shop', '/owner/tournaments', '/owner/money'] as $url) {
        $this->actingAs($this->owner)->get($url)->assertNotFound();
    }
});

it('opens a card only for the staff of its own club', function () {
    $otherOwner = User::factory()->create();
    $other = makeVenue();
    $other->update(['features' => ['booking', 'wallet']]);
    $other->staff()->attach($otherOwner->id, ['role' => 'owner']);
    $this->actingAs($otherOwner)->get('/owner/cards/'.$this->card->code)->assertNotFound();
    $this->actingAs($otherOwner)->post('/owner/cards/'.$this->card->code.'/topup', ['amount' => 1000])->assertNotFound();
    expect($this->card->fresh()->balance)->toBe(0);
});

it('sends a scanned QR to the card for staff, to my cards for the player, to the club page for others', function () {
    $this->actingAs($this->owner)->get('/c/'.$this->card->code)->assertRedirect(route('owner.cards.show', $this->card->code));
    $this->actingAs($this->player)->get('/c/'.$this->card->code)->assertRedirect(route('me.cards', ['venue' => $this->club->slug]));
    $this->actingAs(User::factory()->create())->get('/c/'.$this->card->code)->assertRedirect(route('places.show', $this->club->slug));
    $this->get('/c/'.str_repeat('a', 20))->assertNotFound();
});

it('lets the owner top up, take payments and set discount and rating on the card page', function () {
    $base = '/owner/cards/'.$this->card->code;
    $this->actingAs($this->owner)->post("$base/topup", ['amount' => 10000])->assertRedirect();
    $this->actingAs($this->owner)->post("$base/discount", ['discount' => 10])->assertRedirect();
    $this->actingAs($this->owner)->post("$base/charge", ['amount' => 2000, 'method' => 'card', 'note' => 'balls'])->assertRedirect();
    $this->actingAs($this->owner)->post("$base/rating", ['rating' => 1450, 'intl_id' => 'ARM-7'])->assertRedirect();
    expect($this->card->fresh()->balance)->toBe(10000 - 1800);
    $this->actingAs($this->owner)->get($base)->assertInertia(fn (Assert $p) => $p->component('owner/Card')
        ->where('card.balance', 8200)->where('card.discount', 10)->where('rating.rating', 1450)->has('history', 2));
    $this->actingAs($this->owner)->post("$base/charge", ['amount' => 900000, 'method' => 'card'])->assertSessionHasErrors('booking');
});

it('marks a training visit and sells from the card page; the player sees the card with its QR', function () {
    $t = Training::create(['venue_id' => $this->club->id, 'title' => 'Kids', 'weekdays' => [0, 1, 2, 3, 4, 5, 6], 'starts_at' => '18:00', 'minutes' => 60, 'price' => 2000]);
    $p = Product::create(['venue_id' => $this->club->id, 'name' => 'Balls', 'price' => 1500]);
    $base = '/owner/cards/'.$this->card->code;
    $this->actingAs($this->owner)->post("$base/visit/{$t->id}", ['method' => 'cash'])->assertRedirect();
    $this->actingAs($this->owner)->post("$base/sell/{$p->id}", ['qty' => 2, 'method' => 'cash'])->assertRedirect();
    $this->actingAs($this->owner)->get('/owner/money')->assertInertia(fn (Assert $pg) => $pg->where('report.sales', 5000)
        ->where('report.byKind.training', 2000)->where('report.byKind.shop', 3000));
    $this->actingAs($this->player)->get('/me/cards')->assertInertia(fn (Assert $pg) => $pg->component('player/Cards')
        ->has('cards', 1)->where('cards.0.venue.slug', $this->club->slug)->where('cards.0.qr', fn ($svg) => str_starts_with($svg, '<svg')));
    $this->actingAs($this->player)->get("/places/{$this->club->slug}")->assertInertia(fn (Assert $pg) => $pg
        ->where('club.card.balance', 0)->has('club.trainings', 1)->has('club.products', 1));
});

it('takes a booking payment once, from the booking sheet', function () {
    $small = $this->club->fields->firstWhere('size.value', 'small');
    $b = Booking::create(['code' => 'BB-777', 'venue_id' => $this->club->id, 'field_id' => $small->id, 'user_id' => $this->player->id,
        'starts_at' => at(10)->utc(), 'ends_at' => at(11)->utc(), 'status' => 'accepted', 'source' => 'player', 'contact_name' => 'Gor', 'price' => 15000]);
    $this->actingAs($this->owner)->post("/owner/bookings/{$b->id}/pay", ['method' => 'cash'])->assertRedirect();
    $this->actingAs($this->owner)->post("/owner/bookings/{$b->id}/pay", ['method' => 'cash'])->assertSessionHasErrors('booking');
    $this->actingAs($this->owner)->getJson("/owner/bookings/{$b->id}")->assertJsonPath('paid', 'cash')->assertJsonPath('card.code', $this->card->code);
});

it('registers for a tournament by its link, without an account', function () {
    $t = Tournament::create(['venue_id' => $this->club->id, 'sport' => 'pingpong', 'title' => 'Open', 'code' => 'abcd1234', 'starts_at' => now()->addWeek(),
        'options' => [['label' => 'Free', 'price' => 0], ['label' => 'Pro', 'price' => 5000]]]);
    $this->get('/t/abcd1234')->assertOk()->assertInertia(fn (Assert $p) => $p->component('player/Tournament')->where('tournament.open', true)->has('tournament.options', 2));
    $this->post('/t/abcd1234', ['first_name' => 'Arman', 'last_name' => 'Sargsyan', 'phone' => '+37491234567', 'level' => 1200, 'option' => 1])->assertRedirect();
    $this->post('/t/abcd1234', ['first_name' => 'Arman', 'last_name' => 'Sargsyan', 'phone' => '+37491234567', 'option' => 1])->assertSessionHasErrors('register');
    $this->actingAs($this->owner)->get('/owner/tournaments')->assertInertia(fn (Assert $p) => $p->has('current.list', 1)->where('current.list.0.price', 5000));
});
