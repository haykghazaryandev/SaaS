<?php

use App\Domain\Booking\Actions\OwnerBooking;
use App\Domain\Booking\Events\ScheduleChanged;
use App\Domain\Club\Cards;
use App\Models\Booking;
use App\Models\Field;
use App\Models\Product;
use App\Models\SportRating;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use App\Models\Venue;
use App\Support\LocalTime;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Event;
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
    // staff: the cards too (they make, scan and top them up at the desk); not the money
    $staff = User::factory()->create();
    $this->club->staff()->attach($staff->id, ['role' => 'staff']);
    $this->actingAs($staff)->withSession(['venue_id' => $this->club->id])->get('/owner/cards')->assertOk();
    $this->actingAs($staff)->get('/owner/money')->assertRedirect(route('owner.start'));
    $this->club->update(['features' => ['booking']]);
    foreach (['/owner/players', '/owner/cards', '/owner/shop', '/owner/tournaments'] as $url) {
        $this->actingAs($this->owner)->get($url)->assertNotFound();
    }
    // no money feature: Money is ZoneSpot's fee page
    $this->actingAs($this->owner)->get('/owner/money')->assertRedirect(route('owner.fee'));
    $this->actingAs($this->owner)->get('/owner/fee')->assertOk()->assertInertia(fn ($p) => $p->has('pct'));
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

it('tops a card up only after a scan; the rating needs none', function () {
    $base = '/owner/cards/'.$this->card->code;
    $this->actingAs($this->owner)->get($base)->assertInertia(fn (Assert $p) => $p->component('owner/Card')->where('card.scanned', false));
    $this->actingAs($this->owner)->post("$base/topup", ['amount' => 10000])->assertSessionHasErrors('booking');
    expect($this->card->fresh()->balance)->toBe(0);
    // the scan (the QR link) opens it for a top-up
    $this->actingAs($this->owner)->get('/c/'.$this->card->code)->assertRedirect(route('owner.cards.show', $this->card->code));
    $this->actingAs($this->owner)->post("$base/topup", ['amount' => 10000])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->card->fresh()->balance)->toBe(10000);
    // no discount per card any more: cashback comes by itself (levels in Settings)
    $this->actingAs($this->owner)->post("$base/discount", ['discount' => 10])->assertNotFound();
    $this->actingAs($this->owner)->get($base)->assertInertia(fn (Assert $p) => $p->where('level.pct', 0)->where('level.next.from', 50000));
    // no free "take from card": money comes off a card only on a game's check
    $this->actingAs($this->owner)->post("$base/charge", ['amount' => 2000])->assertNotFound();
    // ratings are a ping-pong thing: football has none; with a table the place has them
    $this->actingAs($this->owner)->post("/owner/players/{$this->player->id}/rating", ['rating' => 1450])->assertNotFound();
    Field::create(['venue_id' => $this->club->id, 'size' => 'table', 'format' => '🏓', 'max_players' => 4, 'durations' => [60], 'default_duration' => 60, 'sort' => 9]);
    $this->club->load('fields');
    $this->actingAs($this->owner)->post("/owner/players/{$this->player->id}/rating", ['rating' => 1450, 'intl_id' => 'ARM-7'])->assertRedirect();
    $this->actingAs($this->owner)->get("/owner/players/{$this->player->id}")->assertInertia(fn (Assert $p) => $p->component('owner/Player')
        ->where('rating.rating', 1450)->where('card.balance', 10000));
});

it('lists everybody who played here, with filters', function () {
    $small = $this->club->fields->firstWhere('size.value', 'small');
    $other = User::factory()->create(['name' => 'Vardan']);
    Booking::create(['code' => 'BB-801', 'venue_id' => $this->club->id, 'field_id' => $small->id, 'user_id' => $other->id,
        'starts_at' => now()->subDays(2), 'ends_at' => now()->subDays(2)->addHour(), 'status' => 'completed', 'source' => 'player', 'contact_name' => 'Vardan', 'price' => 1]);
    SportRating::create(['user_id' => $other->id, 'sport' => 'football', 'rating' => 1600]);
    $this->actingAs($this->owner)->get('/owner/players')->assertInertia(fn (Assert $p) => $p->where('total', 2)->has('players', 2));
    $this->actingAs($this->owner)->get('/owner/players?min=1500')->assertInertia(fn (Assert $p) => $p->has('players', 1)->where('players.0.name', 'Vardan')->where('players.0.visits', 1));
    $this->actingAs($this->owner)->get('/owner/players?card=yes')->assertInertia(fn (Assert $p) => $p->has('players', 1)->where('players.0.id', $this->player->id));
    // somebody who never played here is not a player of the place
    $this->actingAs($this->owner)->get('/owner/players/'.User::factory()->create()->id)->assertNotFound();
});

