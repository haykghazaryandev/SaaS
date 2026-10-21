<?php

use App\Domain\Booking\Actions\RequestBooking;
use App\Domain\Booking\Alerts\SlotAlerts;
use App\Models\Closure;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// UX review 2026-10-22, "Player: booking" 1–5: next free times, taken times, place info, my bookings, slot alerts.

beforeEach(function () {
    $this->venue = makeVenue();
    $this->venue->update(['lat' => 40.2079, 'lng' => 44.4953, 'phone' => '+37410555555']);
    $this->small = $this->venue->fields->firstWhere('size.value', 'small');
    $this->big = $this->venue->fields->firstWhere('size.value', 'big');
    $this->date = at(0)->toDateString(); // next Wednesday
    $this->next = at(0, 1)->toDateString();
});

/** the small field is closed for the whole test day */
function closeSmallAllDay($t): void
{
    Closure::create(['venue_id' => $t->venue->id, 'field_id' => $t->small->id, 'starts_at' => at(0)->utc(), 'ends_at' => at(24)->utc(), 'reason' => 'repair']);
}

it('shows up to 4 free times per field on home cards and the next free time when a field is full', function () {
    closeSmallAllDay($this);

    $this->get('/?date='.$this->date)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('venues.0.fields.0.size', 'small')
        ->where('venues.0.fields.0.starts', [])
        ->where('venues.0.fields.0.next', ['date' => $this->next, 'start' => 8])
        ->where('venues.0.fields.1.next', null) // the big field has free times
        ->has('venues.0.fields.1.starts', 4)
    );
    // with a size filter it is still 4
    $this->get('/?date='.$this->date.'&size=big')->assertInertia(fn (Assert $page) => $page->has('venues.0.fields.0.starts', 4));
});

it('sends taken start times next to the free ones', function () {
    app(RequestBooking::class)->handle(User::factory()->create(), $this->small, at(20), 60);

    $res = $this->getJson("/places/{$this->venue->slug}/slots?date={$this->date}&field={$this->small->id}&minutes=60")->assertOk();
    expect($res->json('fields.0.taken.60'))->toBe([19.5, 20, 20.5])
        ->and($res->json('fields.0.starts.60'))->not->toContain(19.5, 20, 20.5)
        ->and($res->json('fields.0.next'))->toBe([]);
});

it('says when a fully booked day is free next', function () {
    closeSmallAllDay($this);

    $res = $this->getJson("/places/{$this->venue->slug}/slots?date={$this->date}&field={$this->small->id}")->assertOk();
    expect($res->json('fields.0.starts.60'))->toBe([])
        ->and($res->json('fields.0.taken.60'))->toHaveCount(31) // 08:00 … 23:00, every half hour
        ->and($res->json('fields.0.next.60'))->toBe(['date' => $this->next, 'start' => 8])
        ->and($res->json('fields.0.next.120'))->toBe(['date' => $this->next, 'start' => 8]);
});

it('gives the place page the map, phone and the player\'s alerts', function () {
    $this->get("/places/{$this->venue->slug}?date={$this->date}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('venue.lat', 40.2079)->where('venue.lng', 44.4953)
        ->where('venue.phone', '+37410555555')->has('venue.phoneLabel')
        ->where('alerts', [])
        ->has('slots.fields.0.taken')->has('slots.fields.0.next')
    );

    $player = User::factory()->create(['registered_at' => now(), 'name' => 'Aram']);
    app(RequestBooking::class)->handle(User::factory()->create(), $this->small, at(20), 60);
    $alert = app(SlotAlerts::class)->watch($player, $this->small, at(20), 60);

    $this->actingAs($player)->get("/places/{$this->venue->slug}?date={$this->date}")->assertInertia(fn (Assert $page) => $page
        ->has('alerts', 1)
        ->where('alerts.0.id', $alert->id)->where('alerts.0.field', $this->small->id)
        ->where('alerts.0.date', $this->date)->where('alerts.0.start', '20:00')->where('alerts.0.notified', false)
    );
});

it('shows my bookings without the profile block, with place contacts and my alerts', function () {
    $player = User::factory()->create(['registered_at' => now(), 'name' => 'Aram']);
    app(RequestBooking::class)->handle($player, $this->small, at(20), 60);
    app(RequestBooking::class)->handle(User::factory()->create(), $this->big, at(19), 90);
    app(SlotAlerts::class)->watch($player, $this->big, at(19), 90);

    $this->actingAs($player)->get('/me/bookings')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('player/MyBookings')
        ->missing('profile')->missing('past')->missing('tab')->missing('stats')
        ->where('upcoming.0.venue.phone', '+37410555555')
        ->where('upcoming.0.venue.lat', 40.2079)
        ->has('alerts', 1)
        ->where('alerts.0.slug', $this->venue->slug)->where('alerts.0.start', '19:00')->where('alerts.0.notified', false)
    );
});

it('lists only bookings that are still ahead', function () {
    $player = User::factory()->create(['registered_at' => now(), 'name' => 'Aram']);
    $ahead = app(RequestBooking::class)->handle($player, $this->small, at(20), 60);
    $over = app(RequestBooking::class)->handle($player, $this->big, at(10), 90);
    $over->forceFill(['status' => 'accepted', 'starts_at' => now()->subHours(3), 'ends_at' => now()->subHours(2)])->save();
    $declined = app(RequestBooking::class)->handle($player, $this->small, at(12), 60);
    $declined->forceFill(['status' => 'declined'])->save();

    $this->actingAs($player)->get('/me/bookings')->assertInertia(fn (Assert $page) => $page
        ->has('upcoming', 1)->where('upcoming.0.id', $ahead->id)
    );
});
