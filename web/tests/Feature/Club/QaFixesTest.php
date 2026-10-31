<?php

use App\Domain\Booking\Actions\OwnerBooking;
use App\Domain\Club\Checkout;
use App\Domain\Club\Commission;
use App\Models\Booking;
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
    // wrong length: stopped and paid within the same minute, then a new game on the same table
    $this->travelTo(LocalTime::at(LocalTime::now()->startOfDay(), 14)->addSeconds(40));
    $this->checkout->pay($a->fresh(), 0, $this->checkout->bill($a->fresh())['total'], $this->owner);
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
    $product = App\Models\Product::create(['venue_id' => $this->club->id, 'name' => 'Cola', 'price' => 500, 'in_stock' => true, 'active' => true]);
    $this->travelTo(LocalTime::at(LocalTime::now()->startOfDay()->addDay(), 15));
    $g = $this->checkout->walkIn($this->t1, $this->owner, 30);
    $this->checkout->addItem($g, $product, 2, $this->owner);
    $this->checkout->addItem($g->fresh(), $product, 1, $this->owner);
    expect(App\Models\BookingItem::where('booking_id', $g->id)->get(['qty'])->pluck('qty')->all())->toBe([3]);
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
    $theirs = App\Models\Conversation::create(['type' => 'venue', 'venue_id' => $other->id, 'direct_key' => 'venue:qa', 'last_message_at' => now()]);
    app(App\Domain\Chat\Chat::class)->join($theirs, $player->id);
    app(App\Domain\Chat\Chat::class)->join($theirs, $this->owner->id);
    $other->staff()->attach($this->owner->id, ['role' => 'owner']);
    $this->actingAs($this->owner)->withSession(['venue_id' => $this->club->id])->get('/owner/messages/'.$theirs->id)->assertNotFound();
    $this->actingAs($this->owner)->withSession(['venue_id' => $other->id])->get('/owner/messages/'.$theirs->id)->assertOk();
    $this->actingAs($player)->get('/admin')->assertForbidden()->assertInertia(fn ($p) => $p->component('Error')->where('status', 403));
});