it('takes the check at the desk: items, card + cash', function () {
    $small = $this->club->fields->firstWhere('size.value', 'small');
    $b = Booking::create(['code' => 'BB-777', 'venue_id' => $this->club->id, 'field_id' => $small->id, 'user_id' => $this->player->id,
        'starts_at' => at(10)->utc(), 'ends_at' => at(11)->utc(), 'status' => 'accepted', 'source' => 'player', 'contact_name' => 'Gor', 'price' => 15000,
        'started_at' => now()]);   // started at the desk
    $p = Product::create(['venue_id' => $this->club->id, 'name' => 'Cola', 'price' => 500]);
    app(Cards::class)->topUp($this->card, 14000, $this->owner);
    $this->actingAs($this->owner)->post("/owner/bookings/{$b->id}/items", ['product_id' => $p->id])->assertRedirect();
    $this->actingAs($this->owner)->get("/owner/bookings/{$b->id}/checkout")->assertInertia(fn (Assert $pg) => $pg->component('owner/Checkout')
        ->where('bill.total', 15500)->where('bill.card.balance', 14000)->has('products', 1));
    // the player's card is known from the booking: 14 000 from it + 1 500 cash, no scan
    $this->actingAs($this->owner)->post("/owner/bookings/{$b->id}/pay", ['card' => 14000, 'cash' => 1000])->assertSessionHasErrors('booking');
    $this->actingAs($this->owner)->post("/owner/bookings/{$b->id}/pay", ['card' => 14000, 'cash' => 1500])->assertRedirect()->assertSessionHasNoErrors();
    expect($b->fresh()->paid_at)->not->toBeNull()->and($this->card->fresh()->balance)->toBe(0);
    $this->actingAs($this->owner)->getJson("/owner/bookings/{$b->id}")->assertJsonPath('paid', true)->assertJsonPath('items.0.name', 'Cola');
});

it('shows every table now: a game with its live check, a free one its next booking', function () {
    $this->club->update(['rules' => ['min_notice_min' => 0]]);
    $small = $this->club->fields->firstWhere('size.value', 'small');
    $start = LocalTime::now()->startOfDay()->addHours(12);
    $this->travelTo($start->addMinutes(20));
    $b = Booking::create(['code' => 'BB-555', 'venue_id' => $this->club->id, 'field_id' => $small->id, 'user_id' => $this->player->id,
        'starts_at' => $start->utc(), 'ends_at' => $start->addHour()->utc(), 'status' => 'accepted', 'source' => 'player', 'contact_name' => 'Gor', 'price' => 15000]);
    // booked, the team not started yet: no bill, Start game
    $this->actingAs($this->owner)->get('/owner/now')->assertInertia(fn (Assert $p) => $p->where('courts.0.state', 'booked')->where('courts.0.due.id', $b->id)->where('courts.0.game', null));
    $this->actingAs($this->owner)->post(route('owner.bookings.start', $b->id))->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->get('/owner/now')->assertInertia(fn (Assert $p) => $p->component('owner/Now')
        ->where('courts.0.state', 'playing')->where('courts.0.game.id', $b->id)->where('courts.0.game.bill.card.balance', 0)
        ->where('courts.1.state', 'free'));
    $this->actingAs($this->owner)->post("/owner/bookings/{$b->id}/stop")->assertRedirect(route('owner.bookings.checkout', $b->id));
    $this->actingAs($this->owner)->get('/owner/now')->assertInertia(fn (Assert $p) => $p->where('courts.0.state', 'to_pay')->where('courts.1.state', 'free'));
});

