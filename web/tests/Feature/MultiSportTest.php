<?php

use App\Domain\Booking\Actions\DecideBooking;
use App\Domain\Booking\Actions\RequestBooking;
use App\Domain\Booking\AfterBooking\BookingTeam;
use App\Domain\Booking\BookingException;
use App\Domain\Venues\PriceCalculator;
use App\Enums\Sport;
use App\Models\Booking;
use App\Models\Field;
use App\Models\PriceRule;
use App\Models\SportSetting;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonInterface;
use Inertia\Testing\AssertableInertia as Assert;

// ---------- any free table ----------

it('books any free table: three players at 18:00 get three tables; a fourth competes for one, once taken it is taken', function () {
    $club = makeClub(3);
    $first = $club->fields->first();
    $owner = User::factory()->create();
    $reqs = collect(range(1, 3))->map(fn () => app(RequestBooking::class)->handle(User::factory()->create(), $first, at(18), 60));
    expect($reqs->pluck('field_id')->unique())->toHaveCount(3);
    // every table only asked for: a fourth player may still ask (the place picks)
    $fourth = app(RequestBooking::class)->handle(User::factory()->create(), $first, at(18), 60);
    expect(Booking::where('venue_id', $club->id)->count())->toBe(4);
    // all three tables given: now it is taken
    $reqs->each(fn ($b) => app(DecideBooking::class)->accept($b->fresh(), $owner));
    expect($fourth->fresh()->status->value)->toBe('declined')
        ->and(fn () => app(RequestBooking::class)->handle(User::factory()->create(), $first, at(18), 60))->toThrow(BookingException::class);
});

it('shows a time as free while at least one table is free, with how many are left', function () {
    $club = makeClub(3);
    $first = $club->fields->first();
    // two tables given (accepted), one still free
    foreach ([1, 2] as $_) {
        app(DecideBooking::class)->accept(app(RequestBooking::class)->handle(User::factory()->create(), $first, at(18), 60), User::factory()->create());
    }
    $date = at(0)->toDateString();

    $row = $this->getJson("/places/{$club->slug}/slots?date={$date}")->assertOk()->json('fields');
    expect($row)->toHaveCount(1)                          // the tables are one choice
        ->and($row[0]['count'])->toBe(3)
        ->and($row[0]['starts']['60'])->toContain(18)     // one table is still free at 18:00
        ->and($row[0]['left']['60']['18'])->toBe(1);

    app(DecideBooking::class)->accept(app(RequestBooking::class)->handle(User::factory()->create(), $first, at(18), 60), User::factory()->create());
    $row = $this->getJson("/places/{$club->slug}/slots?date={$date}")->json('fields.0');
    expect($row['starts']['60'])->not->toContain(18)->and($row['taken']['60'])->toContain(18);
});

it('keeps football as it was: one field per kind, booked by its own id', function () {
    $venue = makeVenue();
    $small = $venue->fields->firstWhere('size.value', 'small');
    $b = app(RequestBooking::class)->handle(User::factory()->create(), $small, at(20), 60);
    app(DecideBooking::class)->accept($b, User::factory()->create());
    expect($b->field_id)->toBe($small->id)
        ->and(fn () => app(RequestBooking::class)->handle(User::factory()->create(), $small, at(20), 60))->toThrow(BookingException::class);
});

// ---------- home: the sport first, shown places only ----------

