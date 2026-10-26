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
    // "Open the player site": the player app, until they go back to the dashboard
    $this->actingAs($owner)->get('/?as=player')->assertOk();
    $this->actingAs($owner)->get('/')->assertOk();
    $this->actingAs($owner)->get('/owner')->assertOk();
    $this->actingAs($owner)->get('/')->assertRedirect(route('owner.start'));

    // a football place lands at Today; players are not redirected
    $other = User::factory()->create();
    $field->staff()->attach($other->id, ['role' => 'owner']);
    $this->actingAs($other)->get('/owner/start')->assertRedirect(route('owner.today'));
    $this->actingAs(User::factory()->create())->get('/')->assertOk();

    // an admin who also owns a place uses the app as a player (panels from Profile)
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();
    $club->staff()->attach($admin->id, ['role' => 'owner']);
    $this->actingAs($admin)->get('/')->assertOk();
});
