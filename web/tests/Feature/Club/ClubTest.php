<?php

use App\Domain\Booking\Actions\DecideBooking;
use App\Domain\Booking\Actions\OwnerBooking;
use App\Domain\Booking\Actions\RequestBooking;
use App\Domain\Booking\BookingException;
use App\Domain\Club\Cards;
use App\Domain\Club\Checkout;
use App\Domain\Club\Events\CardUpdated;
use App\Domain\Club\Events\ClubChanged;
use App\Domain\Club\Money;
use App\Domain\Club\Ratings;
use App\Domain\Club\Report;
use App\Domain\Club\Shop;
use App\Domain\Club\Tournaments;
use App\Domain\Venues\Availability;
use App\Enums\Sport;
use App\Http\Controllers\Owner\BookingPresenter;
use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\Field;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RatingChange;
use App\Models\SportRating;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\Notice;
use App\Support\LocalTime;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->club = makeVenue(['cashback_tiers' => [['from' => 0, 'pct' => 5]]]);   // 5% from the first payment
    $this->club->update(['features' => [...Venue::DEFAULT_FEATURES, 'desk', 'wallet', 'rating', 'trainings', 'shop', 'tournaments', 'money']]);
    // a table too: tournaments and ratings are ping-pong things (football has neither yet)
    Field::create(['venue_id' => $this->club->id, 'size' => 'table', 'format' => '🏓', 'max_players' => 4, 'durations' => [60], 'default_duration' => 60, 'sort' => 9]);
    $this->club->load('fields');
    $this->other = makeVenue();
    $this->other->update(['features' => ['booking', 'wallet']]);
    $this->owner = User::factory()->create();
    $this->player = User::factory()->create(['name' => 'Gor']);
    $this->cards = app(Cards::class);
    $this->money = app(Money::class);
});

it('gives a player one card per place, whose code works only at that place', function () {
    $card = $this->cards->forPlayer($this->club, $this->player);
    expect($this->cards->forPlayer($this->club, $this->player)->id)->toBe($card->id)
        ->and(strlen($card->code))->toBe(20)
        ->and($this->cards->byCode($this->club, $card->code)?->id)->toBe($card->id)
        ->and($this->cards->byCode($this->other, $card->code))->toBeNull();
    $there = $this->cards->forPlayer($this->other, $this->player);
    expect($there->id)->not->toBe($card->id)->and($there->code)->not->toBe($card->code);
    // a place without the feature has no cards
    expect(fn () => $this->cards->forPlayer(makeVenue(), $this->player))->toThrow(BookingException::class);
});

it('tops up, pays from the card and adds the cashback onto it; a card holder paying cash earns it too', function () {
    $card = $this->cards->forPlayer($this->club, $this->player);
    $this->cards->topUp($card, 10000, $this->owner);
    $p = $this->money->charge($this->club, $this->owner, 'shop', 4000, 'card', $this->player);
    // 4 000 from the card, 5% of it (200) back
    expect($p->amount)->toBe(4000)
        ->and($card->fresh()->balance)->toBe(10000 - 4000 + 200)
        ->and($card->entries()->pluck('kind')->all())->toBe(['cashback', 'pay', 'topup']);
    expect(fn () => $this->money->charge($this->club, $this->owner, 'shop', 100000, 'card', $this->player))->toThrow(BookingException::class, __('booking.error.low_balance'));
    // cash: the full price; only the cashback (5% of 2 000) goes onto the card
    $cash = $this->money->charge($this->club, $this->owner, 'training', 2000, 'cash', $this->player);
    expect($cash->amount)->toBe(2000)->and($card->fresh()->balance)->toBe(6200 + 100);
    // no card: card payment refused
    expect(fn () => $this->money->charge($this->club, $this->owner, 'shop', 100, 'card', User::factory()->create()))->toThrow(BookingException::class, __('booking.error.no_card'));
});

