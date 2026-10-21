<?php

use App\Domain\Booking\Actions\OwnerBooking;
use App\Domain\Booking\Actions\RequestBooking;
use App\Domain\Venues\Availability;
use App\Domain\Venues\PriceCalculator;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Closure;
use App\Models\OpeningHour;
use App\Models\PriceRule;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->venue = makeVenue();
    $this->small = $this->venue->fields->firstWhere('size.value', 'small');
    $this->big = $this->venue->fields->firstWhere('size.value', 'big');
    $this->owner = User::factory()->create(['name' => 'Aram Owner']);
    $this->staff = User::factory()->create(['name' => 'Lilit Staff']);
    $this->player = User::factory()->create(['name' => 'Narek Player']);
    $this->venue->staff()->attach($this->owner->id, ['role' => 'owner']);
    $this->venue->staff()->attach($this->staff->id, ['role' => 'staff']);
    // another place with its own owner and bookings
    $this->other = makeVenue();
    $this->otherSmall = $this->other->fields->firstWhere('size.value', 'small');
});

/** A pending request of the player at the test place. */
function pendingRequest($test, float $hour = 20, int $plusDays = 0): Booking
{
    return app(RequestBooking::class)->handle($test->player, $test->small, at($hour, $plusDays), 60);
}

// ---------- access ----------

it('sends guests to the owner login and refuses players', function () {
    $this->get('/owner')->assertRedirect(route('login', ['to' => 'owner']));
    $this->actingAs($this->player)->get('/owner')->assertForbidden();
    $this->actingAs($this->player)->get('/owner/schedule')->assertForbidden();
    $this->actingAs($this->player)->post('/owner/bookings', [])->assertForbidden();
});

it('shows Today with KPIs, requests and the 7-day chart', function () {
    $req = pendingRequest($this);
    $this->actingAs($this->owner)->get('/owner')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('owner/Today')
        ->where('kpi.pending', 1)
        ->has('requests', 1)
        ->where('requests.0.id', $req->id)
        ->where('requests.0.tel', $this->player->phone)
        ->has('chart', 7)
        ->where('owner.venue.id', $this->venue->id)
        ->where('owner.role', 'owner')
        ->where('owner.pending', 1)
        ->has('owner.fields', 2));
});

it('lets staff work with bookings but not change settings', function () {
    $this->actingAs($this->staff)->get('/owner/settings')->assertOk()->assertInertia(fn (Assert $p) => $p->component('owner/Settings')->where('canEdit', false));
    $this->actingAs($this->staff)->put('/owner/settings/rules', ['free_cancel_hours' => 6, 'min_notice_min' => 30, 'request_expire_min' => 60, 'auto_accept_regulars' => true])->assertForbidden();
    $this->actingAs($this->staff)->put('/owner/settings/prices', ['prices' => []])->assertForbidden();
    $this->actingAs($this->staff)->post('/owner/closures', ['from' => '2030-01-01T10:00', 'to' => '2030-01-01T12:00'])->assertForbidden();
    $b = pendingRequest($this);
    $this->actingAs($this->staff)->post("/owner/bookings/{$b->id}/accept")->assertRedirect();
    expect($b->fresh()->status)->toBe(BookingStatus::Accepted);
});

it('switches between places the user works at, and only those', function () {
    $this->other->staff()->attach($this->owner->id, ['role' => 'staff']);
    $this->actingAs($this->owner)->post('/owner/venue', ['venue_id' => $this->other->id])->assertRedirect(route('owner.today'));
    $this->actingAs($this->owner)->get('/owner')->assertInertia(fn (Assert $p) => $p->where('owner.venue.id', $this->other->id)->where('owner.role', 'staff')->has('owner.venues', 2));
    $third = makeVenue();
    $this->actingAs($this->owner)->post('/owner/venue', ['venue_id' => $third->id])->assertForbidden();
});

// ---------- requests ----------

