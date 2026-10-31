<?php

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Friendship;
use App\Models\Game;
use App\Models\OpeningHour;
use App\Models\Product;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\AdminSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FootballClubSeeder;
use Database\Seeders\VenueSeeder;

it('seeds again without losing or doubling anything', function () {
    $this->seed(DatabaseSeeder::class);
    $counts = fn () => [Venue::count(), User::count(), Booking::count(), Game::count(), Friendship::count(), Conversation::count()];
    $before = $counts();
    expect($before[0])->toBe(9)->and($before[2])->toBeGreaterThan(0)->and($before[3])->toBeGreaterThan(0);

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

it('makes every ADMIN_PHONE number an admin; on staging each owns their own football place and ping-pong club', function () {
    config(['app.admin_phone' => '+37499000001, 099 000 002, 099000003']);
    $this->seed(DatabaseSeeder::class);
    [$a, $b, $c] = collect(['+37499000001', '+37499000002', '+37499000003'])->map(fn ($p) => User::where('phone', $p)->first())->all();
    expect($a->is_admin)->toBeTrue()->and($b->is_admin)->toBeTrue()->and($c->is_admin)->toBeTrue()
        ->and($a->venues()->pluck('slug')->sort()->values()->all())->toBe(['pingpong-land', 'zangak'])
        ->and($b->venues()->pluck('slug')->sort()->values()->all())->toBe(['masis', 'spin-club'])
        // a third admin gets places too (the next football place and a club of their own)
        ->and($c->venues()->pluck('slug')->sort()->values()->all())->toBe(['davtashen', 'smash-club']);
    expect(Venue::where('slug', 'spin-club')->first()->fields()->count())->toBe(2);
});

it('reads ADMIN_PHONE with commas, semicolons or new lines between the numbers', function () {
    config(['app.admin_phone' => "+374 99 000 001;099 000 002\n+37499000003, "]);
    expect(AdminSeeder::phones())->toBe(['+37499000001', '+37499000002', '+37499000003']);
});

it('gives football places the club tools by default (no cards, rating or tournaments yet) and a shop to try', function () {
    $this->seed([VenueSeeder::class, FootballClubSeeder::class]);
    foreach (FootballClubSeeder::PLACES as $slug) {
        $v = Venue::where('slug', $slug)->first();
        expect($v->featureList())->toContain('desk', 'shop', 'trainings', 'money')->not->toContain('wallet', 'rating', 'tournaments')
            ->and(Product::where('venue_id', $v->id)->count())->toBe(4)
            ->and($v->trainingPrice('small'))->toBe(12000);
    }
    expect(Venue::where('slug', 'masis')->first()->lengthPrices('small'))->toHaveCount(3);
    // again: nothing doubled
    $this->seed(FootballClubSeeder::class);
    expect(Product::count())->toBe(4 * count(FootballClubSeeder::PLACES));
});
