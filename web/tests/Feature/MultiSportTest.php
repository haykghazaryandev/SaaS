<?php

use App\Domain\Booking\Actions\RequestBooking;
use App\Domain\Booking\AfterBooking\BookingTeam;
use App\Domain\Booking\BookingException;
use App\Domain\Venues\Courts;
use App\Enums\FieldSize;
use App\Enums\Sport;
use App\Models\Booking;
use App\Models\Field;
use App\Models\OpeningHour;
use App\Models\PriceRule;
use App\Models\SportSetting;
use App\Models\User;
use App\Models\Venue;
use Inertia\Testing\AssertableInertia as Assert;

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

// ---------- any free table ----------

it('books any free table: three players at 18:00 get three tables, the fourth is told it is taken', function () {
    $club = makeClub(3);
    $first = $club->fields->first();
    $tables = collect(range(1, 3))->map(fn () => app(RequestBooking::class)->handle(User::factory()->create(), $first, at(18), 60)->field_id);
    expect($tables->unique())->toHaveCount(3)
        ->and(fn () => app(RequestBooking::class)->handle(User::factory()->create(), $first, at(18), 60))->toThrow(BookingException::class);
    expect(Booking::where('venue_id', $club->id)->count())->toBe(3);
});

it('shows a time as free while at least one table is free, with how many are left', function () {
    $club = makeClub(3);
    $first = $club->fields->first();
    app(RequestBooking::class)->handle(User::factory()->create(), $first, at(18), 60);
    app(RequestBooking::class)->handle(User::factory()->create(), $first, at(18), 60);
    $date = at(0)->toDateString();

    $row = $this->getJson("/places/{$club->slug}/slots?date={$date}")->assertOk()->json('fields');
    expect($row)->toHaveCount(1)                          // the tables are one choice
        ->and($row[0]['count'])->toBe(3)
        ->and($row[0]['starts']['60'])->toContain(18)     // one table is still free at 18:00
        ->and($row[0]['left']['60']['18'])->toBe(1);

    app(RequestBooking::class)->handle(User::factory()->create(), $first, at(18), 60);
    $row = $this->getJson("/places/{$club->slug}/slots?date={$date}")->json('fields.0');
    expect($row['starts']['60'])->not->toContain(18)->and($row['taken']['60'])->toContain(18);
});

it('keeps football as it was: one field per kind, booked by its own id', function () {
    $venue = makeVenue();
    $small = $venue->fields->firstWhere('size.value', 'small');
    $b = app(RequestBooking::class)->handle(User::factory()->create(), $small, at(20), 60);
    expect($b->field_id)->toBe($small->id)
        ->and(fn () => app(RequestBooking::class)->handle(User::factory()->create(), $small, at(20), 60))->toThrow(BookingException::class);
});

// ---------- home: the sport first, shown places only ----------

it('shows the places of the chosen sport, and only sports the admin shows', function () {
    $club = makeClub();
    $venue = makeVenue();
    $this->get('/?sport=pingpong')->assertInertia(fn (Assert $p) => $p
        ->where('filters.sport', 'pingpong')
        ->where('venues', fn ($v) => collect($v)->pluck('id')->all() === [$club->id])
        ->where('venues.0.fields.0.count', 3)
        ->where('sports', fn ($s) => collect($s)->pluck('key')->all() === ['football', 'pingpong']));
    $this->get('/')->assertInertia(fn (Assert $p) => $p->where('filters.sport', 'football')
        ->where('venues', fn ($v) => collect($v)->pluck('id')->all() === [$venue->id]));

    SportSetting::find('football')->update(['visible' => false]);
    $this->get('/')->assertInertia(fn (Assert $p) => $p->where('filters.sport', 'pingpong')
        ->where('sports', fn ($s) => collect($s)->pluck('key')->all() === ['pingpong']));
    expect(Sport::visible())->toBe([Sport::Pingpong]);
});

it('hides an unlisted place from the home page, but it still opens by its link', function () {
    $club = makeClub(2, ['listed' => false]);
    $this->get('/?sport=pingpong')->assertInertia(fn (Assert $p) => $p->where('venues', []));
    $this->get("/places/{$club->slug}")->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('sport', 'pingpong')->has('fields', 1)->where('fields.0.count', 2));
});