it('moves a card up the cashback levels by itself: 0% until 50 000, then 3%, 5% from 150 000, 7% from 300 000', function () {
    Notification::fake();
    $club = makeVenue();   // the default levels
    $club->update(['features' => ['booking', 'wallet']]);
    $card = $this->cards->forPlayer($club, $this->player);
    $this->cards->topUp($card, 1_000_000, $this->owner);
    expect(Cards::levelOf($card))->toBe(['pct' => 0, 'spent' => 0, 'next' => ['from' => 50000, 'pct' => 3, 'left' => 50000]]);

    // 40 000 from the card + 20 000 cash: no cashback yet (the level counts what was paid before); now 60 000 paid → 3%
    $this->money->charge($club, $this->owner, 'booking', 40000, 'card', $this->player);
    $this->money->charge($club, $this->owner, 'booking', 20000, 'cash', $this->player);
    expect($card->entries()->where('kind', 'cashback')->count())->toBe(0)
        ->and(Cards::levelOf($card->fresh()))->toMatchArray(['pct' => 3, 'spent' => 60000, 'next' => ['from' => 150000, 'pct' => 5, 'left' => 90000]]);
    Notification::assertSentTo($this->player, Notice::class, fn ($n) => $n->type === 'card_level');

    $this->money->charge($club, $this->owner, 'booking', 10000, 'card', $this->player);   // 3% → 300
    expect((int) $card->entries()->where('kind', 'cashback')->sum('amount'))->toBe(300);
    // top-ups don't count towards the level; cash without the player (no card) neither
    $this->money->charge($club, $this->owner, 'booking', 500000, 'cash');
    expect(Cards::spent($card))->toBe(70000);
    $this->money->charge($club, $this->owner, 'booking', 240000, 'card', $this->player);   // 310 000 paid
    expect(Cards::levelOf($card)['pct'])->toBe(7)->and(Cards::levelOf($card)['next'])->toBeNull();
    $before = $card->fresh()->balance;
    $this->money->charge($club, $this->owner, 'shop', 1000, 'card', $this->player);
    expect($card->fresh()->balance)->toBe($before - 1000 + 70);

    // the owner sets other levels: they apply at once
    $club->update(['rules' => ['cashback_tiers' => [['from' => 100000, 'pct' => 2], ['from' => 500000, 'pct' => 10]]]]);
    expect(Cards::levelOf($card->fresh()->setRelation('venue', $club->fresh())))->toMatchArray(['pct' => 2, 'next' => ['from' => 500000, 'pct' => 10, 'left' => 189000]]);
});

it('keeps the booking price as the price list: no card discounts', function () {
    $this->cards->forPlayer($this->club, $this->player);
    $small = $this->club->fields->firstWhere('size.value', 'small');
    $b = app(RequestBooking::class)->handle($this->player, $small, at(10), 60);   // 15 000 by day
    expect($b->price)->toBe(15000);
});

it('pays a check of time and items: card first (known from the booking, no scan), the rest cash, once', function () {
    $card = $this->cards->forPlayer($this->club, $this->player);
    $this->cards->topUp($card, 13000, $this->owner);
    $small = $this->club->fields->firstWhere('size.value', 'small');
    $b = app(RequestBooking::class)->handle($this->player, $small, at(10), 60);   // 15 000
    $cola = app(Shop::class)->save(new Product(['venue_id' => $this->club->id]), ['name' => 'Cola', 'price' => 500]);
    $checkout = app(Checkout::class);
    $checkout->addItem($b, $cola, 2, $this->owner);
    // no bill before the game is started at the desk
    expect($checkout->bill($b)['started'])->toBeFalse()
        ->and(fn () => $checkout->pay($b, 13000, 3000, $this->owner))->toThrow(BookingException::class);
    $b->update(['started_at' => now()]);   // started at the desk
    $bill = $checkout->bill($b->fresh());
    expect($bill['total'])->toBe(16000)->and($bill['card']['balance'])->toBe(13000);
    expect(fn () => $checkout->pay($b, 13000, 1000, $this->owner))->toThrow(BookingException::class);   // not the total
    $checkout->pay($b, 13000, 3000, $this->owner);
    // the card paid the time (13 000 of 15 000), cash the rest of the time and the items; 5% cashback on all of it
    $payments = Payment::where('ref_id', $b->id)->get();
    expect($payments->where('kind', 'booking')->where('method', 'card')->sum('amount'))->toBe(13000)
        ->and($payments->where('kind', 'booking')->where('method', 'cash')->sum('amount'))->toBe(2000)
        ->and($payments->where('kind', 'shop')->sum('amount'))->toBe(1000)
        ->and($card->fresh()->balance)->toBe(650 + 100 + 50)
        ->and($b->fresh()->paid_at)->not->toBeNull()
        ->and(fn () => $checkout->pay($b->fresh(), 0, 16000, $this->owner))->toThrow(BookingException::class, __('booking.error.already_paid'));
    // paid before the end: they still play, a cola more is a new amount to take; once ended and paid, the bill is closed
    $checkout->addItem($b->fresh(), $cola, 1, $this->owner);
    expect($b->fresh()->paid_at)->toBeNull()->and($checkout->bill($b->fresh())['due'])->toBe(500);
    $checkout->pay($b->fresh(), 0, 500, $this->owner);
    $checkout->stop($b->fresh(), $this->owner);
    expect($b->fresh()->paid_at)->not->toBeNull()
        ->and(fn () => $checkout->addItem($b->fresh(), $cola, 1, $this->owner))->toThrow(BookingException::class);
});

