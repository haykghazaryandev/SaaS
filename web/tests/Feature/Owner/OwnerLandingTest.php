<?php

use App\Models\User;
use App\Models\Venue;

it('sends an owner to the dashboard instead of the player app, unless they open the player site', function () {
    $club = makeClub(2, ['features' => [...Venue::DEFAULT_FEATURES, 'wallet']]);
    $field = makeVenue();
    $owner = User::factory()->create();
    $club->staff()->attach($owner->id, ['role' => 'owner']);

    // a club lands at its desk
    $this->actingAs($owner)->get('/')->assertRedirect(route('owner.start'));
    $this->actingAs($owner)->get('/owner/start')->assertRedirect(route('owner.now'));
    // people who run a place stay in their panel: no player pages (Messages and Profile stay open)
    $this->actingAs($owner)->get('/?as=player')->assertRedirect(route('owner.start'));
    $this->actingAs($owner)->get('/places/'.$club->slug)->assertRedirect(route('owner.start'));
    $this->actingAs($owner)->get('/me/bookings')->assertRedirect(route('owner.start'));
    $this->actingAs($owner)->getJson('/places/'.$club->slug.'/slots')->assertForbidden();
    $this->actingAs($owner)->get('/messages')->assertOk();
    $this->actingAs($owner)->get('/me/profile')->assertOk();

    // a football place lands at Today; players are not redirected
    $other = User::factory()->create();
    $field->staff()->attach($other->id, ['role' => 'owner']);
    $this->actingAs($other)->get('/owner/start')->assertRedirect(route('owner.today'));
    $this->actingAs(User::factory()->create())->get('/')->assertOk();

    // an admin who also runs a place: the same (the admin panel stays open)
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();
    $club->staff()->attach($admin->id, ['role' => 'owner']);
    $this->actingAs($admin)->get('/')->assertRedirect(route('owner.start'));
});