// ---------- features per place ----------

it('switches features off per place: no booking, no team, no "notify me"', function () {
    $club = makeClub(2, ['features' => ['alerts']]);
    $first = $club->fields->first();
    expect(fn () => app(RequestBooking::class)->handle(User::factory()->create(), $first, at(18), 60))->toThrow(BookingException::class, __('booking.error.feature_off'));
    $this->get("/places/{$club->slug}")->assertInertia(fn (Assert $p) => $p->where('features', ['alerts']));

    $club->update(['features' => ['booking']]);
    $booker = User::factory()->create();
    $b = app(RequestBooking::class)->handle($booker, $first->fresh(), at(18), 60);
    expect(fn () => app(BookingTeam::class)->join($b, User::factory()->create()))->toThrow(BookingException::class, __('booking.error.feature_off'));
    // new features start off for places that never set theirs; the current ones are on
    expect(makeVenue()->hasFeature('games'))->toBeTrue()->and(makeVenue()->hasFeature('wallet'))->toBeFalse();
});

// ---------- the owner manages tables and prices ----------

it('lets the owner add, rename, switch off and remove tables', function () {
    $club = makeClub(2);
    $owner = User::factory()->create();
    $staff = User::factory()->create();
    $club->staff()->attach($owner->id, ['role' => 'owner']);
    $club->staff()->attach($staff->id, ['role' => 'staff']);

    $this->actingAs($staff)->post('/owner/courts', ['size' => 'table'])->assertForbidden();
    $this->actingAs($owner)->post('/owner/courts', ['size' => 'table'])->assertRedirect();
    $third = Field::where('venue_id', $club->id)->latest('id')->first();
    expect($club->fields()->count())->toBe(3)->and($third->name)->toEndWith(' 3')
        ->and($third->priceRules()->value('price_per_hour'))->toBe(3000);   // copies the first table's prices
    $this->actingAs($owner)->post('/owner/courts', ['size' => 'big'])->assertSessionHasErrors('size');   // new kinds: admin only

    $this->actingAs($owner)->patch("/owner/courts/{$third->id}", ['name' => 'Window table'])->assertRedirect();
    expect($third->fresh()->name)->toBe('Window table');

    // a table with an upcoming booking can't go; a never-booked one is deleted; one with history is switched off
    $b = app(RequestBooking::class)->handle(User::factory()->create(), $third->fresh(), at(18), 60);
    $b->update(['field_id' => $third->id]);
    $this->actingAs($owner)->delete("/owner/courts/{$third->id}")->assertSessionHasErrors('booking');
    $b->update(['status' => 'cancelled']);
    $this->actingAs($owner)->delete("/owner/courts/{$third->id}")->assertRedirect();
    expect($third->fresh()->active)->toBeFalse();
    $second = Field::where('venue_id', $club->id)->orderBy('id')->skip(1)->first();
    $this->actingAs($owner)->delete("/owner/courts/{$second->id}")->assertRedirect();
    expect(Field::find($second->id))->toBeNull();
    // the last table of a kind stays
    $this->actingAs($owner)->patch('/owner/courts/'.$club->fields()->first()->id, ['active' => false])->assertSessionHasErrors('booking');
});

it('sets one price for every table of a kind', function () {
    $club = makeClub(3);
    $owner = User::factory()->create();
    $club->staff()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner)->get('/owner/settings')->assertInertia(fn (Assert $p) => $p->has('prices', 1)->where('prices.0.count', 3)
        ->has('courts', 1)->has('courts.0.items', 3));
    $this->actingAs($owner)->put('/owner/settings/prices', ['prices' => [['field_id' => $club->fields->first()->id, 'day' => 2500, 'evening' => 3500, 'weekend' => 4000]]])->assertRedirect();
    foreach ($club->fields as $t) {
        expect(PriceRule::where('field_id', $t->id)->pluck('price_per_hour')->sort()->values()->all())->toBe([2500, 3500, 4000]);
    }
});