it('gives a phone-only booking its player when their card is scanned at the bill', function () {
    $small = $this->club->fields->firstWhere('size.value', 'small');
    $b = app(OwnerBooking::class)->create($this->owner, $small, at(12), 60, ['name' => 'Walk-in']);
    $card = $this->cards->forPlayer($this->club, $this->player);
    app(Checkout::class)->attachCard($b, $card);
    expect($b->fresh()->user_id)->toBe($this->player->id);
    $other = $this->cards->forPlayer($this->club, User::factory()->create());
    expect(fn () => app(Checkout::class)->attachCard($b->fresh(), $other))->toThrow(BookingException::class, __('booking.error.other_card'));
});

it('stops a game: the booked length, the time added; past the end the bill does not grow', function () {
    $club = makeClub(2, ['rules' => ['min_notice_min' => 0, 'step_min' => 15], 'features' => ['booking', 'desk', 'wallet', 'shop']]);
    $t1 = $club->fields->first();
    $checkout = app(Checkout::class);
    $this->cards->forPlayer($club, $this->player);
    $now = LocalTime::now();
    $start = LocalTime::at($now->startOfDay(), 12);
    $this->travelTo($start->subMinutes(5));
    $b = app(RequestBooking::class)->handle($this->player, $t1, $start, 60);   // 3 000
    $b->update(['status' => 'accepted']);
    // not started: nothing to stop; they come and the desk starts the game
    expect(fn () => $checkout->stop($b->fresh(), $this->owner))->toThrow(BookingException::class);
    $this->travelTo($start);
    $checkout->start($b->fresh(), $this->owner);
    // they leave after 40 min: still the booked hour; adding 15 min would add 750
    $this->travelTo($start->addMinutes(40));
    expect($checkout->bill($b->fresh())['minutes'])->toBe(60)->and($checkout->addPrice($b->fresh(), 15))->toBe(750);
    // the time is up and they still play (1 h 20): the bill does not grow — the desk takes the money or adds time
    $this->travelTo($start->addMinutes(80));
    expect($checkout->bill($b->fresh())['minutes'])->toBe(60);
    $checkout->extend($b->fresh(), 30, $this->owner);
    $checkout->stop($b->fresh(), $this->owner);
    $bill = $checkout->bill($b->fresh());
    expect($bill['minutes'])->toBe(90)->and($bill['time'])->toBe(4500)
        ->and(fn () => $checkout->stop($b->fresh(), $this->owner))->toThrow(BookingException::class);
});

it('books a training at the training price', function () {
    $this->club->update(['rules' => [...$this->club->rules, 'training_prices' => ['small' => 9000]]]);
    $small = $this->club->fresh()->fields->firstWhere('size.value', 'small');
    $b = app(RequestBooking::class)->handle($this->player, $small, at(10), 90, ['purpose' => 'training']);
    expect($b->purpose)->toBe('training')->and($b->price)->toBe(13500);
    // no training price for the big field: no trainings there
    $big = $this->club->fresh()->fields->firstWhere('size.value', 'big');
    expect(fn () => app(RequestBooking::class)->handle(User::factory()->create(), $big, at(10), 90, ['purpose' => 'training']))->toThrow(BookingException::class);
});

