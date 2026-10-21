<?php

use App\Domain\Booking\Actions\RequestBooking;
use App\Enums\BookingStatus;
use App\Filament\Pages\OwnerAccount;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Venues\Pages\EditVenue;
use App\Filament\Resources\Venues\RelationManagers\PriceRulesRelationManager;
use App\Filament\Resources\Venues\RelationManagers\StaffRelationManager;
use App\Models\PriceRule;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create(['phone' => '+37499000000', 'name' => 'Admin']);
    $this->admin->forceFill(['is_admin' => true])->save();
    $this->venue = makeVenue();
});

it('sends guests to the phone login', function () {
    $this->get('/admin')->assertRedirect(route('login'));
});

it('does not let players or owners in', function () {
    $owner = User::factory()->create();
    $this->venue->staff()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    $this->actingAs($owner)->get('/admin')->assertForbidden();
});

it('opens every admin page for the admin', function () {
    $this->actingAs($this->admin);
    foreach (['/admin', '/admin/venues', '/admin/venues/create', '/admin/venues/'.$this->venue->slug.'/edit', '/admin/users',
        '/admin/users/'.$this->admin->id, '/admin/bookings', '/admin/notification-logs', '/admin/owner-account'] as $url) {
        $this->get($url)->assertOk();
    }
});

it('works with the session from the phone login', function () {
    $this->post('/login/code', ['phone' => '099 000 000'])->assertSessionHasNoErrors();
    $this->post('/login/verify', ['code' => Cache::get('dev-otp:+37499000000')]);
    $this->assertAuthenticatedAs($this->admin);
    $this->get('/admin')->assertOk()->assertSee('Bookball Admin');
});

it('creates an owner account for a new phone', function () {
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');

    Livewire::test(ListUsers::class)
        ->callAction('createOwner', data: ['phone' => '091 55 66 77', 'name' => 'Karen Owner', 'venue_id' => $this->venue->id, 'role' => 'owner'])
        ->assertHasNoActionErrors();

    $owner = User::where('phone', '+37491556677')->first();
    expect($owner)->not->toBeNull()->and($owner->name)->toBe('Karen Owner')->and($owner->isRegistered())->toBeTrue();
    $this->assertDatabaseHas('venue_users', ['venue_id' => $this->venue->id, 'user_id' => $owner->id, 'role' => 'owner', 'created_by' => $this->admin->id]);
});

it('gives an existing player staff access from the owner page, without a duplicate user', function () {
    $player = User::factory()->create(['phone' => '+37493000111', 'name' => 'Narek']);
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');

    Livewire::test(OwnerAccount::class)
        ->fillForm(['phone' => '+374 93 000 111', 'name' => 'Other name', 'venue_id' => $this->venue->id, 'role' => 'staff'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::where('phone', '+37493000111')->count())->toBe(1)->and($player->fresh()->name)->toBe('Narek');
    $this->assertDatabaseHas('venue_users', ['venue_id' => $this->venue->id, 'user_id' => $player->id, 'role' => 'staff', 'created_by' => $this->admin->id]);
});

it('rejects a wrong phone in the owner form', function () {
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Livewire::test(ListUsers::class)
        ->callAction('createOwner', data: ['phone' => '12345', 'venue_id' => $this->venue->id, 'role' => 'owner'])
        ->assertHasActionErrors(['phone']);
    $this->assertDatabaseCount('venue_users', 0);
});

it('adds and removes staff on the place page', function () {
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Livewire::test(StaffRelationManager::class, ['ownerRecord' => $this->venue, 'pageClass' => EditVenue::class])
        ->callTableAction('addStaff', data: ['phone' => '+37477123456', 'name' => 'Ani', 'role' => 'staff'])
        ->assertHasNoTableActionErrors();
    $user = User::where('phone', '+37477123456')->firstOrFail();
    expect($user->load('venues')->roleAt($this->venue)?->value)->toBe('staff');

    Livewire::test(StaffRelationManager::class, ['ownerRecord' => $this->venue, 'pageClass' => EditVenue::class])
        ->callTableAction('detach', $user);
    expect($this->venue->staff()->count())->toBe(0);
});

it('blocks, unblocks and resets no-shows', function () {
    $player = User::factory()->create();
    $player->forceFill(['no_show_count' => 2, 'blocked_until' => now()->addDays(20)])->save();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');

    Livewire::test(ViewUser::class, ['record' => $player->id])->callAction('resetNoShows');
    expect($player->fresh()->no_show_count)->toBe(0)->and($player->fresh()->isBlocked())->toBeFalse();

    Livewire::test(ViewUser::class, ['record' => $player->id])->callAction('block', data: []);
    expect($player->fresh()->status)->toBe('blocked');
    Livewire::test(ViewUser::class, ['record' => $player->id])->callAction('unblock');
    expect($player->fresh()->isBlocked())->toBeFalse();
});

it('cancels a booking as the system', function () {
    $player = User::factory()->create();
    $small = $this->venue->fields->firstWhere('size.value', 'small');
    $booking = app(RequestBooking::class)->handle($player, $small, at(20), 60);
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');

    Livewire::test(ListBookings::class)
        ->callTableAction('cancel', $booking, data: ['reason' => 'Field repair'])
        ->assertHasNoTableActionErrors();

    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::Cancelled)->and($booking->cancelled_by)->toBe('system')->and($booking->cancel_reason)->toBe('Field repair');
});

it('comes back to /admin after the phone login (full page load, not an Inertia visit)', function () {
    $this->get('/admin/users')->assertRedirect(route('login'));
    $this->post('/login/code', ['phone' => '99000000']);
    $this->withHeaders(['X-Inertia' => 'true'])
        ->post('/login/verify', ['code' => Cache::get('dev-otp:+37499000000')])
        ->assertStatus(409)->assertHeader('X-Inertia-Location', url('/admin/users'));
});

it('saves a place with cleaned rules and adds a price rule', function () {
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');

    Livewire::test(EditVenue::class, ['record' => $this->venue->slug])
        ->fillForm(['rules.horizon_days' => '21', 'rules.min_notice_min' => '', 'amenities' => ['parking', 'cafe'], 'address.ru' => 'Тест 2'])
        ->call('save')
        ->assertHasNoFormErrors();
    $v = $this->venue->fresh();
    expect($v->rules)->toBe(['horizon_days' => 21])->and($v->rule('min_notice_min'))->toBe(60)
        ->and($v->amenities)->toBe(['parking', 'cafe'])->and($v->address['ru'])->toBe('Тест 2')->and($v->address['hy'])->toBe('Թեստ 1');

    $big = $this->venue->fields->firstWhere('size.value', 'big');
    Livewire::test(PriceRulesRelationManager::class, ['ownerRecord' => $this->venue, 'pageClass' => EditVenue::class])
        ->callTableAction('create', data: ['field_id' => $big->id, 'price_per_hour' => '60000', 'weekdays' => ['5', '6'], 'from_time' => '20:00', 'to_time' => '24:00', 'priority' => '5'])
        ->assertHasNoTableActionErrors();
    $rule = PriceRule::where('field_id', $big->id)->where('priority', 5)->firstOrFail();
    expect($rule->weekdays)->toBe([5, 6])->and($rule->price_per_hour)->toBe(60000);
});
