<?php

use App\Domain\Club\Cards;
use App\Models\Booking;
use App\Models\Product;
use App\Models\Tournament;
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
    foreach (['/owner/players', '/owner/players/'.$this->player->id, '/owner/cards', '/owner/cards/'.$this->card->code, '/owner/shop', '/owner/tournaments', '/owner/money'] as $url) {
        $this->actingAs($this->owner)->get($url)->assertOk();
    }
    $this->club->update(['features' => ['booking']]);
    foreach (['/owner/players', '/owner/cards', '/owner/shop', '/owner/tournaments', '/owner/money'] as $url) {
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

it('changes card money only after a scan; the rating is set on the player page', function () {
    $base = '/owner/cards/'.$this->card->code;
    // looking is fine, money is locked
    $this->actingAs($this->owner)->get($base)->assertInertia(fn (Assert $p) => $p->component('owner/Card')->where('card.scanned', false));
    $this->actingAs($this->owner)->post("$base/topup", ['amount' => 10000])->assertSessionHasErrors('booking');
    expect($this->card->fresh()->balance)->toBe(0);
    // the scan (the QR link) opens it
    $this->actingAs($this->owner)->get('/c/'.$this->card->code)->assertRedirect(route('owner.cards.show', $this->card->code));
    $this->actingAs($this->owner)->post("$base/topup", ['amount' => 10000])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post("$base/discount", ['discount' => 10])->assertRedirect();
    $this->actingAs($this->owner)->post("$base/charge", ['amount' => 2000, 'note' => 'balls'])->assertRedirect();
    expect($this->card->fresh()->balance)->toBe(10000 - 2000)->and($this->card->fresh()->discount_pct)->toBe(10);
    $this->actingAs($this->owner)->post("/owner/players/{$this->player->id}/rating", ['rating' => 1450, 'intl_id' => 'ARM-7'])->assertRedirect();
    $this->actingAs($this->owner)->get("/owner/players/{$this->player->id}")->assertInertia(fn (Assert $p) => $p->component('owner/Player')
        ->where('rating.rating', 1450)->where('card.balance', 8000));
});

it('lists everybody who played here, with filters', function () {
    $small = $this->club->fields->firstWhere('size.value', 'small');
    $other = User::factory()->create(['name' => 'Vardan']);
    Booking::create(['code' => 'BB-801', 'venue_id' => $this->club->id, 'field_id' => $small->id, 'user_id' => $other->id,
        'starts_at' => now()->subDays(2), 'ends_at' => now()->subDays(2)->addHour(), 'status' => 'completed', 'source' => 'player', 'contact_name' => 'Vardan', 'price' => 1]);
    App\Models\SportRating::create(['user_id' => $other->id, 'sport' => 'football', 'rating' => 1600]);
    $this->actingAs($this->owner)->get('/owner/players')->assertInertia(fn (Assert $p) => $p->where('total', 2)->has('players', 2));
    $this->actingAs($this->owner)->get('/owner/players?min=1500')->assertInertia(fn (Assert $p) => $p->has('players', 1)->where('players.0.name', 'Vardan')->where('players.0.visits', 1));
    $this->actingAs($this->owner)->get('/owner/players?card=yes')->assertInertia(fn (Assert $p) => $p->has('players', 1)->where('players.0.id', $this->player->id));
    // somebody who never played here is not a player of the place
    $this->actingAs($this->owner)->get('/owner/players/'.User::factory()->create()->id)->assertNotFound();
});

it('takes the bill at the desk: items, scan, card + cash', function () {
    $small = $this->club->fields->firstWhere('size.value', 'small');
    $b = Booking::create(['code' => 'BB-777', 'venue_id' => $this->club->id, 'field_id' => $small->id, 'user_id' => $this->player->id,
        'starts_at' => at(10)->utc(), 'ends_at' => at(11)->utc(), 'status' => 'accepted', 'source' => 'player', 'contact_name' => 'Gor', 'price' => 15000]);
    $p = Product::create(['venue_id' => $this->club->id, 'name' => 'Cola', 'price' => 500]);
    app(Cards::class)->topUp($this->card, 14000, $this->owner);
    $this->actingAs($this->owner)->post("/owner/bookings/{$b->id}/items", ['product_id' => $p->id])->assertRedirect();
    $this->actingAs($this->owner)->get("/owner/bookings/{$b->id}/checkout")->assertInertia(fn (Assert $pg) => $pg->component('owner/Checkout')
        ->where('bill.total', 15500)->where('bill.card.scanned', false)->has('products', 1));
    $this->actingAs($this->owner)->post("/owner/bookings/{$b->id}/pay", ['card' => 14000, 'cash' => 1500])->assertSessionHasErrors('booking');
    $this->actingAs($this->owner)->post("/owner/bookings/{$b->id}/scan", ['code' => url('/c/'.$this->card->code)])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post("/owner/bookings/{$b->id}/pay", ['card' => 14000, 'cash' => 1500])->assertRedirect()->assertSessionHasNoErrors();
    expect($b->fresh()->paid_at)->not->toBeNull()->and($this->card->fresh()->balance)->toBe(0);
    $this->actingAs($this->owner)->getJson("/owner/bookings/{$b->id}")->assertJsonPath('paid', true)->assertJsonPath('items.0.name', 'Cola');
});

it('shows the player their card with its QR, and the club page their balance', function () {
    Product::create(['venue_id' => $this->club->id, 'name' => 'Balls', 'price' => 1500]);
    $this->actingAs($this->player)->get('/me/cards')->assertInertia(fn (Assert $pg) => $pg->component('player/Cards')
        ->has('cards', 1)->where('cards.0.venue.slug', $this->club->slug)->where('cards.0.qr', fn ($svg) => str_starts_with($svg, '<svg')));
    $this->actingAs($this->player)->get("/places/{$this->club->slug}")->assertInertia(fn (Assert $pg) => $pg
        ->where('club.card.balance', 0)->has('club.products', 1));
});

it('registers for a tournament by its link, without an account', function () {
    $t = Tournament::create(['venue_id' => $this->club->id, 'sport' => 'pingpong', 'title' => 'Open', 'code' => 'abcd1234', 'starts_at' => now()->addWeek(),
        'options' => [['label' => 'Free', 'price' => 0], ['label' => 'Pro', 'price' => 5000]]]);
    $this->get('/t/abcd1234')->assertOk()->assertInertia(fn (Assert $p) => $p->component('player/Tournament')->where('tournament.open', true)->has('tournament.options', 2));
    $this->post('/t/abcd1234', ['first_name' => 'Arman', 'last_name' => 'Sargsyan', 'phone' => '+37491234567', 'level' => 1200, 'option' => 1])->assertRedirect();
    $this->post('/t/abcd1234', ['first_name' => 'Arman', 'last_name' => 'Sargsyan', 'phone' => '+37491234567', 'option' => 1])->assertSessionHasErrors('register');
    $this->actingAs($this->owner)->get('/owner/tournaments')->assertInertia(fn (Assert $p) => $p->has('current.list', 1)->where('current.list.0.price', 5000));
});