it('keeps a rating per sport with its history', function () {
    // ratings are a ping-pong thing: the club gets a table
    Field::create(['venue_id' => $this->club->id, 'size' => 'table', 'format' => '🏓', 'max_players' => 4, 'durations' => [60], 'default_duration' => 60, 'sort' => 9]);
    $this->club->load('fields');
    $r = app(Ratings::class);
    $r->set($this->player, Sport::Pingpong, 1450, 'ARM-123', $this->club, $this->owner, 'after the league');
    $r->set($this->player, Sport::Pingpong, 1500, 'ARM-123', $this->club, $this->owner);
    expect(Ratings::of($this->player))->toBe(['pingpong' => ['rating' => 1500, 'intl_id' => 'ARM-123', 'club' => ['name' => $this->club->name, 'slug' => $this->club->slug]]])
        ->and(RatingChange::where('user_id', $this->player->id)->pluck('new')->all())->toBe([1450, 1500]);
    expect(fn () => $r->set($this->player, Sport::Pingpong, 1, null, $this->other, $this->owner))->toThrow(BookingException::class);
});

it('sells shop products with their discount', function () {
    $p = app(Shop::class)->save(new Product(['venue_id' => $this->club->id]), ['name' => 'Balls ×6', 'price' => 2000, 'discount_pct' => 25]);
    $sale = app(Shop::class)->sell($this->club, $p, 2, 'cash', $this->owner);
    expect($sale->price_each)->toBe(1500)->and($sale->payment->amount)->toBe(3000);
    $p->update(['in_stock' => false]);
    expect(fn () => app(Shop::class)->sell($this->club, $p->fresh(), 1, 'cash', $this->owner))->toThrow(BookingException::class);
});

it('runs a tournament: register by link, accept, pay the option, results', function () {
    $tt = app(Tournaments::class);
    $t = $tt->save(new Tournament(['venue_id' => $this->club->id]), ['title' => 'November Open', 'sport' => 'pingpong', 'starts_at' => now()->addWeek(),
        'options' => [['label' => 'Free', 'price' => 0], ['label' => 'Standard', 'price' => 5000], ['label' => 'Pro', 'price' => 10000]]]);
    expect($t->code)->toHaveLength(8)->and($t->link())->toEndWith('/t/'.$t->code);
    $e = $tt->register($t, ['first_name' => 'Arman', 'last_name' => 'Sargsyan', 'phone' => '091 23 45 67', 'level' => 3, 'option' => 1]);
    expect($e->phone)->toBe('+37491234567')
        ->and(fn () => $tt->register($t, ['first_name' => 'A', 'last_name' => 'B', 'phone' => '+37491234567', 'option' => 0]))->toThrow(BookingException::class);
    $tt->decide($e, 'accepted');
    // the fee only once registration is closed (a tournament cancelled for too few players has nothing to give back)
    expect(fn () => $tt->pay($e, 'cash', $this->owner))->toThrow(BookingException::class);
    $t->update(['status' => 'closed']);
    expect($tt->pay($e->fresh(), 'cash', $this->owner)->amount)->toBe(5000);
    $tt->place($e->fresh(), 1);
    expect($e->fresh()->place)->toBe(1);
    $t->update(['status' => 'closed']);
    expect(fn () => $tt->register($t->fresh(), ['first_name' => 'A', 'last_name' => 'B', 'phone' => '+37491234568', 'option' => 0]))->toThrow(BookingException::class);
});

