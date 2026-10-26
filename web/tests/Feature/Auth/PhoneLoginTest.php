<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;

it('logs in a new phone with the code and asks for a name', function () {
    $this->post('/login/code', ['phone' => '091 23 45 67'])->assertSessionHasNoErrors();
    $code = Cache::get('dev-otp:+37491234567');
    expect($code)->toHaveLength(4);

    $this->post('/login/verify', ['code' => $code])->assertRedirect('/welcome');
    $this->assertAuthenticated();
    expect(User::where('phone', '+37491234567')->first()->registered_at)->toBeNull();

    $this->post('/welcome', ['name' => 'Aram Petrosyan', 'username' => 'Aram_10'])->assertRedirect('/');
    $user = User::where('phone', '+37491234567')->first();
    expect($user->registered_at)->not->toBeNull()->and($user->username)->toBe('aram_10');
});

it('rejects a wrong code and counts the tries', function () {
    $this->post('/login/code', ['phone' => '+37491234567']);
    $this->post('/login/verify', ['code' => '0000'])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('rejects numbers that are not Armenian mobiles', function () {
    $this->post('/login/code', ['phone' => '12345'])->assertSessionHasErrors('phone');
});

it('limits how many codes one phone can ask for', function () {
    foreach (range(1, 3) as $i) {
        $this->post('/login/code', ['phone' => '+37491234567'])->assertSessionHasNoErrors();
    }
    $this->post('/login/code', ['phone' => '+37491234567'])->assertSessionHasErrors('phone');
});

it('sends owners to the owner app after login', function () {
    $venue = makeVenue();
    $owner = User::factory()->create(['phone' => '+37491000001']);
    $venue->staff()->attach($owner->id, ['role' => 'owner']);
    $this->post('/login/code', ['phone' => '+37491000001']);
    $this->post('/login/verify', ['code' => Cache::get('dev-otp:+37491000001'), 'to' => 'owner'])->assertRedirect('/owner/start');
    $this->get('/owner')->assertOk();
});

it('keeps players out of the owner app', function () {
    $this->actingAs(User::factory()->create())->get('/owner')->assertForbidden();
});
