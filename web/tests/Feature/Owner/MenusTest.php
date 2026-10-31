<?php

use App\Domain\Booking\Actions\OwnerBooking;
use App\Domain\Venues\Menus;
use App\Models\Field;
use App\Models\User;
use App\Models\Venue;
use App\Support\LocalTime;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->club = makeVenue();
    $this->club->update(['features' => [...Venue::DEFAULT_FEATURES, 'desk', 'wallet', 'rating', 'trainings', 'shop', 'tournaments', 'money']]);
    // a table too: tournaments and ratings are ping-pong things (football has neither yet)
    Field::create(['venue_id' => $this->club->id, 'size' => 'table', 'format' => '🏓', 'max_players' => 4, 'durations' => [60], 'default_duration' => 60, 'sort' => 9]);
    $this->club->load('fields');
    $this->owner = User::factory()->create();
    $this->club->staff()->attach($this->owner->id, ['role' => 'owner']);
    $this->staff = User::factory()->create();
    $this->club->staff()->attach($this->staff->id, ['role' => 'staff']);
    $this->menus = fn (array $change) => array_replace_recursive(Menus::of($this->club->fresh()), $change);
});

it('starts like before: the owner sees everything, staff the daily work without money', function () {
    $m = Menus::of($this->club);
    expect(collect($m['owner'])->every(fn ($on) => $on))->toBeTrue()
        ->and($m['staff']['money'])->toBeFalse()->and($m['staff']['now'])->toBeTrue()
        ->and($m['level'])->toBe(['schedule' => 'view', 'shop' => 'sell', 'tournaments' => 'run', 'money' => 'today']);
    $this->actingAs($this->staff)->get('/owner/schedule')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('owner.role', 'staff')->where('owner.menus.staff.money', false)->where('owner.menus.available', Menus::KEYS));
    $this->actingAs($this->staff)->get('/owner/money')->assertRedirect(route('owner.start'));
    $this->actingAs($this->staff)->post('/owner/shop', ['name' => 'Cola', 'price' => 500])->assertForbidden();
});

it('lets the owner save the menus, owner only, and keeps at least one staff menu', function () {
    $this->actingAs($this->staff)->put(route('owner.settings.menus'), ($this->menus)([]))->assertForbidden();
    $this->actingAs($this->owner)->put(route('owner.settings.menus'), ($this->menus)(['staff' => array_fill_keys(Menus::KEYS, false)]))
        ->assertSessionHasErrors('staff');
    $this->actingAs($this->owner)->put(route('owner.settings.menus'), ($this->menus)(['level' => ['money' => 'everything']]))
        ->assertSessionHasErrors('level.money');
    $this->actingAs($this->owner)->put(route('owner.settings.menus'), ($this->menus)(['owner' => ['now' => false], 'staff' => ['money' => true]]))
        ->assertSessionHasNoErrors();
    expect(Menus::of($this->club->fresh()))->owner->now->toBeFalse()->staff->money->toBeTrue();
});

it('closes a menu turned off for staff on the server, not only in the menu', function () {
    $this->club->update(['menus' => ($this->menus)(['staff' => ['now' => false, 'cards' => false, 'requests' => false]])]);
    $this->actingAs($this->staff)->get('/owner/now')->assertRedirect(route('owner.schedule'));
    $this->actingAs($this->staff)->get('/owner/cards')->assertRedirect();
    $this->actingAs($this->staff)->get('/owner/requests')->assertRedirect();
    $this->actingAs($this->staff)->postJson('/owner/cards', [])->assertForbidden();
    // staff land on their first menu; the owner still gets in everywhere
    $this->actingAs($this->staff)->get('/owner/start')->assertRedirect(route('owner.schedule'));
    $this->actingAs($this->owner)->get('/owner/now')->assertOk();
    $this->actingAs($this->owner)->get('/owner/start')->assertRedirect(route('owner.now'));
    // the owner hid Now from their own panel: they land on the next menu
    $this->club->update(['menus' => ($this->menus)(['owner' => ['now' => false]])]);
    $this->actingAs($this->owner)->get('/owner/start')->assertRedirect(route('owner.schedule'));
});

it('gives staff more with the levels: changing bookings, shop items, tournaments, all the money', function () {
    $field = $this->club->fields->first();
    $b = app(OwnerBooking::class)->create($this->owner, $field, LocalTime::at(LocalTime::now()->startOfDay()->addDays(2), 18), 60, ['name' => 'Team A']);
    $move = ['field_id' => $field->id, 'date' => LocalTime::now()->addDays(2)->toDateString(), 'start' => '19:00', 'minutes' => 60, 'name' => 'Team A'];
    $this->actingAs($this->staff)->patch(route('owner.bookings.update', $b), $move)->assertForbidden();

    $this->club->update(['menus' => ($this->menus)(['staff' => ['money' => true]])]);
    // money "today": only today, whatever period is asked
    $this->actingAs($this->staff)->get('/owner/money?period=month')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('period', 'today')->where('todayOnly', true));
    $this->actingAs($this->owner)->get('/owner/money?period=month')->assertInertia(fn (Assert $p) => $p->where('period', 'month')->where('todayOnly', false));

    $this->club->update(['menus' => ($this->menus)(['staff' => ['money' => true], 'level' => ['schedule' => 'edit', 'shop' => 'edit', 'tournaments' => 'create', 'money' => 'all']])]);
    $this->actingAs($this->staff)->from('/owner/schedule')->patch(route('owner.bookings.update', $b), $move)->assertSessionHasNoErrors();
    expect($b->fresh()->starts_at->setTimezone(LocalTime::tz())->format('H:i'))->toBe('19:00');
    $this->actingAs($this->staff)->get('/owner/money?period=month')->assertInertia(fn (Assert $p) => $p->where('period', 'month'));
    $this->actingAs($this->staff)->get('/owner/tournaments/new')->assertOk();
    $this->actingAs($this->staff)->from('/owner/shop')->post('/owner/shop', ['name' => 'Cola', 'price' => 500, 'in_stock' => true, 'active' => true])->assertSessionHasNoErrors();
});

it('has no "as staff" view any more', function () {
    $this->actingAs($this->owner)->get(route('switch', ['to' => 'staff']))->assertSessionHasErrors('to');
    $this->actingAs($this->owner)->get('/owner/schedule')->assertInertia(fn (Assert $p) => $p->where('owner.role', 'owner')->missing('owner.realRole'));
});
