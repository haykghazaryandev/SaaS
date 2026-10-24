<?php

use App\Domain\Booking\Actions\RequestBooking;
use App\Models\User;

// The place page says up front why a new request can't be sent (instead of failing after "Book").
it('tells the player about waiting requests and a booking pause before they tap Book', function () {
    $venue = makeVenue();
    $u = User::factory()->create();
    $this->actingAs($u)->get("/places/{$venue->slug}")->assertInertia(fn ($p) => $p->where('limit.pending', 0)->where('limit.maxPending', 2)->where('limit.bannedUntil', null));

    foreach ([18, 20] as $h) {
        app(RequestBooking::class)->handle($u, $venue->fields->first(), at($h), 60);
    }
    $this->actingAs($u)->get("/places/{$venue->slug}")->assertInertia(fn ($p) => $p->where('limit.pending', 2));

    $u->forceFill(['booking_ban_until' => now()->addDays(10)])->save();
    $this->actingAs($u)->get("/places/{$venue->slug}")->assertInertia(fn ($p) => $p->whereNot('limit.bannedUntil', null));
    $this->post('/logout');
    $this->get("/places/{$venue->slug}")->assertInertia(fn ($p) => $p->where('limit', null));
});

it('marks the player\'s own requests among the times of the place, only for them', function () {
    $venue = makeVenue();
    $small = $venue->fields->firstWhere('size.value', 'small');
    $u = User::factory()->create();
    $b = app(RequestBooking::class)->handle($u, $small, at(20), 60);

    $this->actingAs($u)->getJson("/places/{$venue->slug}/slots?date=".at(20)->toDateString())
        ->assertJsonPath('mine.0.id', $b->id)->assertJsonPath('mine.0.status', 'pending')->assertJsonPath('mine.0.start', 20)->assertJsonPath('mine.0.end', 21);
    $this->actingAs(User::factory()->create())->getJson("/places/{$venue->slug}/slots?date=".at(20)->toDateString())->assertJsonPath('mine', []);
});