it('sums the money: sales by kind, cash and top-ups apart', function () {
    $card = $this->cards->forPlayer($this->club, $this->player);
    $this->cards->topUp($card, 10000, $this->owner);
    $this->money->charge($this->club, $this->owner, 'shop', 2000, 'card', $this->player);
    $this->money->charge($this->club, $this->owner, 'training', 3000, 'cash');
    $today = LocalTime::now()->startOfDay();
    $r = app(Report::class)->between($this->club, $today, $today->addDay());
    expect($r['sales'])->toBe(5000)->and($r['byKind']['shop'])->toBe(2000)->and($r['byKind']['training'])->toBe(3000)
        ->and($r['cash'])->toBe(13000)->and($r['fromCards'])->toBe(2000)->and($r['topups'])->toBe(10000)
        ->and(app(Report::class)->query($this->club, $today, $today->addDay())->count())->toBe(3);

    // the Money page: the payments of the period, filtered (kind, card / cash), 20 a page; no ZoneSpot fee there
    $this->club->staff()->attach($this->owner->id, ['role' => 'owner']);
    foreach (range(1, 21) as $i) {
        $this->money->charge($this->club, $this->owner, 'shop', 100, 'cash');
    }
    $this->actingAs($this->owner)->withSession(['venue_id' => $this->club->id])->get(route('owner.money'))
        ->assertInertia(fn (Assert $p) => $p->where('payments.total', 24)->where('payments.pages', 2)->has('payments.data', 20)->missing('fee'));
    $this->actingAs($this->owner)->get(route('owner.money', ['kind' => 'training']))
        ->assertInertia(fn (Assert $p) => $p->where('payments.total', 1)->where('payments.data.0.amount', 3000)->where('filters.kind', 'training'));
    $this->actingAs($this->owner)->get(route('owner.money', ['method' => 'card', 'page' => 1]))
        ->assertInertia(fn (Assert $p) => $p->where('payments.total', 1)->where('payments.data.0.method', 'card'));
    // staff never see the money
    $staff = User::factory()->create();
    $this->club->staff()->attach($staff->id, ['role' => 'staff']);
    $this->actingAs($staff)->withSession(['venue_id' => $this->club->id])->get(route('owner.money'))->assertRedirect(route('owner.start'));
});

it('tells the owner pages and the player at once: items, top-ups, payments, registrations', function () {
    Event::fake([ClubChanged::class, CardUpdated::class]);
    $card = $this->cards->forPlayer($this->club, $this->player);
    Event::assertDispatched(CardUpdated::class, fn ($e) => $e->userId === $this->player->id);
    $this->cards->topUp($card, 1000, $this->owner);
    Event::assertDispatched(ClubChanged::class, fn ($e) => $e->venueId === $this->club->id
        && collect($e->broadcastOn())->map->name->all() === ["private-venue.{$this->club->id}"]);
    Event::assertDispatchedTimes(CardUpdated::class, 2);

    Event::fake([ClubChanged::class]);
    $small = $this->club->fields->firstWhere('size.value', 'small');
    $b = app(RequestBooking::class)->handle($this->player, $small, at(10), 60);
    $cola = app(Shop::class)->save(new Product(['venue_id' => $this->club->id]), ['name' => 'Cola', 'price' => 500]);
    app(Checkout::class)->addItem($b, $cola, 1, $this->owner);
    $t = app(Tournaments::class)->save(new Tournament(['venue_id' => $this->club->id]), ['title' => 'Cup', 'sport' => 'football', 'starts_at' => now()->addWeek(), 'options' => [['label' => 'Free', 'price' => 0]]]);
    app(Tournaments::class)->register($t, ['first_name' => 'Arman', 'last_name' => 'S', 'phone' => '+37491234567', 'option' => 0]);
    // product saved, item added, registration: each tells the place
    Event::assertDispatchedTimes(ClubChanged::class, 3);
});

it('tells the player about their card: made, topped up, paid (one message for a whole check)', function () {
    Notification::fake();
    $card = $this->cards->forPlayer($this->club, $this->player);
    $this->cards->topUp($card, 5000, $this->owner);
    $small = $this->club->fields->firstWhere('size.value', 'small');
    $b = app(RequestBooking::class)->handle($this->player, $small, at(10), 60);   // 15 000
    $cola = app(Shop::class)->save(new Product(['venue_id' => $this->club->id]), ['name' => 'Cola', 'price' => 500]);
    app(Checkout::class)->addItem($b, $cola, 2, $this->owner);
    $b->update(['started_at' => now()]);   // started at the desk
    app(Checkout::class)->pay($b->fresh(), 5000, 11000, $this->owner);

    $sent = fn (string $type) => Notification::sent($this->player, Notice::class)->filter(fn ($n) => $n->type === $type);
    expect($sent('card_created'))->toHaveCount(1)
        ->and($sent('card_created')->first()->link)->toBe('/me/cards?venue='.$this->club->slug)
        ->and($sent('card_topup')->first()->params['amount'])->toBe('5 000 ֏')
        ->and($sent('card_paid'))->toHaveCount(1)
        ->and($sent('card_paid')->first()->params['amount'])->toBe('5 000 ֏');
    // the second time it is the same card: no new "card made"
    $this->cards->forPlayer($this->club, $this->player);
    expect($sent('card_created'))->toHaveCount(1);
});

