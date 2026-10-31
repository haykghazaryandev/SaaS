<?php

use App\Domain\Booking\Actions\OwnerBooking;
use App\Domain\Chat\Chat;
use App\Domain\Club\Checkout;
use App\Domain\Club\Commission;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Conversation;
use App\Models\Product;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

function yerevan($t): CarbonImmutable
{
    return CarbonImmutable::parse($t)->setTimezone(LocalTime::tz());
}

beforeEach(function () {
    $this->club = makeClub(2, ['rules' => ['min_notice_min' => 0, 'step_min' => 15], 'features' => ['booking', 'desk', 'shop']]);
    $this->owner = User::factory()->create();
    $this->club->staff()->attach($this->owner->id, ['role' => 'owner']);
    $this->t1 = $this->club->fields->first()->setRelation('venue', $this->club);
    $this->checkout = app(Checkout::class);
});

it('takes the money of a game that ran into the next booking (the table stays booked for the next one)', function () {
    $start = LocalTime::at(LocalTime::now()->startOfDay()->addDay(), 12);
    $this->travelTo($start->subMinutes(2));
    $game = $this->checkout->walkIn($this->t1, $this->owner, 60);
    $next = app(OwnerBooking::class)->create($this->owner, $this->t1, yerevan($game->ends_at)->addMinutes(5), 60, ['name' => 'Next team']);
    // they play on: 30 min past their end, the next one has started
    $this->travelTo(yerevan($game->ends_at)->addMinutes(30));
    $total = $this->checkout->bill($game->fresh())['total'];
    $this->checkout->pay($game->fresh(), 0, $total, $this->owner);
    expect($game->fresh()->paid_at)->not->toBeNull()->and($game->fresh()->stopped_at)->not->toBeNull()
        ->and($next->fresh()->status->value)->toBe('accepted');
});

it('starts a walk-in from this minute, and again right after a game stopped in its first minute', function () {
    $this->travelTo(LocalTime::at(LocalTime::now()->startOfDay()->addDay(), 14)->addSeconds(20));
    $a = $this->checkout->walkIn($this->t1, $this->owner, 15);
    // 15 min from 14:00 (not 14:01–14:16: no "16 min left")
    expect(yerevan($a->starts_at)->format('H:i'))->toBe('14:00')->and($a->durationMinutes())->toBe(15);
    // wrong length: ended and paid within the same minute, then a new game on the same table
    $this->travelTo(LocalTime::at(LocalTime::now()->startOfDay(), 14)->addSeconds(40));
    $this->checkout->stop($a->fresh(), $this->owner);
    $this->checkout->pay($a->fresh(), 0, $this->checkout->bill($a->fresh())['due'], $this->owner);
    $b = $this->checkout->walkIn($this->t1, $this->owner, 30);
    expect(yerevan($b->starts_at)->format('H:i'))->toBe('14:01')
        ->and(Booking::where('field_id', $this->t1->id)->whereNull('paid_at')->count())->toBe(1);
});

it('counts the played bookings of a place that has the desk but took no money in the app (football paid in cash)', function () {
    $park = makeVenue();   // football: the desk on by default
    $field = $park->fields->first();
    Booking::create(['code' => 'BB-QA1', 'venue_id' => $park->id, 'field_id' => $field->id, 'status' => 'completed', 'source' => 'owner', 'contact_name' => 'Team',
        'starts_at' => LocalTime::now()->startOfDay()->addHours(1)->utc(), 'ends_at' => LocalTime::now()->startOfDay()->addHours(2)->utc(), 'price' => 20000]);
    [$from, $to] = Commission::period('day');
    $r = app(Commission::class)->between($park->fresh(), $from, $to);
    expect($park->hasFeature('desk'))->toBeTrue()->and($r['byKind']['unpaid'])->toBe(20000)->and($r['fee'])->toBe(1000)->and($r['desk'])->toBeFalse();
});

it('puts the same product on a bill as one line (3 × Cola, not 2 × and 1 ×)', function () {
    $product = Product::create(['venue_id' => $this->club->id, 'name' => 'Cola', 'price' => 500, 'in_stock' => true, 'active' => true]);
    $this->travelTo(LocalTime::at(LocalTime::now()->startOfDay()->addDay(), 15));
    $g = $this->checkout->walkIn($this->t1, $this->owner, 30);
    $this->checkout->addItem($g, $product, 2, $this->owner);
    $this->checkout->addItem($g->fresh(), $product, 1, $this->owner);
    expect(BookingItem::where('booking_id', $g->id)->get(['qty'])->pluck('qty')->all())->toBe([3]);
});