it('accepts and declines requests with a reason', function () {
    $a = pendingRequest($this, 18);
    $d = pendingRequest($this, 20);
    $this->actingAs($this->owner)->post("/owner/bookings/{$a->id}/accept")->assertSessionHas('success');
    $this->actingAs($this->owner)->post("/owner/bookings/{$d->id}/decline", ['reason' => 'Maintenance'])->assertSessionHas('success');
    expect($a->fresh()->status)->toBe(BookingStatus::Accepted)->and($a->fresh()->decided_by)->toBe($this->owner->id);
    expect($d->fresh()->status)->toBe(BookingStatus::Declined)->and($d->fresh()->decline_reason)->toBe('Maintenance');
    // answering twice is a clear error
    $this->actingAs($this->owner)->post("/owner/bookings/{$a->id}/decline")->assertSessionHasErrors(['booking' => __('booking.error.not_pending')]);
});

it('never touches bookings of another place', function () {
    $foreign = app(RequestBooking::class)->handle($this->player, $this->otherSmall, at(20), 60);
    $this->actingAs($this->owner)->post("/owner/bookings/{$foreign->id}/accept")->assertForbidden();
    $this->actingAs($this->owner)->post("/owner/bookings/{$foreign->id}/decline")->assertForbidden();
    $this->actingAs($this->owner)->post("/owner/bookings/{$foreign->id}/cancel")->assertForbidden();
    $this->actingAs($this->owner)->post("/owner/bookings/{$foreign->id}/no-show")->assertForbidden();
    $this->actingAs($this->owner)->patch("/owner/bookings/{$foreign->id}", ['field_id' => $this->otherSmall->id, 'date' => at(0)->toDateString(), 'start' => '21:00', 'minutes' => 60, 'name' => 'X'])->assertForbidden();
    $this->actingAs($this->owner)->getJson("/owner/bookings/{$foreign->id}")->assertForbidden();
    // a field of another place can't be used for a new booking or a move
    $this->actingAs($this->owner)->post('/owner/bookings', ['field_id' => $this->otherSmall->id, 'date' => at(0)->toDateString(), 'start' => '10:00', 'minutes' => 60, 'name' => 'X'])->assertSessionHasErrors('field_id');
    $mine = app(OwnerBooking::class)->create($this->owner, $this->small, at(10), 60, ['name' => 'Mine']);
    $this->actingAs($this->owner)->patch("/owner/bookings/{$mine->id}", ['field_id' => $this->otherSmall->id, 'date' => at(0)->toDateString(), 'start' => '10:00', 'minutes' => 60, 'name' => 'Mine'])->assertSessionHasErrors('field_id');
    expect($foreign->fresh()->status)->toBe(BookingStatus::Pending)->and($mine->fresh()->field_id)->toBe($this->small->id);
});

// ---------- create / edit / cancel / no-show ----------

it('creates a booking with the auto price and finds the player by phone', function () {
    $this->actingAs($this->staff)->post('/owner/bookings', [
        'field_id' => $this->small->id, 'date' => at(0)->toDateString(), 'start' => '17:30', 'minutes' => 90,
        'phone' => substr($this->player->phone, 4), 'team_name' => 'Kentron FC', 'note_owner' => 'Pays cash',
    ])->assertSessionHasNoErrors()->assertSessionHas('success');
    $b = Booking::where('venue_id', $this->venue->id)->latest('id')->first();
    expect($b->status)->toBe(BookingStatus::Accepted)
        ->and($b->price)->toBe(27500) // 30 min at 15 000/h + 60 min at 20 000/h
        ->and($b->user_id)->toBe($this->player->id)
        ->and($b->contact_name)->toBe('Narek Player')
        ->and($b->team_name)->toBe('Kentron FC')
        ->and($b->note_owner)->toBe('Pays cash')
        ->and($b->events()->first()->type)->toBe('created_by_owner');
});