it('asks "what do you play?" first, then shows the places of that sport; only sports the admin shows', function () {
    $club = makeClub();
    $venue = makeVenue();
    $this->get('/')->assertInertia(fn (Assert $p) => $p->where('pick', true)
        ->where('sports', fn ($s) => collect($s)->pluck('key')->all() === ['football', 'pingpong']));
    $this->get('/?sport=pingpong')->assertInertia(fn (Assert $p) => $p->where('pick', false)
        ->where('filters.sport', 'pingpong')
        ->where('venues', fn ($v) => collect($v)->pluck('id')->all() === [$club->id])
        ->where('venues.0.fields.0.count', 3)
        ->where('openGames', 0));
    $this->get('/?sport=football')->assertInertia(fn (Assert $p) => $p->where('filters.sport', 'football')
        ->where('venues', fn ($v) => collect($v)->pluck('id')->all() === [$venue->id])->where('openGames', 0));

    // one sport shown: no question, straight to it
    SportSetting::find('football')->update(['visible' => false]);
    $this->get('/')->assertInertia(fn (Assert $p) => $p->where('pick', false)->where('filters.sport', 'pingpong'));
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

it('shows the tables as one column with how many are taken, and books any free table for the owner', function () {
    $club = makeClub(3);
    $owner = User::factory()->create();
    $club->staff()->attach($owner->id, ['role' => 'owner']);
    $first = $club->fields->first();
    // two tables given (accepted), one still free
    foreach ([1, 2] as $_) {
        app(DecideBooking::class)->accept(app(RequestBooking::class)->handle(User::factory()->create(), $first, at(18), 60), User::factory()->create());
    }
    $date = at(0)->toDateString();
    $this->actingAs($owner)->get("/owner/schedule?date={$date}")->assertInertia(fn (Assert $p) => $p
        ->has('day.lanes', 1)->where('day.lanes.0.count', 3)
        // which tables are taken, not only how many
        ->where("day.load.{$first->id}", fn ($segs) => collect($segs)->contains(fn ($s) => $s[0] == 18 && $s[1] == 19 && $s[2] === 2 && count($s[3]) === 2)));
    $q = ['field_id' => $first->id, 'date' => $date, 'start' => '18:00', 'minutes' => 60, 'any' => 1];
    $this->actingAs($owner)->getJson('/owner/bookings/quote?'.http_build_query($q))->assertJson(['problem' => null])->assertJsonPath('starts', fn ($s) => in_array(18, $s));
    $this->actingAs($owner)->post('/owner/bookings', $q + ['name' => 'Walk-in'])->assertRedirect()->assertSessionHasNoErrors();
    expect(Booking::where('venue_id', $club->id)->pluck('field_id')->unique())->toHaveCount(3);
    // full now: the fourth one is refused
    $this->actingAs($owner)->post('/owner/bookings', $q + ['name' => 'Late'])->assertSessionHasErrors('booking');
});

it('books in quarter hours where the place starts every 15 minutes', function () {
    $club = makeClub(1, ['rules' => ['min_notice_min' => 0, 'step_min' => 15]]);
    $first = $club->fields->first();
    $b = app(RequestBooking::class)->handle(User::factory()->create(), $first, at(18.25), 30);
    expect($b->price)->toBe(1500)
        ->and($b->starts_at->setTimezone('Asia/Yerevan')->format('H:i'))->toBe('18:15');
});

it('prices tables by length: 15 min 1 200, 30 min 2 000, 1 h 5 000; longer games are made of them', function () {
    $club = makeClub(2, ['rules' => ['min_notice_min' => 0, 'step_min' => 15]]);
    $owner = User::factory()->create();
    $club->staff()->attach($owner->id, ['role' => 'owner']);
    $first = $club->fields->first();
    $this->actingAs($owner)->put('/owner/settings', ['prices' => [['field_id' => $first->id, 'day' => 3000, 'evening' => 4000, 'weekend' => 4000, 'training' => 6000,
        'lengths' => [['min' => 60, 'day' => 5000, 'evening' => 6000], ['min' => 15, 'day' => 1200], ['min' => 30, 'day' => 2000]]]]])
        ->assertSessionHasNoErrors();
    $club->refresh();
    expect($club->lengthPrices('table'))->toBe([
        ['min' => 15, 'day' => 1200, 'evening' => null, 'weekend' => null], ['min' => 30, 'day' => 2000, 'evening' => null, 'weekend' => null],
        ['min' => 60, 'day' => 5000, 'evening' => 6000, 'weekend' => null]])
        // the priced lengths are the lengths players book, on every table
        ->and(Field::where('venue_id', $club->id)->pluck('durations')->unique()->values()->all())->toBe([[15, 30, 60]]);
    // the settings page shows them back (and the training price; tables have no surface)
    $this->actingAs($owner)->get('/owner/settings')->assertInertia(fn (Assert $p) => $p->where('prices.0.lengths.2.evening', 6000)
        ->where('prices.0.training', 6000)->where('prices.0.surface', null)->where('prices.0.sport', 'pingpong'));

    $prices = app(PriceCalculator::class);
    $t = Field::find($first->id);
    $weekday = LocalTime::now()->next(CarbonInterface::WEDNESDAY);
    $at = fn ($h) => LocalTime::at($weekday, $h);
    expect($prices->price($t, $at(12), 15))->toBe(1200)
        ->and($prices->price($t, $at(12), 60))->toBe(5000)
        ->and($prices->price($t, $at(12), 75))->toBe(6200)         // 1 h + 15 min
        ->and($prices->price($t, $at(12), 50))->toBe(2000 + 1200 + 1200)   // 30 + 15 + the rest as 15
        ->and($prices->price($t, $at(19), 60))->toBe(6000)         // evening price
        ->and($prices->price($t, $at(19), 15))->toBe(1200)         // no evening price: the day one
        ->and($prices->price($t, LocalTime::at($weekday->next(CarbonInterface::SATURDAY), 12), 60))->toBe(5000);

    // a player's booking and the place page use the same prices (seen as a player: the owner stays in the panel)
    $this->actingAs(User::factory()->create());
    $b = app(RequestBooking::class)->handle(User::factory()->create(), $t, $at(12), 30);
    expect($b->price)->toBe(2000);
    $this->getJson("/places/{$club->slug}/slots?date={$weekday->toDateString()}")->assertOk()
        ->assertJsonPath('fields.0.costs.60.19', 6000)->assertJsonPath('fields.0.costs.15.12', 1200);
    $this->get("/places/{$club->slug}")->assertInertia(fn (Assert $p) => $p->where('fields.0.lengths.0.min', 15));

    // back to per hour
    $this->actingAs($owner)->put('/owner/settings', ['prices' => [['field_id' => $first->id, 'day' => 3000, 'evening' => 4000, 'weekend' => 4000, 'lengths' => []]]]);
    expect($club->fresh()->lengthPrices('table'))->toBe([])->and($prices->price(Field::find($first->id), $at(12), 60))->toBe(3000);
});