it('runs the desk: start a booked game in its window, extend it while the table is free, a walk-in holds the table', function () {
    $club = makeClub(2, ['rules' => ['min_notice_min' => 0, 'step_min' => 15], 'features' => ['booking', 'desk', 'wallet', 'shop']]);
    [$t1, $t2] = [$club->fields[0], $club->fields[1]];
    $checkout = app(Checkout::class);
    $start = LocalTime::at(LocalTime::now()->startOfDay(), 12);
    $this->travelTo($start->subHours(2));
    $b = app(OwnerBooking::class)->create($this->owner, $t1, $start, 60, ['name' => 'Team A']);
    $next = app(OwnerBooking::class)->create($this->owner, $t1, $start->addMinutes(90), 60, ['name' => 'Team B']);
    // too early (more than 30 min before): no start
    expect(fn () => $checkout->start($b->fresh(), $this->owner))->toThrow(BookingException::class);
    $this->travelTo($start->subMinutes(10));
    $checkout->start($b->fresh(), $this->owner);
    expect($b->fresh()->started_at)->not->toBeNull()->and($b->fresh()->arrived_at)->not->toBeNull()
        ->and(fn () => $checkout->start($b->fresh(), $this->owner))->toThrow(BookingException::class);
    // the table is free 30 min after it: +15 yes, then +30 more would hit Team B
    $checkout->extend($b->fresh(), 15, $this->owner);
    expect($b->fresh()->ends_at->equalTo($start->addMinutes(75)))->toBeTrue()
        ->and(fn () => $checkout->extend($b->fresh(), 30, $this->owner))->toThrow(BookingException::class);
    // a new game on the free table: started now, a 60 min game (counts down like a booked one, charged its length)
    $w = $checkout->walkIn($t2->setRelation('venue', $club), $this->owner, 60);
    expect((bool) $w->fresh()->open_end)->toBeFalse()->and($w->started_at)->not->toBeNull()
        ->and(app(Availability::class)->problem($t2, $start, 30))->not->toBeNull();
    $this->travelTo($start->subMinutes(10)->addMinutes(20));
    $checkout->stop($w->fresh(), $this->owner);
    // they stop after 20 min: charged the 60 chosen; the table is free again from the stop
    expect($w->fresh()->charged_minutes)->toBe(60)->and($checkout->bill($w->fresh())['minutes'])->toBe(60)
        ->and($w->fresh()->ends_at->lessThanOrEqualTo(now()->addMinute()))->toBeTrue();
    // paid, and a new game on the same table at once (1 hour): not "taken"
    $checkout->pay($w->fresh(), 0, $checkout->bill($w->fresh())['total'], $this->owner);
    $again = $checkout->walkIn($t2->setRelation('venue', $club), $this->owner, 60);
    expect($again->exists)->toBeTrue();
});

it('frees the time of a team that did not come', function () {
    $club = makeClub(1, ['rules' => ['min_notice_min' => 0], 'features' => ['booking', 'wallet']]);
    $start = LocalTime::at(LocalTime::now()->startOfDay(), 12);
    $this->travelTo($start->subHour());
    $b = app(RequestBooking::class)->handle($this->player, $club->fields[0], $start, 60);
    app(DecideBooking::class)->accept($b, $this->owner);
    $this->travelTo($start->addMinutes(20));
    app(OwnerBooking::class)->noShow($this->owner, $b->fresh());
    expect($b->fresh()->status->value)->toBe('no_show')
        ->and(app(Availability::class)->problem($club->fields[0], $start->addMinutes(30), 30, forPlayer: false))->toBeNull();
});