it('puts demo games on the club tables at any hour (not in production)', function () {
    $club = makeClub(3, ['slug' => 'demo-club', 'features' => ['booking', 'wallet', 'shop']]);
    $club->staff()->attach($this->owner->id, ['role' => 'owner']);
    $this->travelTo(LocalTime::now()->startOfDay()->addMinutes(20));   // 00:20, the club is closed
    // a booking (no note) where the next team goes: it makes way (it used to break the overlap rule: a 500)
    $t1 = $club->fields()->orderBy('sort')->orderBy('id')->first();
    $at = LocalTime::now()->second(0)->addMinutes(30);
    Booking::create(['code' => Booking::newCode(), 'venue_id' => $club->id, 'field_id' => $t1->id, 'starts_at' => $at->utc(), 'ends_at' => $at->addHour()->utc(),
        'status' => 'accepted', 'source' => 'owner', 'contact_name' => 'Someone', 'price' => 1000]);
    $this->artisan('club:demo-now', ['venue' => 'demo-club'])->assertSuccessful();
    $this->actingAs($this->owner)->post('/owner/venue', ['venue_id' => $club->id]);
    $this->actingAs($this->owner)->get('/owner/now')->assertInertia(fn (Assert $p) => $p
        ->where('courts.0.state', 'playing')->where('courts.1.state', 'playing')->where('courts.2.state', 'to_pay')
        // the countdown's end; Add time: only +5 fits before the next team (and what it adds to the bill)
        ->where('courts.0.game.endsAt', fn ($ts) => $ts > now()->getTimestamp())
        ->where('courts.0.game.canExtend', 5)->where('courts.0.game.limit.by', 'next')->has('courts.0.game.addPrices.5'));

    // real lengths: table 1's game ends in 15 min, its next team 5 min after
    $game = Booking::where('field_id', $t1->id)->where('note_owner', 'demo-now')->whereNotNull('started_at')->first();
    expect($game->starts_at->diffInMinutes($game->ends_at))->toEqual(60)
        ->and((int) round(now()->diffInMinutes($game->ends_at)))->toBe(15);
});

it('shows the player their card with its QR, and the club page their balance', function () {
    Product::create(['venue_id' => $this->club->id, 'name' => 'Balls', 'price' => 1500]);
    $this->actingAs($this->player)->get('/me/cards')->assertInertia(fn (Assert $pg) => $pg->component('player/Cards')
        ->has('cards', 1)->where('cards.0.venue.slug', $this->club->slug)->where('cards.0.qr', fn ($svg) => str_starts_with($svg, '<svg')));
    $this->actingAs($this->player)->get("/places/{$this->club->slug}")->assertInertia(fn (Assert $pg) => $pg
        ->where('club.card.balance', 0)->has('club.products', 1));
});

it('registers for a tournament by its link: a guest logs in first, then registers with the account', function () {
    $t = Tournament::create(['venue_id' => $this->club->id, 'sport' => 'pingpong', 'title' => 'Open', 'code' => 'abcd1234', 'starts_at' => now()->addWeek(),
        'options' => [['label' => 'Free', 'price' => 0], ['label' => 'Pro', 'price' => 5000]]]);
    $this->get('/t/abcd1234')->assertOk()->assertInertia(fn (Assert $p) => $p->component('player/Tournament')->where('tournament.open', true)->has('tournament.options', 2));
    // a guest: the page says "log in to register"; posting sends them to the login
    $this->get('/t/abcd1234')->assertInertia(fn (Assert $p) => $p->where('me', null));
    $this->post('/t/abcd1234', ['first_name' => 'Arman', 'last_name' => 'Sargsyan', 'phone' => '+37491234567', 'option' => 1])->assertRedirect(route('login'));
    expect(TournamentEntry::count())->toBe(0);
    // logged in: the account's phone, and the entry is theirs
    $arman = User::factory()->create(['name' => 'Arman Sargsyan', 'phone' => '+37491234567']);
    $this->actingAs($arman)->get('/t/abcd1234')->assertInertia(fn (Assert $p) => $p->where('me.phone', '+374 91 234 567'));
    $this->actingAs($arman)->post('/t/abcd1234', ['first_name' => 'Arman', 'last_name' => 'Sargsyan', 'level' => 1200, 'option' => 1])->assertRedirect()->assertSessionHasNoErrors();
    expect(TournamentEntry::first()->only('user_id', 'phone'))->toBe(['user_id' => $arman->id, 'phone' => '+37491234567']);
    $this->actingAs($arman)->post('/t/abcd1234', ['first_name' => 'Arman', 'last_name' => 'Sargsyan', 'option' => 1])->assertSessionHasErrors('register');
    $this->actingAs($arman)->get('/t/abcd1234')->assertInertia(fn (Assert $p) => $p->where('entry.status', 'applied'));
    $this->actingAs($this->owner)->get("/owner/tournaments/{$t->id}")->assertInertia(fn (Assert $p) => $p->has('tournament.list', 1)->where('tournament.list.0.price', 5000));
});

