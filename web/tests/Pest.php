<?php

use App\Domain\Venues\Courts;
use App\Enums\FieldSize;
use App\Models\Field;
use App\Models\OpeningHour;
use App\Models\PriceRule;
use App\Models\Venue;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
| Bookball helpers
*/

/** A place open 08:00–24:00 every day with a small (15 000 / 20 000 ֏) and a big field (40 000 / 50 000 ֏). */
function makeVenue(array $rules = []): Venue
{
    $venue = Venue::create([
        'slug' => 'test-'.uniqid(), 'name' => 'Test Park', 'district' => 'arabkir',
        'address' => ['en' => '1 Test St', 'hy' => 'Թեստ 1', 'ru' => 'Тест 1'], 'amenities' => [], 'rules' => $rules, 'status' => 'active',
    ]);
    foreach (range(0, 6) as $wd) {
        OpeningHour::create(['venue_id' => $venue->id, 'weekday' => $wd, 'opens_at' => '08:00', 'closes_at' => '24:00']);
    }
    foreach ([['small', 15000, 20000, [60, 90, 120], 60], ['big', 40000, 50000, [90, 120], 90]] as $i => [$size, $day, $prime, $durations, $def]) {
        $field = Field::create(['venue_id' => $venue->id, 'size' => $size, 'format' => $size === 'small' ? '5×5' : '11×11',
            'max_players' => $size === 'small' ? 10 : 22, 'durations' => $durations, 'default_duration' => $def, 'sort' => $i]);
        PriceRule::create(['field_id' => $field->id, 'weekdays' => [1, 2, 3, 4, 5], 'from_time' => '00:00', 'to_time' => '18:00', 'price_per_hour' => $day, 'priority' => 1]);
        PriceRule::create(['field_id' => $field->id, 'weekdays' => [1, 2, 3, 4, 5], 'from_time' => '18:00', 'to_time' => '24:00', 'price_per_hour' => $prime, 'priority' => 1]);
        PriceRule::create(['field_id' => $field->id, 'weekdays' => [0, 6], 'from_time' => '00:00', 'to_time' => '24:00', 'price_per_hour' => $prime, 'priority' => 2]);
    }

    return $venue->load('fields', 'openingHours');
}

/** Next Wednesday (a weekday) at a local hour, e.g. at(17.5) = 17:30. */
function at(float $hour, int $plusDays = 0): CarbonImmutable
{
    $wed = LocalTime::now()->startOfDay()->next(CarbonInterface::WEDNESDAY)->addDays($plusDays);

    return LocalTime::at($wed, $hour);
}

/** A ping-pong club with $tables tables (3 000 ֏ on weekdays), open 10:00–23:00. */
function makeClub(int $tables = 3, array $attrs = []): Venue
{
    $venue = Venue::create([
        'slug' => 'club-'.uniqid(), 'name' => 'Spin Club', 'district' => 'kentron',
        'address' => ['en' => '1 Spin St', 'hy' => 'Սպին 1', 'ru' => 'Спин 1'], 'amenities' => [], 'rules' => ['min_notice_min' => 0], 'status' => 'active',
        ...$attrs,
    ]);
    foreach (range(0, 6) as $wd) {
        OpeningHour::create(['venue_id' => $venue->id, 'weekday' => $wd, 'opens_at' => '10:00', 'closes_at' => '23:00']);
    }
    $first = Field::create(['venue_id' => $venue->id, 'size' => 'table', 'format' => '🏓', 'max_players' => 4, 'durations' => [30, 60], 'default_duration' => 60, 'sort' => 0]);
    PriceRule::create(['field_id' => $first->id, 'weekdays' => [0, 1, 2, 3, 4, 5, 6], 'from_time' => '00:00', 'to_time' => '24:00', 'price_per_hour' => 3000, 'priority' => 1]);
    for ($i = 1; $i < $tables; $i++) {
        app(Courts::class)->add($venue, FieldSize::Table);
    }

    return $venue->load('fields', 'openingHours');
}