it("shows a player's record at this place on their requests: played, didn't come, cancelled late, came late", function () {
    $club = makeClub(1, ['rules' => ['min_notice_min' => 0], 'features' => ['booking']]);
    $club->staff()->attach($this->owner->id, ['role' => 'owner']);
    $t = $club->fields[0];
    $mk = fn ($days, $status, array $x = []) => Booking::create(['code' => Booking::newCode(), 'venue_id' => $club->id, 'field_id' => $t->id, 'user_id' => $this->player->id,
        'starts_at' => now()->subDays($days), 'ends_at' => now()->subDays($days)->addHour(), 'status' => $status, 'source' => 'player', 'contact_name' => 'Aram', 'price' => 1000] + $x);
    $mk(10, 'completed');
    $mk(9, 'completed', ['started_at' => now()->subDays(9)->addMinutes(20)]);   // started 20 min late
    $mk(8, 'no_show');
    $c = $mk(7, 'cancelled');
    BookingEvent::create(['booking_id' => $c->id, 'type' => 'cancelled', 'changes' => ['short' => true]]);
    $mk(6, 'completed', ['late_minutes' => 10]);   // said "10 min late"
    // another place: does not count here
    $other = makeClub(1);
    Booking::create(['code' => 'BB-x9', 'venue_id' => $other->id, 'field_id' => $other->fields[0]->id, 'user_id' => $this->player->id,
        'starts_at' => now()->subDays(5), 'ends_at' => now()->subDays(5)->addHour(), 'status' => 'no_show', 'source' => 'player', 'contact_name' => 'Aram', 'price' => 1]);
    $req = app(RequestBooking::class)->handle($this->player, $t, LocalTime::at(LocalTime::now()->startOfDay()->addDays(2), 12), 60);
    $this->actingAs($this->owner)->withSession(['venue_id' => $club->id])->getJson(route('owner.requests'))
        ->assertJsonPath('requests.0.id', $req->id)
        ->assertJsonPath('requests.0.history', ['played' => 3, 'no_shows' => 1, 'late_cancels' => 1, 'lates' => 2]);
});

it('marks the preferred one of two requests for the same time: the better record here, then the rating', function () {
    $club = makeClub(1, ['rules' => ['min_notice_min' => 0], 'features' => ['booking', 'rating']]);
    $club->staff()->attach($this->owner->id, ['role' => 'owner']);
    $t = $club->fields[0];
    $good = User::factory()->create();
    $bad = User::factory()->create();
    // the second one didn't come here once
    Booking::create(['code' => Booking::newCode(), 'venue_id' => $club->id, 'field_id' => $t->id, 'user_id' => $bad->id,
        'starts_at' => now()->subDays(3), 'ends_at' => now()->subDays(3)->addHour(), 'status' => 'no_show', 'source' => 'player', 'contact_name' => 'B', 'price' => 1]);
    SportRating::create(['user_id' => $bad->id, 'sport' => 'pingpong', 'rating' => 1600]);
    SportRating::create(['user_id' => $good->id, 'sport' => 'pingpong', 'rating' => 1200]);
    $at = LocalTime::at(LocalTime::now()->startOfDay()->addDays(2), 18);
    $a = app(RequestBooking::class)->handle($bad, $t, $at, 60);
    $b = app(RequestBooking::class)->handle($good, $t, $at, 60);
    $res = $this->actingAs($this->owner)->withSession(['venue_id' => $club->id])->getJson(route('owner.requests'))->json('requests');
    $byId = collect($res)->keyBy('id');
    expect($byId[$b->id]['preferred'])->toBeTrue()->and($byId[$a->id]['preferred'])->toBeFalse()
        ->and($byId[$a->id]['competing'])->toBe([$b->id])->and($byId[$a->id]['rating'])->toBe(1600)
        // the order among them (the best first in the list) and how far to trust each one here
        ->and($byId[$b->id]['rank'])->toBe(1)->and($byId[$a->id]['rank'])->toBe(2)
        ->and($byId[$a->id]['trust'])->toBe('risky')->and($byId[$b->id]['trust'])->toBe('new')
        ->and(collect($res)->pluck('id')->all())->toBe([$b->id, $a->id]);
});

it('tells how far to trust a player here from the record', function () {
    $h = fn (array $x) => BookingPresenter::trust($x + ['played' => 0, 'no_shows' => 0, 'late_cancels' => 0, 'lates' => 0]);
    expect($h([]))->toBe('new')->and($h(['played' => 4]))->toBe('good')
        ->and($h(['played' => 4, 'lates' => 1]))->toBe('mixed')->and($h(['played' => 4, 'late_cancels' => 1]))->toBe('mixed')
        ->and($h(['played' => 9, 'late_cancels' => 2]))->toBe('risky')->and($h(['played' => 9, 'no_shows' => 1]))->toBe('risky');
});
