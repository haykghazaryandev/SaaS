<?php

use App\Domain\Booking\Actions\DecideBooking;
use App\Domain\Booking\Actions\RequestBooking;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

// header / tab bar badges (docs/ux-review G5): game invites and waiting bookings next to the old counters

it('counts unanswered game invites and waiting booking requests', function () {
    $me = User::factory()->create(['name' => 'Aram']);
    $host = User::factory()->create(['name' => 'Davit']);
    $game = fn (string $status) => Game::create(['creator_id' => $host->id, 'players_per_side' => 5, 'total_players' => 10, 'min_to_open' => 3, 'status' => $status]);
    foreach (['forming' => 'invited', 'open' => 'invited', 'cancelled' => 'invited', 'full' => 'accepted'] as $status => $mine) {
        GamePlayer::create(['game_id' => $game($status)->id, 'user_id' => $me->id, 'status' => $mine, 'invited_by' => $host->id]);
    }

    $venue = makeVenue();
    [$small, $big] = [$venue->fields[0], $venue->fields[1]];
    $accepted = app(RequestBooking::class)->handle($me, $small, at(10), 60);
    app(DecideBooking::class)->accept($accepted, User::factory()->create());   // accepted: not waiting
    app(RequestBooking::class)->handle($me, $small, at(20), 60);
    app(RequestBooking::class)->handle($me, $big, at(20), 90);

    $this->actingAs($me)->get('/')->assertInertia(fn (Assert $p) => $p
        ->where('counts.invites', 2)->where('counts.pendingBookings', 2)
        ->has('counts.notifications')->has('counts.messages')->has('counts.friendRequests')
    );
    $this->getJson('/notifications')->assertOk()->assertJsonPath('counts.invites', 2)->assertJsonPath('counts.pendingBookings', 2);
});