it('takes the edited price, and refuses taken times and bad input', function () {
    $date = at(0)->toDateString();
    $this->actingAs($this->owner)->post('/owner/bookings', ['field_id' => $this->big->id, 'date' => $date, 'start' => '19:00', 'minutes' => 120, 'name' => 'Ararat', 'price' => 90000])->assertSessionHasNoErrors();
    expect(Booking::where('field_id', $this->big->id)->first()->price)->toBe(90000);
    $this->actingAs($this->owner)->post('/owner/bookings', ['field_id' => $this->big->id, 'date' => $date, 'start' => '20:00', 'minutes' => 60, 'name' => 'Late'])
        ->assertSessionHasErrors(['booking' => __('booking.error.taken')]);
    $this->actingAs($this->owner)->post('/owner/bookings', ['field_id' => $this->big->id, 'date' => $date, 'start' => '07:00', 'minutes' => 60, 'name' => 'Early'])
        ->assertSessionHasErrors(['booking' => __('booking.error.outside_hours')]);
    $this->actingAs($this->owner)->post('/owner/bookings', ['field_id' => $this->small->id, 'date' => $date, 'start' => '10:15', 'minutes' => 60, 'name' => 'X'])->assertSessionHasErrors('start');
    $this->actingAs($this->owner)->post('/owner/bookings', ['field_id' => $this->small->id, 'date' => $date, 'start' => '10:00', 'minutes' => 60])->assertSessionHasErrors('name');
    $this->actingAs($this->owner)->post('/owner/bookings', ['field_id' => $this->small->id, 'date' => $date, 'start' => '10:00', 'minutes' => 60, 'name' => 'X', 'phone' => '12'])->assertSessionHasErrors('phone');
});

it('creates weekly bookings and reports the skipped dates', function () {
    app(OwnerBooking::class)->create($this->owner, $this->small, at(21, 14), 60, ['name' => 'Someone']);
    $this->actingAs($this->owner)->post('/owner/bookings', [
        'field_id' => $this->small->id, 'date' => at(0)->toDateString(), 'start' => '21:00', 'minutes' => 60, 'name' => 'Regulars', 'weeks' => 4,
    ])->assertSessionHasNoErrors()->assertSessionHas('skipped', [at(0, 14)->toDateString()]);
    $made = Booking::where('contact_name', 'Regulars')->get();
    expect($made)->toHaveCount(3)->and($made->pluck('series_id')->unique())->toHaveCount(1)->and($made->first()->source->value)->toBe('recurring');
});

it('edits a booking: moves it, keeps or changes the price, and writes the history', function () {
    $b = app(OwnerBooking::class)->create($this->owner, $this->small, at(18), 60, ['name' => 'Kentron FC', 'phone' => '093112233']);
    $other = app(OwnerBooking::class)->create($this->owner, $this->small, at(21), 60, ['name' => 'Other']);
    $date = at(0)->toDateString();
    // move to 19:00 for 90 min: the price follows the price list
    $this->actingAs($this->owner)->patch("/owner/bookings/{$b->id}", ['field_id' => $this->small->id, 'date' => $date, 'start' => '19:00', 'minutes' => 90, 'name' => 'Kentron FC', 'phone' => '093112233', 'note_owner' => 'Regulars'])
        ->assertSessionHasNoErrors();
    $b->refresh();
    expect($b->durationMinutes())->toBe(90)->and($b->price)->toBe(30000)->and($b->note_owner)->toBe('Regulars');
    // moving onto another booking is refused (its own time is ignored)
    $this->actingAs($this->owner)->patch("/owner/bookings/{$b->id}", ['field_id' => $this->small->id, 'date' => $date, 'start' => '20:30', 'minutes' => 60, 'name' => 'Kentron FC'])
        ->assertSessionHasErrors(['booking' => __('booking.error.taken')]);
    $this->actingAs($this->owner)->patch("/owner/bookings/{$b->id}", ['field_id' => $this->small->id, 'date' => $date, 'start' => '19:30', 'minutes' => 90, 'name' => 'Kentron FC', 'price' => 25000])
        ->assertSessionHasNoErrors();
    expect($b->fresh()->price)->toBe(25000)->and($other->fresh()->status)->toBe(BookingStatus::Accepted);
    // the sheet JSON shows the history with who and what changed
    $json = $this->actingAs($this->owner)->getJson("/owner/bookings/{$b->id}")->assertOk()->json();
    expect($json['tel'])->toBe('+37493112233')->and(collect($json['events'])->pluck('type')->all())->toBe(['created_by_owner', 'changed', 'changed'])
        ->and($json['events'][1]['details'])->not->toBeEmpty();
});

