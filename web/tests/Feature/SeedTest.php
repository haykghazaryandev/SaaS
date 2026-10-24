<?php

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\Game;
use App\Models\OpeningHour;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\DatabaseSeeder;

it('seeds again without losing or doubling anything', function () {
    $this->seed(DatabaseSeeder::class);
    $counts = fn () => [Venue::count(), User::count(), Booking::count(), Game::count(), Friendship::count(), Conversation::count()];
    $before = $counts();
    expect($before[0])->toBe(8)->and($before[2])->toBeGreaterThan(0)->and($before[3])->toBeGreaterThan(0);

    // an owner changed hours, a player renamed themselves
    $hour = Venue::where('slug', 'zangak')->first()->openingHours()->first();
    $hour->update(['opens_at' => '10:00']);
    User::where('phone', '+37493000100')->update(['name' => 'Renamed Player']);
    $booking = Booking::first();

    $this->seed(DatabaseSeeder::class);

    expect($counts())->toBe($before)
        ->and(OpeningHour::find($hour->id)->opens_at)->toStartWith('10:00')
        ->and(User::where('phone', '+37493000100')->value('name'))->toBe('Renamed Player')
        ->and(Booking::find($booking->id))->not->toBeNull();
});

it('adds a missing place without touching the others', function () {
    $this->seed(DatabaseSeeder::class);
    Venue::where('slug', 'malatia')->forceDelete();

    $this->seed(DatabaseSeeder::class);

    expect(Venue::where('slug', 'malatia')->first()->fields()->count())->toBe(2);
});

it('seeds only the admin in production', function () {
    app()->detectEnvironment(fn () => 'production');

    app(DatabaseSeeder::class)->setContainer(app())->__invoke();   // db:seed itself asks "are you sure?" in production

    expect(User::count())->toBe(1)
        ->and(User::first()->is_admin)->toBeTrue()
        ->and(Venue::count())->toBe(0);
});
