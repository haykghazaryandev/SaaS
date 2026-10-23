<?php

use App\Domain\Booking\Actions\ExpireAndComplete;
use App\Domain\Booking\Actions\RequestBooking;
use App\Enums\BookingStatus;
use App\Models\User;

it('expires requests the place did not answer and frees the time', function () {
    $venue = makeVenue(['request_expire_min' => 60]);
    $field = $venue->fields->firstWhere('size.value', 'small');
    $b = app(RequestBooking::class)->handle(User::factory()->create(), $field, at(20), 60);
    $this->travel(30)->minutes();
    expect(app(ExpireAndComplete::class)->expirePending())->toBe(0);
    $this->travel(31)->minutes();
    app(ExpireAndComplete::class)->expirePending();
    expect($b->fresh()->status)->toBe(BookingStatus::Expired)
        ->and($b->events()->where('type', 'expired')->exists())->toBeTrue();
});

it('marks accepted bookings as completed after they end', function () {
    $venue = makeVenue();
    $field = $venue->fields->firstWhere('size.value', 'small');
    $owner = User::factory()->create();
    $b = app(App\Domain\Booking\Actions\OwnerBooking::class)->create($owner, $field, at(20), 60, ['name' => 'X']);
    $this->travelTo(at(21.5));
    app(ExpireAndComplete::class)->completeEnded();
    expect($b->fresh()->status)->toBe(BookingStatus::Completed);
});