it('cancels with a reason and marks a no-show only after the start', function () {
    $future = pendingRequest($this);
    $this->actingAs($this->owner)->post("/owner/bookings/{$future->id}/accept");
    $this->actingAs($this->owner)->post("/owner/bookings/{$future->id}/no-show")->assertSessionHasErrors('booking');
    $this->actingAs($this->owner)->post("/owner/bookings/{$future->id}/cancel", ['reason' => 'Rain'])->assertSessionHas('success');
    expect($future->fresh()->status)->toBe(BookingStatus::Cancelled)->and($future->fresh()->cancel_reason)->toBe('Rain')->and($future->fresh()->cancelled_by)->toBe('owner');

    // a game that started an hour ago (inserted directly: the app does not book the past)
    $started = Booking::create([
        'code' => 'BB-777', 'venue_id' => $this->venue->id, 'field_id' => $this->big->id, 'user_id' => $this->player->id,
        'starts_at' => now()->subHour(), 'ends_at' => now()->addMinutes(30), 'status' => 'accepted', 'source' => 'player', 'contact_name' => 'Narek', 'price' => 1,
    ]);
    $this->actingAs($this->owner)->post("/owner/bookings/{$started->id}/no-show")->assertSessionHas('success');
    expect($started->fresh()->status)->toBe(BookingStatus::NoShow)->and($this->player->fresh()->no_show_count)->toBe(1);
});

it('quotes the price, the free time and the player behind a phone', function () {
    $b = app(OwnerBooking::class)->create($this->owner, $this->small, at(20), 60, ['name' => 'Busy']);
    $q = ['field_id' => $this->small->id, 'date' => at(0)->toDateString(), 'start' => '20:00', 'minutes' => 60];
    $this->actingAs($this->owner)->getJson('/owner/bookings/quote?'.http_build_query($q + ['phone' => $this->player->phone]))
        ->assertOk()->assertJson(['price' => 20000, 'problem' => 'taken', 'player' => ['name' => 'Narek Player']]);
    // editing the same booking: its own time is free
    $this->actingAs($this->owner)->getJson('/owner/bookings/quote?'.http_build_query($q + ['ignore' => $b->id]))->assertJson(['problem' => null, 'player' => null]);
    $this->actingAs($this->owner)->getJson('/owner/bookings/quote?'.http_build_query(['field_id' => $this->otherSmall->id] + $q))->assertStatus(422);
});

// ---------- schedule ----------

it('gives the schedule day, week and list data', function () {
    $req = pendingRequest($this, 20);
    $date = at(0)->toDateString();
    $this->actingAs($this->owner)->get("/owner/schedule?date={$date}")->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('owner/Schedule')
        ->where('view', 'day')
        ->where('date', $date)
        ->where('day.open', 8)
        ->where('day.close', 24)
        ->has('day.bookings', 1)
        ->where('day.bookings.0.id', $req->id)
        ->where('day.bookings.0.kind', 'pending')
        ->where('day.bookings.0.start', 20)
        ->has('strip', 35)
        ->where('pendingDays', [$date]));
    $this->actingAs($this->owner)->get("/owner/schedule?date={$date}&view=week")->assertInertia(fn (Assert $p) => $p
        ->where('view', 'week')
        ->has('week.days', 7)
        ->where("week.days.0.fields.{$this->small->id}.pending", 1)
        ->where("week.days.0.fields.{$this->small->id}.blocks.0.kind", 'pending'));
    $this->actingAs($this->owner)->get('/owner/schedule?view=list')->assertInertia(fn (Assert $p) => $p->where('view', 'list')->has('list.rows', 1));
    $this->actingAs($this->owner)->get('/owner/schedule?view=nope')->assertSessionHasErrors('view');
});

