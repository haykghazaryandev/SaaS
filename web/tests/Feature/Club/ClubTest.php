<?php

use App\Domain\Booking\Actions\RequestBooking;
use App\Domain\Booking\BookingException;
use App\Domain\Club\Cards;
use App\Domain\Club\Money;
use App\Domain\Club\Ratings;
use App\Domain\Club\Report;
use App\Domain\Club\Shop;
use App\Domain\Club\Tournaments;
use App\Domain\Club\Checkout;
use App\Enums\Sport;
use App\Models\Product;
use App\Models\RatingChange;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Venue;
use App\Support\LocalTime;

beforeEach(function () {
    $this->club = makeVenue(['cashback_pct' => 5]);
    $this->club->update(['features' => [...Venue::DEFAULT_FEATURES, 'wallet', 'rating', 'trainings', 'shop', 'tournaments', 'money']]);
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

it('tops up, pays from the card with the discount and adds the cashback', function () {
    $card = $this->cards->forPlayer($this->club, $this->player);
    $this->cards->setDiscount($card, 10);
    $this->cards->topUp($card, 10000, $this->owner);
    $p = $this->money->charge($this->club, $this->owner, 'shop', 4000, 'card', $this->player);
    // 4 000 − 10% = 3 600 from the card, 5% of it (180) back
    expect($p->amount)->toBe(3600)->and($p->discount)->toBe(400)
        ->and($card->fresh()->balance)->toBe(10000 - 3600 + 180)
        ->and($card->entries()->pluck('kind')->all())->toBe(['cashback', 'pay', 'topup']);
    expect(fn () => $this->money->charge($this->club, $this->owner, 'shop', 100000, 'card', $this->player))->toThrow(BookingException::class, __('booking.error.low_balance'));
    // cash: the discount still counts, the card balance doesn't move except the cashback
    $cash = $this->money->charge($this->club, $this->owner, 'training', 2000, 'cash', $this->player);
    expect($cash->amount)->toBe(1800)->and($card->fresh()->balance)->toBe(6580 + 90);
    // no card: card payment refused
    expect(fn () => $this->money->charge($this->club, $this->owner, 'shop', 100, 'card', User::factory()->create()))->toThrow(BookingException::class, __('booking.error.no_card'));
});

it('puts the card discount into the booking price', function () {
    $card = $this->cards->forPlayer($this->club, $this->player);
    $this->cards->setDiscount($card, 20);
    $small = $this->club->fields->firstWhere('size.value', 'small');
    $b = app(RequestBooking::class)->handle($this->player, $small, at(10), 60);   // 15 000 by day
    expect($b->price)->toBe(12000);
});

it('pays a bill of time and items: card first (only after a scan), the rest cash, once', function () {
    $card = $this->cards->forPlayer($this->club, $this->player);
    $this->cards->topUp($card, 13000, $this->owner);
    $small = $this->club->fields->firstWhere('size.value', 'small');
    $b = app(RequestBooking::class)->handle($this->player, $small, at(10), 60);   // 15 000
    $cola = app(Shop::class)->save(new Product(['venue_id' => $this->club->id]), ['name' => 'Cola', 'price' => 500]);
    $checkout = app(Checkout::class);
    $checkout->addItem($b, $cola, 2, $this->owner);
    $bill = $checkout->bill($b);
    expect($bill['total'])->toBe(16000)->and($bill['card']['scanned'])->toBeFalse();
    // not scanned: the card can't pay
    expect(fn () => $checkout->pay($b, 13000, 3000, $this->owner))->toThrow(BookingException::class, __('booking.error.scan_first'));
    Cards::markScanned($card);
    expect(fn () => $checkout->pay($b, 13000, 1000, $this->owner))->toThrow(BookingException::class);   // not the total
    $checkout->pay($b, 13000, 3000, $this->owner);
    // the card paid the time (13 000 of 15 000), cash the rest of the time and the items; 5% cashback on the card part
    $payments = App\Models\Payment::where('ref_id', $b->id)->get();
    expect($payments->where('kind', 'booking')->where('method', 'card')->sum('amount'))->toBe(13000)
        ->and($payments->where('kind', 'booking')->where('method', 'cash')->sum('amount'))->toBe(2000)
        ->and($payments->where('kind', 'shop')->sum('amount'))->toBe(1000)
        ->and($card->fresh()->balance)->toBe(0 + 650 + 100 + 50)
        ->and($b->fresh()->paid_at)->not->toBeNull()
        ->and(fn () => $checkout->pay($b->fresh(), 0, 16000, $this->owner))->toThrow(BookingException::class, __('booking.error.already_paid'))
        ->and(fn () => $checkout->addItem($b->fresh(), $cola, 1, $this->owner))->toThrow(BookingException::class);
});

it('gives a phone-only booking its player when their card is scanned at the bill', function () {
    $small = $this->club->fields->firstWhere('size.value', 'small');
    $b = app(App\Domain\Booking\Actions\OwnerBooking::class)->create($this->owner, $small, at(12), 60, ['name' => 'Walk-in']);
    $card = $this->cards->forPlayer($this->club, $this->player);
    app(Checkout::class)->attachCard($b, $card);
    expect($b->fresh()->user_id)->toBe($this->player->id)->and(Cards::scanned($card))->toBeTrue();
    $other = $this->cards->forPlayer($this->club, User::factory()->create());
    expect(fn () => app(Checkout::class)->attachCard($b->fresh(), $other))->toThrow(BookingException::class, __('booking.error.other_card'));
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
    $r = app(Ratings::class);
    $r->set($this->player, Sport::Pingpong, 1450, 'ARM-123', $this->club, $this->owner, 'after the league');
    $r->set($this->player, Sport::Pingpong, 1500, 'ARM-123', $this->club, $this->owner);
    expect(Ratings::of($this->player))->toBe(['pingpong' => ['rating' => 1500, 'intl_id' => 'ARM-123']])
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
    expect($tt->pay($e, 'cash', $this->owner)->amount)->toBe(5000);
    $tt->result($e->fresh(), 1);
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
        ->and($r['cash'])->toBe(13000)->and($r['fromCards'])->toBe(2000)->and($r['topups'])->toBe(10000)->and($r['rows'])->toHaveCount(3);
});
