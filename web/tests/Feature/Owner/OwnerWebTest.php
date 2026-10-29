<?php

use App\Domain\Booking\Actions\OwnerBooking;
use App\Domain\Booking\Actions\RequestBooking;
use App\Domain\Booking\Events\ScheduleChanged;
use App\Domain\Venues\Availability;
use App\Domain\Venues\PriceCalculator;
use App\Enums\BookingStatus;
use App\Http\Controllers\Owner\VenueStats;
use App\Models\Booking;
use App\Models\Closure;
use App\Models\OpeningHour;
use App\Models\PriceRule;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Support\Facades\Event;
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

it('shows the four numbers of the day shown on top of the schedule: bookings, requests waiting, trainings, how full', function () {
    $req = pendingRequest($this);
    $day = $req->starts_at->setTimezone(LocalTime::tz())->toDateString();
    // the old Today address opens the schedule
    $this->actingAs($this->owner)->get('/owner')->assertRedirect(route('owner.schedule'));
    // today: the request is for another day
    $this->actingAs($this->owner)->get('/owner/schedule')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('owner/Schedule')
        ->where('kpi.date', VenueStats::today()->toDateString())
        ->where('kpi.pending', 0)
        ->where('kpi.trainings', 0)
        ->has('kpi.occupancy', 2)
        ->where('owner.venue.id', $this->venue->id)
        ->where('owner.role', 'owner')
        ->where('owner.pending', 1)
        ->has('owner.fields', 2));
    // the request's day picked: its numbers
    $this->actingAs($this->owner)->get("/owner/schedule?date={$day}")->assertInertia(fn (Assert $p) => $p->where('kpi.date', $day)->where('kpi.pending', 1));
});

it('lets staff work with bookings but not change settings', function () {
    // the settings, team, money and ZoneSpot fee pages are the owner's: staff go back to their start page
    foreach (['/owner/settings', '/owner/team', '/owner/fee', '/owner/money', '/owner/tournaments/new'] as $url) {
        $this->actingAs($this->staff)->get($url)->assertRedirect(route('owner.start'));
    }

    $this->actingAs($this->staff)->put('/owner/settings/rules', ['min_notice_min' => 30, 'request_expire_min' => 60, 'auto_accept_regulars' => true])->assertForbidden();
    $this->actingAs($this->staff)->put('/owner/settings/prices', ['prices' => []])->assertForbidden();
    $this->actingAs($this->staff)->post('/owner/closures', ['from' => '2030-01-01T10:00', 'to' => '2030-01-01T12:00'])->assertForbidden();
    $b = pendingRequest($this);
    $this->actingAs($this->staff)->post("/owner/bookings/{$b->id}/accept")->assertRedirect();
    expect($b->fresh()->status)->toBe(BookingStatus::Accepted);
});

