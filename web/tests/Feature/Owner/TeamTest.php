<?php

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\OwnerBooking;
use App\Domain\Booking\BookingException;
use App\Domain\Venues\Team;
use App\Models\User;
use App\Notifications\Notice;
use App\Support\LocalTime;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->venue = makeVenue();
    $this->owner = User::factory()->create(['phone' => '+37499111111']);
    $this->venue->staff()->attach($this->owner->id, ['role' => 'owner']);
});

it('lets the one owner add staff by name and phone and take them off; staff get the staff panel only', function () {
    Notification::fake();
    $this->actingAs($this->owner)->post(route('owner.team.store'), ['phone' => '093 55 44 33', 'name' => 'Ani'])->assertSessionHasNoErrors();
    $ani = User::where('phone', '+37493554433')->firstOrFail();
    expect($ani->name)->toBe('Ani')->and($this->venue->staff()->whereKey($ani->id)->first()->pivot->role)->toBe('staff');
    Notification::assertSentTo($ani, Notice::class, fn ($n) => $n->type === 'team_added');
    $this->actingAs($this->owner)->get(route('owner.team'))->assertInertia(fn (Assert $p) => $p->component('owner/Team')->has('people', 2));
    // a name is needed; nobody becomes an owner from here; the owner can't be added or taken off
    $this->actingAs($this->owner)->post(route('owner.team.store'), ['phone' => '093 11 22 33'])->assertSessionHasErrors('name');
    $team = app(Team::class);
    expect(fn () => $team->add($this->venue, '+37499111111', 'Me', $this->owner))->toThrow(BookingException::class)
        ->and(fn () => $team->remove($this->venue, $this->owner, $this->owner))->toThrow(BookingException::class);

    // Ani logs in: the staff panel only (no owner / staff toggle for her, no settings)
    $this->actingAs($ani)->withSession(['venue_id' => $this->venue->id])->get(route('owner.schedule'))
        ->assertInertia(fn (Assert $p) => $p->where('owner.role', 'staff')->missing('kpi.revenueToday'));
    $this->actingAs($ani)->get(route('switch', ['to' => 'owner']));
    $this->actingAs($ani)->get(route('owner.settings'))->assertRedirect(route('owner.start'));
    $this->actingAs($ani)->post(route('owner.team.store'), ['phone' => '+37493000999', 'name' => 'X'])->assertForbidden();

    $this->actingAs($this->owner)->delete(route('owner.team.destroy', $ani->id))->assertSessionHasNoErrors();
    expect($this->venue->staff()->whereKey($ani->id)->exists())->toBeFalse();
});

it('switches between the place panel, the player app and the admin panel', function () {
    // an owner stays in the panel: the player pages send them back
    $this->actingAs($this->owner)->get('/')->assertRedirect(route('owner.start'));
    // the player app, on purpose
    $this->actingAs($this->owner)->get(route('switch', ['to' => 'player']))->assertRedirect(route('home'));
    $this->actingAs($this->owner)->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->where('auth.user.playerMode', true));
    // the panel again: player mode off
    $this->actingAs($this->owner)->get(route('switch', ['to' => 'owner']))->assertRedirect(route('owner.start'));
    $this->actingAs($this->owner)->get('/')->assertRedirect(route('owner.start'));

    // no "as staff" any more: the owner sets what staff see in Settings → Menus
    $this->actingAs($this->owner)->get(route('switch', ['to' => 'staff']))->assertSessionHasErrors('to');

    // the admin panel: admins only
    $this->actingAs($this->owner)->get(route('switch', ['to' => 'admin']))->assertForbidden();
    $this->owner->forceFill(['is_admin' => true])->save();
    $this->actingAs($this->owner->fresh())->get(route('switch', ['to' => 'admin']))->assertRedirect('/admin');
});

it('opens the schedule on today by default; a date opens that day, without declined ones', function () {
    $field = $this->venue->fields->first();
    $at = LocalTime::at(LocalTime::now()->startOfDay()->addDay(), 18);
    $ok = app(OwnerBooking::class)->create($this->owner, $field, $at, 60, ['name' => 'Tomorrow team']);
    $gone = app(OwnerBooking::class)->create($this->owner, $field, $at->addHours(2), 60, ['name' => 'Cancelled']);
    app(CancelBooking::class)->handle($gone, $this->owner, 'owner');
    // today: only today's (tomorrow is on the day strip: 1 booking)
    $this->actingAs($this->owner)->get(route('owner.schedule'))->assertInertia(fn (Assert $p) => $p->where('view', 'now')
        ->has('upcoming.rows', 0)->has('upcoming.hours')->where('days.1.count', 1));
    $this->actingAs($this->owner)->get(route('owner.schedule', ['date' => $at->toDateString()]))
        ->assertInertia(fn (Assert $p) => $p->where('view', 'day')->has('day.rows', 1));
});

it('adds the test requests again outside production (they expire unanswered)', function () {
    $this->actingAs($this->owner)->post(route('owner.requests.demo'))->assertRedirect();
    $this->app['env'] = 'production';
    $this->withoutMiddleware(ValidateCsrfToken::class)
        ->actingAs($this->owner)->post(route('owner.requests.demo'))->assertNotFound();
});
