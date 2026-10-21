<?php

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\DecideBooking;
use App\Domain\Booking\Actions\OwnerBooking;
use App\Domain\Booking\Actions\RequestBooking;
use App\Models\SlotAlert;
use App\Models\User;

beforeEach(function () {
    $this->venue = makeVenue();
    $this->small = $this->venue->fields->firstWhere('size.value', 'small');
    $this->holder = User::factory()->create();
    $this->waiter = User::factory()->create();
    $this->booking = app(RequestBooking::class)->handle($this->holder, $this->small, at(20), 60);
    $this->alertBody = ['field_id' => $this->small->id, 'date' => at(0)->toDateString(), 'start' => '20:00', 'minutes' => 60];
});

it('lets a player wait for a booked time, and tells them when it frees up', function () {
    $this->actingAs($this->waiter)->post('/alerts', $this->alertBody)->assertSessionHasNoErrors();
    expect(SlotAlert::count())->toBe(1);

    app(CancelBooking::class)->handle($this->booking, $this->holder, 'player');
    $n = $this->waiter->notifications()->first();
    expect($n->data['type'])->toBe('slot_free')
        ->and($n->data['link'])->toContain('/places/'.$this->venue->slug)->toContain('start=20%3A00')
        ->and(SlotAlert::first()->notified_at)->not->toBeNull();
});

it('does not alert for a free time and tells the player to book it', function () {
    $this->actingAs($this->waiter)->post('/alerts', [...$this->alertBody, 'start' => '10:00'])
        ->assertSessionHasErrors(['alert' => __('alerts.error.already_free')]);
});

it('notifies when the place declines or moves the booking', function () {
    $this->actingAs($this->waiter)->post('/alerts', $this->alertBody);
    app(OwnerBooking::class)->update(User::factory()->create(), $this->booking, ['starts_at' => at(22)->toIso8601String()]);
    expect($this->waiter->notifications()->count())->toBe(1);
});

it('does not notify while the time is still taken by another booking', function () {
    $this->actingAs($this->waiter)->post('/alerts', [...$this->alertBody, 'minutes' => 120]);   // 20:00–22:00
    app(OwnerBooking::class)->create(User::factory()->create(), $this->small, at(21), 60, ['name' => 'X']);
    app(DecideBooking::class)->decline($this->booking, User::factory()->create());
    expect($this->waiter->notifications()->count())->toBe(0);   // 21:00 is still taken
});

it('removes the alert when the player books that time', function () {
    $this->actingAs($this->waiter)->post('/alerts', $this->alertBody);
    app(CancelBooking::class)->handle($this->booking, $this->holder, 'player');
    app(RequestBooking::class)->handle($this->waiter, $this->small, at(20), 60);
    expect(SlotAlert::count())->toBe(0);
});

it('lets only the owner of an alert remove it', function () {
    $this->actingAs($this->waiter)->post('/alerts', $this->alertBody);
    $alert = SlotAlert::first();
    $this->actingAs($this->holder)->delete("/alerts/{$alert->id}")->assertForbidden();
    $this->actingAs($this->waiter)->delete("/alerts/{$alert->id}")->assertRedirect();
    expect(SlotAlert::count())->toBe(0);
});
