<?php

use App\Domain\Booking\Actions\DecideBooking;
use App\Domain\Booking\Actions\RequestBooking;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->venue = makeVenue();
    $this->small = $this->venue->fields->firstWhere('size.value', 'small');
    $this->big = $this->venue->fields->firstWhere('size.value', 'big');
    $this->date = at(0)->toDateString(); // next Wednesday
});

it('shows the home page with places and their next free times', function () {
    app(RequestBooking::class)->handle(User::factory()->create(), $this->small, at(8), 60);

    $this->get('/?date='.$this->date)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('player/Home')
        ->where('filters.date', $this->date)
        ->has('days', 14)
        ->has('venues', 1)
        ->where('venues.0.slug', $this->venue->slug)
        ->where('venues.0.from', 15000)
        ->has('venues.0.fields', 2)
        ->where('venues.0.fields.0.starts', [9, 9.5, 10, 10.5]) // 08:00 is taken
    );
});

it('filters the home page by size and time of day', function () {
    $this->get('/?date='.$this->date.'&size=big&time=evening')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.size', 'big')
        ->has('venues.0.fields', 1)
        ->where('venues.0.fields.0.size', 'big')
        ->where('venues.0.fields.0.starts.0', 18)
    );
    // another district: nothing
    $this->get('/?district=kentron')->assertInertia(fn (Assert $page) => $page->has('venues', 0));
});

it('shows the place page with both fields and free times', function () {
    $this->get("/places/{$this->venue->slug}?date={$this->date}&field={$this->big->id}&start=19:00")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('player/Place')
        ->where('venue.slug', $this->venue->slug)
        ->has('fields', 2)
        ->where('initial.field', $this->big->id)
        ->where('initial.minutes', 90)
        ->where('initial.start', '19:00')
        ->where('slots.date', $this->date)
        ->has('slots.fields', 2)
        ->where('booked', null)
    );
});

it('returns free times as JSON for live updates', function () {
    app(DecideBooking::class)->accept(app(RequestBooking::class)->handle(User::factory()->create(), $this->small, at(20), 60), User::factory()->create());   // accepted: the time is taken

    $res = $this->getJson("/places/{$this->venue->slug}/slots?date={$this->date}&field={$this->small->id}&minutes=60")->assertOk()
        ->assertJsonPath('date', $this->date)
        ->assertJsonCount(1, 'fields');
    $starts = $res->json('fields.0.starts.60');
    expect($starts)->toContain(19, 21)->not->toContain(19.5, 20, 20.5);
    // half-hour prices: day rate before 18:00, prime after
    expect(collect($res->json('fields.0.rates'))->firstWhere(0, 17.5)[1])->toBe(15000)
        ->and(collect($res->json('fields.0.rates'))->firstWhere(0, 18)[1])->toBe(20000);

    // a field of another place is not found
    $other = makeVenue()->fields->first();
    $this->getJson("/places/{$this->venue->slug}/slots?date={$this->date}&field={$other->id}")->assertNotFound();
});

it('sends a request for a logged-in player', function () {
    $player = User::factory()->create(['registered_at' => now(), 'name' => 'Aram Petrosyan']);

    $this->actingAs($player)->post('/bookings', [
        'field_id' => $this->small->id, 'date' => $this->date, 'start' => '20:00', 'minutes' => 90, 'team_name' => 'Friday Lads', 'note' => '10 bibs',
    ])->assertSessionHasNoErrors()->assertRedirect(route('places.show', ['venue' => $this->venue->slug, 'date' => $this->date, 'field' => $this->small->id, 'minutes' => 90]));

    $b = Booking::first();
    expect($b->status)->toBe(BookingStatus::Pending)->and($b->source)->toBe(BookingSource::Player)
        ->and($b->user_id)->toBe($player->id)->and($b->price)->toBe(30000)->and($b->team_name)->toBe('Friday Lads')->and($b->note_player)->toBe('10 bibs');

    // the confirmation is shown once on the place page
    $this->withSession(['booked' => $b->id])->get("/places/{$this->venue->slug}")->assertInertia(fn (Assert $page) => $page
        ->where('booked.code', $b->code)->where('booked.status', 'pending')->where('booked.start', '20:00')->where('booked.end', '21:30')
    );
});

it('shows booking rule errors nicely', function () {
    $player = User::factory()->create(['registered_at' => now(), 'name' => 'Aram']);
    app(DecideBooking::class)->accept(app(RequestBooking::class)->handle(User::factory()->create(), $this->small, at(20), 60), User::factory()->create());   // accepted: the time is taken

    $this->actingAs($player)->from("/places/{$this->venue->slug}")->post('/bookings', [
        'field_id' => $this->small->id, 'date' => $this->date, 'start' => '20:30', 'minutes' => 60,
    ])->assertRedirect("/places/{$this->venue->slug}")->assertSessionHasErrors(['booking' => __('booking.error.taken')]);
});

it('asks a guest to log in before sending a request', function () {
    $this->post('/bookings', ['field_id' => $this->small->id, 'date' => $this->date, 'start' => '19:00', 'minutes' => 60])
        ->assertSessionHasErrors(['booking' => __('player.verify.login_first')]);
    expect(Booking::count())->toBe(0);
});

it('lists my bookings and lets me cancel my own', function () {
    $player = User::factory()->create(['registered_at' => now(), 'name' => 'Aram']);
    $b = app(RequestBooking::class)->handle($player, $this->small, at(20), 60);

    $this->actingAs($player)->get('/me/bookings')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('player/MyBookings')
        ->has('upcoming', 1)
        ->where('upcoming.0.code', $b->code)->where('upcoming.0.canCancel', true)->where('upcoming.0.status', 'pending')
    );

    $this->post("/bookings/{$b->id}/cancel")->assertRedirect()->assertSessionHas('success');
    expect($b->fresh()->status)->toBe(BookingStatus::Cancelled)->and($b->fresh()->cancelled_by)->toBe('player');

    // past and cancelled bookings are not listed (only what is still ahead)
    $this->get('/me/bookings')->assertInertia(fn (Assert $page) => $page->has('upcoming', 0)->missing('past'));
});

it('does not let a player cancel somebody else\'s booking', function () {
    $owner = User::factory()->create();
    $b = app(RequestBooking::class)->handle($owner, $this->small, at(20), 60);

    $this->actingAs(User::factory()->create())->post("/bookings/{$b->id}/cancel")->assertForbidden();
    expect($b->fresh()->status)->toBe(BookingStatus::Pending);
});

it('sends guests to the login page for my bookings', function () {
    $this->get('/me/bookings')->assertRedirect('/login');
});