// ---------- settings ----------

it('saves prices as three rules per field', function () {
    $prices = $this->venue->fields->map(fn ($f) => ['field_id' => $f->id, 'day' => 11000, 'evening' => 17000, 'weekend' => 19000])->all();
    $this->actingAs($this->owner)->put('/owner/settings/prices', ['prices' => $prices])->assertSessionHasNoErrors();
    expect(PriceRule::where('field_id', $this->small->id)->count())->toBe(3);
    $calc = app(PriceCalculator::class);
    $small = $this->small->fresh();
    expect($calc->price($small, at(10), 60))->toBe(11000)->and($calc->price($small, at(19), 60))->toBe(17000)->and($calc->price($small, at(10, 3), 60))->toBe(19000);
    // a field of another place is refused
    $this->actingAs($this->owner)->put('/owner/settings/prices', ['prices' => [['field_id' => $this->otherSmall->id, 'day' => 1, 'evening' => 1, 'weekend' => 1]]])->assertSessionHasErrors();
});

it('saves opening hours (24:00 allowed, closed days) and rules', function () {
    $hours = collect(range(0, 6))->map(fn ($wd) => ['weekday' => $wd, 'open' => '09:00', 'close' => '24:00'])->all();
    $hours[0] = ['weekday' => 0, 'open' => null, 'close' => null];
    $this->actingAs($this->owner)->put('/owner/settings/hours', ['hours' => $hours])->assertSessionHasNoErrors();
    expect(OpeningHour::where('venue_id', $this->venue->id)->count())->toBe(6)
        ->and(substr(OpeningHour::where('venue_id', $this->venue->id)->where('weekday', 1)->value('closes_at'), 0, 5))->toBe('24:00');
    $hours[1] = ['weekday' => 1, 'open' => '20:00', 'close' => '10:00'];
    $this->actingAs($this->owner)->put('/owner/settings/hours', ['hours' => $hours])->assertSessionHasErrors('hours.1.close');

    $this->actingAs($this->owner)->put('/owner/settings/rules', ['free_cancel_hours' => 6, 'min_notice_min' => 30, 'request_expire_min' => 60, 'auto_accept_regulars' => true])->assertSessionHasNoErrors();
    $v = $this->venue->fresh();
    expect($v->rule('free_cancel_hours'))->toBe(6)->and($v->rule('min_notice_min'))->toBe(30)->and($v->rule('auto_accept_regulars'))->toBeTrue()->and($v->rule('horizon_days'))->toBe(14);
});

it('adds and removes closures, which block the time', function () {
    $day = at(0);
    $this->actingAs($this->owner)->post('/owner/closures', [
        'field_id' => $this->small->id, 'from' => $day->format('Y-m-d').'T10:00', 'to' => $day->format('Y-m-d').'T14:00', 'reason' => 'Repairs',
    ])->assertSessionHasNoErrors();
    $c = Closure::firstOrFail();
    expect(app(Availability::class)->problem($this->small, at(11), 60, forPlayer: false))->toBe('taken');
    $this->actingAs($this->owner)->get('/owner/settings')->assertInertia(fn (Assert $p) => $p->has('closures', 1)->where('closures.0.reason', 'Repairs'));
    $this->actingAs($this->owner)->post('/owner/closures', ['from' => $day->format('Y-m-d').'T14:00', 'to' => $day->format('Y-m-d').'T10:00'])->assertSessionHasErrors('to');
    // a closure of another place can't be removed
    $foreign = Closure::create(['venue_id' => $this->other->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
    $this->actingAs($this->owner)->delete("/owner/closures/{$foreign->id}")->assertForbidden();
    $this->actingAs($this->owner)->delete("/owner/closures/{$c->id}")->assertSessionHas('success');
    expect(Closure::count())->toBe(1);
});
