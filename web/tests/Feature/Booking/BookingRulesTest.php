<?php

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\DecideBooking;
use App\Domain\Booking\Actions\OwnerBooking;
use App\Domain\Booking\Actions\RequestBooking;
use App\Domain\Booking\BookingException;
use App\Domain\Venues\Availability;
use App\Domain\Venues\PriceCalculator;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;

beforeEach(function () {
    $this->venue = makeVenue();
    $this->small = $this->venue->fields->firstWhere('size.value', 'small');
    $this->player = User::factory()->create();
    $this->owner = User::factory()->create();
});

it('charges every half hour by its own price', function () {
    // Wednesday 17:30–19:00: 30 min at 15 000/h + 60 min at 20 000/h
    expect(app(PriceCalculator::class)->price($this->small, at(17.5), 90))->toBe(27500);
    // Saturday is prime all day
    expect(app(PriceCalculator::class)->price($this->small, at(10, 3), 60))->toBe(20000);
});

it('creates a pending request and the owner accepts it', function () {
    $b = app(RequestBooking::class)->handle($this->player, $this->small, at(20), 60);
    expect($b->status)->toBe(BookingStatus::Pending)->and($b->price)->toBe(20000)->and($b->code)->toStartWith('BB-');
    app(DecideBooking::class)->accept($b, $this->owner);
    expect($b->fresh()->status)->toBe(BookingStatus::Accepted)->and($b->events()->count())->toBe(2);
});

it('never lets two bookings of one field overlap (database guard)', function () {
    app(RequestBooking::class)->handle($this->player, $this->small, at(20), 60);
    // bypass the app check and insert directly: Postgres must refuse it
    expect(fn () => Illuminate\Support\Facades\DB::transaction(fn () => Booking::create([
        'code' => 'BB-1', 'venue_id' => $this->venue->id, 'field_id' => $this->small->id,
        'starts_at' => at(20.5)->utc(), 'ends_at' => at(21.5)->utc(), 'status' => 'accepted', 'source' => 'owner',
        'contact_name' => 'X', 'price' => 1,
    ])))->toThrow(Illuminate\Database\QueryException::class);
    // through the app: a clear "taken" error
    expect(fn () => app(RequestBooking::class)->handle(User::factory()->create(), $this->small, at(20.5), 60))
        ->toThrow(BookingException::class, __('booking.error.taken'));
});

it('frees the time again after a decline', function () {
    $b = app(RequestBooking::class)->handle($this->player, $this->small, at(20), 60);
    app(DecideBooking::class)->decline($b, $this->owner, 'Closed for repairs');
    expect(app(Availability::class)->isFree($this->small, at(20), 60))->toBeTrue();
});

it('applies the player rules', function () {
    $req = app(RequestBooking::class);
    expect(fn () => $req->handle($this->player, $this->small, at(7), 60))->toThrow(BookingException::class); // before opening
    expect(fn () => $req->handle($this->player, $this->small, at(20, 21), 60))->toThrow(BookingException::class); // too far ahead
    expect(fn () => $req->handle($this->player, $this->small, at(20), 45))->toThrow(BookingException::class); // bad duration
    $req->handle($this->player, $this->small, at(10), 60);
    $req->handle($this->player, $this->small, at(12), 60);
    expect(fn () => $req->handle($this->player, $this->small, at(14), 60))->toThrow(BookingException::class, __('booking.error.too_many_pending'));
});

it('lists free start times without the booked ones', function () {
    app(RequestBooking::class)->handle($this->player, $this->small, at(20), 60);
    $free = app(Availability::class)->freeStarts($this->small, at(0), 60);
    expect($free)->toContain(19.0, 21.0)->not->toContain(19.5, 20.0, 20.5);
});

it('lets the owner create, move and cancel bookings', function () {
    $ob = app(OwnerBooking::class);
    $b = $ob->create($this->owner, $this->small, at(18), 60, ['name' => 'Kentron FC', 'phone' => '093 11 22 33']);
    expect($b->status)->toBe(BookingStatus::Accepted)->and($b->contact_phone)->toBe('+37493112233');
    $b = $ob->update($this->owner, $b, ['starts_at' => at(19)->toIso8601String(), 'minutes' => 90]);
    expect($b->durationMinutes())->toBe(90)->and($b->price)->toBe(30000)->and($b->events()->where('type', 'changed')->exists())->toBeTrue();
    app(CancelBooking::class)->handle($b, $this->owner, 'owner', 'Rain');
    expect($b->fresh()->status)->toBe(BookingStatus::Cancelled);
});

it('creates weekly bookings and skips taken weeks', function () {
    app(OwnerBooking::class)->create($this->owner, $this->small, at(21, 7), 60, ['name' => 'Someone']);
    $r = app(OwnerBooking::class)->createWeekly($this->owner, $this->small, at(21), 60, 4, ['name' => 'Regulars']);
    expect($r['created'])->toHaveCount(3)->and($r['skipped'])->toHaveCount(1);
});

it('lets the owner remove a phone number from a booking', function () {
    $b = app(OwnerBooking::class)->create($this->owner, $this->small, at(18), 60, ['name' => 'X', 'phone' => '093112233']);
    $b = app(OwnerBooking::class)->update($this->owner, $b, ['contact_phone' => '']);
    expect($b->fresh()->contact_phone)->toBeNull();
});