it('keeps the language chosen in the profile even when the page address has ?lang=', function () {
    $u = User::factory()->create(['locale' => 'hy']);
    $this->actingAs($u)->get('/me/profile?lang=hy');
    $this->actingAs($u)->from('/me/profile?lang=hy')->patch(route('me.profile.update'), ['name' => $u->name, 'locale' => 'ru'])
        ->assertRedirect('/me/profile?lang=ru');
    expect($u->fresh()->locale)->toBe('ru');
});

it('shows a place the number of days its admin set for booking ahead', function () {
    $this->club->update(['rules' => [...$this->club->rules, 'horizon_days' => 21]]);
    $this->get('/places/'.$this->club->slug)->assertInertia(fn ($p) => $p->has('days', 21));
});

it('opens a chat in the place panel only when it is a chat of the place picked; non-admins get our 403 page on /admin', function () {
    $player = User::factory()->create();
    $other = makeClub(1);
    $theirs = Conversation::create(['type' => 'venue', 'venue_id' => $other->id, 'direct_key' => 'venue:qa', 'last_message_at' => now()]);
    app(Chat::class)->join($theirs, $player->id);
    app(Chat::class)->join($theirs, $this->owner->id);
    $other->staff()->attach($this->owner->id, ['role' => 'owner']);
    $this->actingAs($this->owner)->withSession(['venue_id' => $this->club->id])->get('/owner/messages/'.$theirs->id)->assertNotFound();
    $this->actingAs($this->owner)->withSession(['venue_id' => $other->id])->get('/owner/messages/'.$theirs->id)->assertOk();
    $this->actingAs($player)->get('/admin')->assertForbidden()->assertInertia(fn ($p) => $p->component('Error')->where('status', 403));
});

it('takes the money before the end: the game plays on, paid; more after it is a new amount; at the end the table is free', function () {
    $this->travelTo(LocalTime::at(LocalTime::now()->startOfDay()->addDay(), 16));
    $g = $this->checkout->walkIn($this->t1, $this->owner, 60);
    $this->checkout->pay($g->fresh(), 0, $this->checkout->bill($g->fresh())['due'], $this->owner);
    expect($g->fresh()->stopped_at)->toBeNull()->and($g->fresh()->paid_at)->not->toBeNull();
    $now = fn () => $this->actingAs($this->owner)->withSession(['venue_id' => $this->club->id])->get('/owner/now');
    $now()->assertInertia(fn ($p) => $p->where('courts.0.state', 'playing')->where('courts.0.game.paid', true)->where('courts.0.game.bill.due', 0));
    // 15 min more: that is to pay now
    $this->checkout->extend($g->fresh(), 15, $this->owner);
    expect($this->checkout->bill($g->fresh())['due'])->toBeGreaterThan(0);
    $this->checkout->pay($g->fresh(), 0, $this->checkout->bill($g->fresh())['due'], $this->owner);
    // the time is up: paid, so the table is simply free
    $this->travelTo(LocalTime::at(LocalTime::now()->startOfDay(), 17)->addMinutes(20));
    $now()->assertInertia(fn ($p) => $p->where('courts.0.state', 'free')->where('toPay', []));
});

it('moves a game over and not paid to "To pay" when the next team is due, so the team can start', function () {
    $this->travelTo(LocalTime::at(LocalTime::now()->startOfDay()->addDay(), 16));
    $g = $this->checkout->walkIn($this->t1, $this->owner, 60);
    $next = app(OwnerBooking::class)->create($this->owner, $this->t1, yerevan($g->ends_at)->addMinutes(30), 60, ['name' => 'Next team']);
    $now = fn () => $this->actingAs($this->owner)->withSession(['venue_id' => $this->club->id])->get('/owner/now');
    // time up, the next team not due yet: the red card stays on the table
    $this->travelTo(yerevan($g->ends_at)->addSeconds(10));
    $now()->assertInertia(fn ($p) => $p->where('courts.0.game.id', $g->id)->where('courts.0.due', null)->where('toPay', []));
    // the next team's time has come: the table shows them, the old game waits under "To pay"
    $this->travelTo(yerevan($next->starts_at)->addMinutes(2));
    $now()->assertInertia(fn ($p) => $p->where('courts.0.due.id', $next->id)->where('courts.0.toPay.0.id', $g->id)->where('toPay.0.id', $g->id));
    $this->checkout->start($next->fresh(), $this->owner);
    $this->checkout->pay($g->fresh(), 0, $this->checkout->bill($g->fresh())['due'], $this->owner);
    $now()->assertInertia(fn ($p) => $p->where('courts.0.game.id', $next->id)->where('toPay', []));
});