it('lets the owner add test games from the Now page outside production', function () {
    $this->actingAs($this->owner)->post('/owner/now/demo')->assertRedirect();
    $this->app['env'] = 'production';
    $this->withoutMiddleware(ValidateCsrfToken::class)
        ->actingAs($this->owner)->post('/owner/now/demo')->assertNotFound();
});

it('lets the owner set the cashback levels in Settings: sorted, one per amount, within limits', function () {
    $rules = ['min_notice_min' => 30, 'request_expire_min' => 60, 'auto_accept_regulars' => false];
    $this->actingAs($this->owner)->put('/owner/settings', ['rules' => $rules + ['cashback_tiers' => [['from' => 200000, 'pct' => 6], ['from' => 80000, 'pct' => 2], ['from' => 80000, 'pct' => 4]]]])
        ->assertSessionHasNoErrors();
    expect($this->club->fresh()->rule('cashback_tiers'))->toEqual([['from' => 80000, 'pct' => 2], ['from' => 200000, 'pct' => 6]]);
    $this->actingAs($this->owner)->get('/owner/settings')->assertInertia(fn (Assert $p) => $p->where('rules.cashback_tiers.1.pct', 6));
    $this->actingAs($this->owner)->put('/owner/settings', ['rules' => $rules + ['cashback_tiers' => [['from' => 1000, 'pct' => 90]]]])->assertSessionHasErrors('rules.cashback_tiers.0.pct');
});

it('opens every club page for an owner of two places (the place is not lazy loaded from a list)', function () {
    $football = makeVenue();
    $football->staff()->attach($this->owner->id, ['role' => 'owner']);
    $this->actingAs($this->owner)->withSession(['venue_id' => $this->club->id]);
    foreach (['/owner/now', '/owner/players', '/owner/players/'.$this->player->id, '/owner/cards', '/owner/cards/'.$this->card->code, '/owner/shop', '/owner/tournaments', '/owner/money', '/owner/settings', '/owner/schedule'] as $url) {
        $this->get($url)->assertOk();
    }
});

it('attaches a card to a phone booking from the scanned card (a phone) or by name at the desk; the desk updates live', function () {
    Event::fake([ScheduleChanged::class]);
    $small = $this->club->fields->firstWhere('size.value', 'small');
    $start = at(12);
    $other = makeVenue();
    $b = app(OwnerBooking::class)->create($this->owner, $small, $start, 60, ['name' => 'Walk-in']);
    $b2 = app(OwnerBooking::class)->create($this->owner, $this->club->fields->firstWhere('size.value', 'big'), $start, 90, ['name' => 'Walk-in 2']);
    $ob = app(OwnerBooking::class)->create($this->owner, $other->fields->first(), $start, 60, ['name' => 'X']);
    $this->travelTo($start->addMinutes(30));   // they play (started at the desk)
    foreach ([$b, $b2, $ob] as $x) {
        $x->update(['started_at' => $start->utc()]);
    }
    // the card scanned at the bill: attached, the bill opens
    $this->actingAs($this->owner)->post("/owner/bookings/{$b->id}/scan", ['code' => $this->card->code, 'open' => 1])
        ->assertRedirect(route('owner.bookings.checkout', $b->id));
    expect($b->fresh()->user_id)->toBe($this->player->id);
    Event::assertDispatched(ScheduleChanged::class);

    // no camera: find the card by name or phone on another phone booking's bill
    $this->actingAs($this->owner)->getJson("/owner/bookings/{$b2->id}/cards?q=gor")->assertOk()->assertJsonPath('0.code', $this->card->code);
    $this->actingAs($this->owner)->getJson("/owner/bookings/{$b2->id}/cards?q=nobody")->assertExactJson([]);
    // another place's booking: not here
    $this->actingAs($this->owner)->getJson("/owner/bookings/{$ob->id}/cards?q=gor")->assertForbidden();
});
