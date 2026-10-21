<?php

use App\Domain\Booking\Actions\DecideBooking;
use App\Domain\Booking\Actions\RequestBooking;
use App\Models\User;

it('tells the player in the app (bell) when the place answers', function () {
    $venue = makeVenue();
    $field = $venue->fields->firstWhere('size.value', 'small');
    $player = User::factory()->create();
    $b = app(RequestBooking::class)->handle($player, $field, at(20), 60);
    expect($player->notifications()->count())->toBe(0);   // "request sent" is not a notice

    app(DecideBooking::class)->accept($b, User::factory()->create());
    $n = $player->notifications()->first();
    expect($n->data['type'])->toBe('booking_accepted')
        ->and($n->data['link'])->toBe('/me/bookings')
        ->and($n->data['params']['code'])->toBe($b->code)
        ->and(__('notice.booking_accepted', $n->data['params']))->toContain($b->code);
});

it('tells the player when the place declines, with the reason', function () {
    $venue = makeVenue();
    $field = $venue->fields->firstWhere('size.value', 'small');
    $player = User::factory()->create();
    $b = app(RequestBooking::class)->handle($player, $field, at(20), 60);
    app(DecideBooking::class)->decline($b, User::factory()->create(), 'Pitch repairs');
    $n = $player->notifications()->first();
    expect($n->data['type'])->toBe('booking_declined')->and($n->data['params']['reason'])->toBe('Pitch repairs');
});