it('switches between places the user works at, and only those', function () {
    $this->other->staff()->attach($this->owner->id, ['role' => 'staff']);
    $this->actingAs($this->owner)->post('/owner/venue', ['venue_id' => $this->other->id])->assertRedirect(route('owner.start'));
    $this->actingAs($this->owner)->get('/owner/schedule')->assertInertia(fn (Assert $p) => $p->where('owner.venue.id', $this->other->id)->where('owner.role', 'staff')->has('owner.venues', 2));
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
    $this->actingAs($this->owner)->post('/owner/bookings', ['field_id' => $this->small->id, 'date' => $date, 'start' => '10:10', 'minutes' => 60, 'name' => 'X'])->assertSessionHasErrors('start');
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

it('gives the schedule from now on, a day picked and the next 7 days, without open requests', function () {
    pendingRequest($this, 20);
    $ok = app(OwnerBooking::class)->create($this->owner, $this->small, at(10), 90, ['name' => 'Morning']);
    $date = at(0)->toDateString();
    $this->actingAs($this->owner)->get("/owner/schedule?date={$date}")->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('owner/Schedule')
        ->where('view', 'day')
        ->where('date', $date)
        // the request waits on Requests: only the accepted booking here
        ->has('day.rows', 1)->where('day.rows.0.id', $ok->id)->where('day.rows.0.start', 10)
        ->where('day.hours', [8, 24])
        ->has('days', 7));
    $this->actingAs($this->owner)->get('/owner/schedule')->assertInertia(fn (Assert $p) => $p->where('view', 'now')->has('upcoming.rows')->has('days', 7)->missing('day'));
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

    $this->actingAs($this->owner)->put('/owner/settings/rules', ['min_notice_min' => 30, 'request_expire_min' => 60, 'auto_accept_regulars' => true])->assertSessionHasNoErrors();
    $v = $this->venue->fresh();
    expect($v->rule('min_notice_min'))->toBe(30)->and($v->rule('auto_accept_regulars'))->toBeTrue()->and($v->rule('horizon_days'))->toBe(14);
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

// ---------- UX review 2026-10-22: Today strip, conflicts, undo-friendly answers, expired, one "Save changes" ----------

it('lists the requests on their own page and flags requests that clash', function () {
    // the same player asks for both fields at overlapping times, and once more later
    $a = pendingRequest($this, 20);
    $b = app(RequestBooking::class)->handle($this->player, $this->big, at(20.5), 90);
    $this->actingAs($this->owner)->get(route('owner.requests'))->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('owner/Requests')
        ->has('requests', 2)
        ->where('requests.0.id', $a->id)
        ->where('requests.0.tel', $this->player->phone)
        ->where('requests.0.conflicts.0.id', $b->id)
        ->where('requests.0.conflicts.0.field_id', $this->big->id)
        ->where('requests.1.conflicts.0.id', $a->id));
    // a request of another player at another time does not clash
    $other = User::factory()->create();
    $c = app(RequestBooking::class)->handle($other, $this->small, at(10, 1), 60);
    $this->actingAs($this->owner)->get(route('owner.requests'))->assertInertia(fn (Assert $p) => $p
        ->where('requests', fn ($r) => collect($r)->firstWhere('id', $c->id)['conflicts'] === []));
    // the bell still gets JSON
    $this->actingAs($this->owner)->getJson(route('owner.requests'))->assertOk()->assertJsonCount(3, 'requests');
});

it('answers requests with JSON for the Undo toast and refuses a second answer', function () {
    $a = pendingRequest($this, 18);
    $d = pendingRequest($this, 20);
    $this->actingAs($this->owner)->postJson("/owner/bookings/{$a->id}/accept")->assertOk()->assertJson(['ok' => true]);
    $this->actingAs($this->owner)->postJson("/owner/bookings/{$d->id}/decline", ['reason' => 'Rain'])->assertOk();
    expect($a->fresh()->status)->toBe(BookingStatus::Accepted)->and($d->fresh()->status)->toBe(BookingStatus::Declined)->and($d->fresh()->decline_reason)->toBe('Rain');
    $this->actingAs($this->owner)->postJson("/owner/bookings/{$a->id}/decline")->assertStatus(422)->assertJsonPath('errors.booking.0', __('booking.error.not_pending'));
    $foreign = app(RequestBooking::class)->handle($this->player, $this->otherSmall, at(21), 60);
    $this->actingAs($this->owner)->postJson("/owner/bookings/{$foreign->id}/accept")->assertForbidden();
});

it('shows unanswered requests whose time has passed as expired, before the job runs', function () {
    $late = Booking::create([
        'code' => 'BB-902', 'venue_id' => $this->venue->id, 'field_id' => $this->small->id, 'user_id' => $this->player->id,
        'starts_at' => now()->subMinutes(10), 'ends_at' => now()->addMinutes(50), 'status' => 'pending', 'source' => 'player', 'contact_name' => 'Late', 'price' => 1,
    ]);
    $this->actingAs($this->owner)->get(route('owner.requests'))->assertInertia(fn (Assert $p) => $p->has('requests', 0));
    $this->actingAs($this->owner)->get('/owner/schedule')->assertInertia(fn (Assert $p) => $p->where('kpi.pending', 0));
    $this->actingAs($this->owner)->getJson("/owner/bookings/{$late->id}")->assertJsonPath('kind', 'expired');
});

it('saves prices, hours, rules and the place phone with one "Save changes"', function () {
    $prices = $this->venue->fields->map(fn ($f) => ['field_id' => $f->id, 'day' => 12000, 'evening' => 18000, 'weekend' => 21000])->all();
    $hours = collect(range(0, 6))->map(fn ($wd) => ['weekday' => $wd, 'open' => '10:00', 'close' => '23:00'])->all();
    $rules = ['min_notice_min' => 60, 'request_expire_min' => 120, 'auto_accept_regulars' => false];
    $this->actingAs($this->owner)->put('/owner/settings', ['prices' => $prices, 'hours' => $hours, 'rules' => $rules, 'phone' => '+374 99 12 34 56'])
        ->assertSessionHasNoErrors()->assertSessionHas('success', __('owner.flash.settings_saved'));
    $v = $this->venue->fresh();
    expect($v->phone)->toBe('+37499123456')
        ->and($v->rule('min_notice_min'))->toBe(60)->and($v->rule('request_expire_min'))->toBe(120)->and($v->rule('auto_accept_regulars'))->toBeFalse()
        ->and(app(PriceCalculator::class)->price($this->small->fresh(), at(10), 60))->toBe(12000)
        ->and(substr(OpeningHour::where('venue_id', $v->id)->where('weekday', 3)->value('opens_at'), 0, 5))->toBe('10:00');
    $this->actingAs($this->owner)->get('/owner/settings')->assertInertia(fn (Assert $p) => $p->where('place.tel', '+37499123456')->where('place.phone', '+374 99 123 456'));

    // only what changed is sent: a phone alone leaves the rest as it is; null removes it
    $this->actingAs($this->owner)->put('/owner/settings', ['phone' => '091 00 00 00'])->assertSessionHasNoErrors();
    expect($v->fresh()->phone)->toBe('+37491000000')->and(PriceRule::where('field_id', $this->small->id)->count())->toBe(3);
    $this->actingAs($this->owner)->put('/owner/settings', ['phone' => null])->assertSessionHasNoErrors();
    expect($v->fresh()->phone)->toBeNull();

    // nothing is saved when one part is wrong
    $bad = $hours;
    $bad[2] = ['weekday' => 2, 'open' => '22:00', 'close' => '10:00'];
    $this->actingAs($this->owner)->put('/owner/settings', ['rules' => [...$rules, 'min_notice_min' => 0], 'hours' => $bad])->assertSessionHasErrors('hours.2.close');
    $this->actingAs($this->owner)->put('/owner/settings', ['phone' => '12345'])->assertSessionHasErrors(['phone' => __('owner.settings.bad_phone')]);
    $this->actingAs($this->owner)->put('/owner/settings', ['rules' => ['request_expire_min' => 30]])->assertSessionHasErrors('rules.min_notice_min');
    expect($v->fresh()->rule('min_notice_min'))->toBe(60)->and($v->fresh()->phone)->toBeNull();

    // staff can't
    $this->actingAs($this->staff)->put('/owner/settings', ['phone' => '091000000'])->assertForbidden();
});

// ---------- live schedule and walk-ins ----------

it('lets the place book the half hour that has already begun, but not earlier', function () {
    [$day, $t2000, $t2030] = [at(0), at(20), at(20.5)];
    $this->travelTo(LocalTime::at($day, 20 + 40 / 60)); // 20:40
    $avail = app(Availability::class);
    expect($avail->problem($this->small, $t2030, 60, forPlayer: false))->toBeNull()
        ->and($avail->problem($this->small, $t2000, 60, forPlayer: false))->toBe('past')
        ->and($avail->problem($this->small, $t2030, 60))->toBe('past')
        ->and($avail->freeStarts($this->small, $day, 60, false))->toContain(20.5)->not->toContain(20.0);
});

it('tells owner pages and the booking page at once when the times change', function () {
    Event::fake([ScheduleChanged::class]);
    $b = pendingRequest($this, 20);
    $day = at(20)->toDateString();
    Event::assertDispatched(ScheduleChanged::class,
        fn ($e) => $e->venueId === $this->venue->id && $e->dates === [$day]
            && collect($e->broadcastOn())->map->name->all() === ["private-venue.{$this->venue->id}", "place.{$this->venue->id}"]);
    // a moved booking updates both days; a reminder written to the row does not count
    Event::fake([ScheduleChanged::class]);
    $b->update(['reminded_day_at' => now()]);
    Event::assertNotDispatched(ScheduleChanged::class);
    $b->update(['starts_at' => at(20, 1)->utc(), 'ends_at' => at(21, 1)->utc()]);
    Event::assertDispatched(ScheduleChanged::class, fn ($e) => $e->dates === [at(20, 1)->toDateString(), $day]);
});

it('gives the booking form the free and taken start times of the day', function () {
    app(OwnerBooking::class)->create($this->owner, $this->small, at(20), 60, ['name' => 'Busy']);
    $q = ['field_id' => $this->small->id, 'date' => at(0)->toDateString(), 'start' => '19:00', 'minutes' => 60];
    $r = $this->actingAs($this->owner)->getJson('/owner/bookings/quote?'.http_build_query($q))->assertOk()->json();
    expect($r['starts'])->toContain(8, 19, 21, 23)->not->toContain(19.5, 20, 20.5, 23.5)
        ->and($r['taken'])->toBe([19.5, 20, 20.5]);
});
