<?php

use App\Domain\Booking\Actions\RequestBooking;
use App\Domain\Booking\BookingException;
use App\Domain\Club\Cards;
use App\Domain\Club\Money;
use App\Domain\Club\Ratings;
use App\Domain\Club\Report;
use App\Domain\Club\Shop;
use App\Domain\Club\Tournaments;
use App\Domain\Club\Trainings;
use App\Enums\Sport;
use App\Models\Product;
use App\Models\RatingChange;
use App\Models\Tournament;
use App\Models\Training;
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

it('puts the card discount into the booking price, and takes the booking money once', function () {
    $card = $this->cards->forPlayer($this->club, $this->player);
    $this->cards->setDiscount($card, 20);
    $small = $this->club->fields->firstWhere('size.value', 'small');
    $b = app(RequestBooking::class)->handle($this->player, $small, at(10), 60);   // 15 000 by day
    expect($b->price)->toBe(12000);
    $this->cards->topUp($card, 20000, $this->owner);
    $pay = $this->money->payBooking($b, 'card', $this->owner);
    expect($pay->amount)->toBe(12000)->and($pay->discount)->toBe(0)
        ->and(fn () => $this->money->payBooking($b, 'cash', $this->owner))->toThrow(BookingException::class, __('booking.error.already_paid'));
});

it('keeps a rating per sport with its history', function () {
    $r = app(Ratings::class);
    $r->set($this->player, Sport::Pingpong, 1450, 'ARM-123', $this->club, $this->owner, 'after the league');
    $r->set($this->player, Sport::Pingpong, 1500, 'ARM-123', $this->club, $this->owner);
    expect(Ratings::of($this->player))->toBe(['pingpong' => ['rating' => 1500, 'intl_id' => 'ARM-123']])
        ->and(RatingChange::where('user_id', $this->player->id)->pluck('new')->all())->toBe([1450, 1500]);
    expect(fn () => $r->set($this->player, Sport::Pingpong, 1, null, $this->other, $this->owner))->toThrow(BookingException::class);
});

it('marks a training visit once a day and takes its price', function () {
    $t = app(Trainings::class)->save(new Training(['venue_id' => $this->club->id]), ['title' => 'Kids', 'coach' => 'Ani', 'weekdays' => [1, 3], 'starts_at' => '18:00', 'minutes' => 90, 'price' => 3000, 'capacity' => 1]);
    $v = app(Trainings::class)->visit($t, $this->player, $this->owner, 'cash');
    expect($v->payment->amount)->toBe(3000)->and($v->payment->kind)->toBe('training')->and($v->payment->ref_id)->toBe($v->id)
        ->and(fn () => app(Trainings::class)->visit($t, $this->player, $this->owner, 'cash'))->toThrow(BookingException::class)
        ->and(fn () => app(Trainings::class)->visit($t, User::factory()->create(), $this->owner, null))->toThrow(BookingException::class, __('booking.error.training_full'));
    expect(Trainings::present($t))->toMatchArray(['start' => '18:00', 'end' => '19:30']);
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
