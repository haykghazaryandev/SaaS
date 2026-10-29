<?php

use App\Domain\Booking\BookingException;
use App\Domain\Venues\Team;
use App\Models\User;
use App\Notifications\Notice;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->venue = makeVenue();
    $this->owner = User::factory()->create(['phone' => '+37499111111']);
    $this->venue->staff()->attach($this->owner->id, ['role' => 'owner']);
});

it('lets the owner add staff by phone, change the role and take them off; a place keeps an owner', function () {
    Notification::fake();
    $this->actingAs($this->owner)->post(route('owner.team.store'), ['phone' => '093 55 44 33', 'name' => 'Ani', 'role' => 'staff'])->assertSessionHasNoErrors();
    $ani = User::where('phone', '+37493554433')->firstOrFail();
    expect($ani->name)->toBe('Ani')->and($this->venue->staff()->whereKey($ani->id)->first()->pivot->role)->toBe('staff');
    Notification::assertSentTo($ani, Notice::class, fn ($n) => $n->type === 'team_added');
    $this->actingAs($this->owner)->get(route('owner.team'))->assertInertia(fn (Assert $p) => $p->component('owner/Team')->has('people', 2));

    // owner, then staff again (another owner is there)
    $this->actingAs($this->owner)->post(route('owner.team.store'), ['phone' => '+374 93 554 433', 'role' => 'owner'])->assertSessionHasNoErrors();
    expect($this->venue->staff()->whereKey($ani->id)->first()->pivot->role)->toBe('owner');
    // nobody takes themselves off; the last owner stays an owner
    $team = app(Team::class);
    expect(fn () => $team->remove($this->venue, $this->owner, $this->owner))->toThrow(BookingException::class);
    $this->actingAs($this->owner)->delete(route('owner.team.destroy', $ani->id))->assertSessionHasNoErrors();
    expect($this->venue->staff()->whereKey($ani->id)->exists())->toBeFalse()
        ->and(fn () => $team->add($this->venue, '+37499111111', 'staff', null, $this->owner))->toThrow(BookingException::class);

    // staff can't manage the team
    $team->add($this->venue, '+37493554433', 'staff', null, $this->owner);
    $this->actingAs($ani)->withSession(['venue_id' => $this->venue->id])->post(route('owner.team.store'), ['phone' => '+37493000999', 'role' => 'owner'])->assertForbidden();
});

it('switches between the place panel, the panel as staff, the player app and the admin panel', function () {
    // an owner stays in the panel: the player pages send them back
    $this->actingAs($this->owner)->get('/')->assertRedirect(route('owner.start'));
    // the player app, on purpose
    $this->actingAs($this->owner)->get(route('switch', ['to' => 'player']))->assertRedirect(route('home'));
    $this->actingAs($this->owner)->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->where('auth.user.playerMode', true));
    // the panel again: player mode off
    $this->actingAs($this->owner)->get(route('switch', ['to' => 'owner']))->assertRedirect(route('owner.start'));
    $this->actingAs($this->owner)->get('/')->assertRedirect(route('owner.start'));

    // as staff: the staff menu and rights, the real role kept for the way back
    $this->actingAs($this->owner)->get(route('switch', ['to' => 'staff']));
    $this->actingAs($this->owner)->get(route('owner.today'))->assertInertia(fn (Assert $p) => $p->where('owner.role', 'staff')->where('owner.realRole', 'owner'));
    $this->actingAs($this->owner)->get(route('owner.settings'))->assertRedirect(route('owner.start'));
    $this->actingAs($this->owner)->get(route('switch', ['to' => 'owner']));
    $this->actingAs($this->owner)->get(route('owner.settings'))->assertOk();

    // the admin panel: admins only
    $this->actingAs($this->owner)->get(route('switch', ['to' => 'admin']))->assertForbidden();
    $this->owner->forceFill(['is_admin' => true])->save();
    $this->actingAs($this->owner->fresh())->get(route('switch', ['to' => 'admin']))->assertRedirect('/admin');
});
