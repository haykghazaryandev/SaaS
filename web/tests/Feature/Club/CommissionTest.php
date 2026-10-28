<?php

use App\Domain\Club\Cards;
use App\Domain\Club\Commission;
use App\Domain\Club\Money;
use App\Enums\BookingStatus;
use App\Filament\Pages\PlatformFee;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueCard;
use Filament\Facades\Filament;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

beforeEach(function () {
    $this->club = makeClub(2, ['features' => [...Venue::DEFAULT_FEATURES, 'wallet', 'shop', 'money', 'tournaments']]);
    $this->owner = User::factory()->create();
    $this->club->staff()->attach($this->owner->id, ['role' => 'owner']);
    $this->player = User::factory()->create();
    $this->money = app(Money::class);
});

it('takes the % of bookings, trainings and tournaments — not the shop, not top-ups — and played but unpaid games', function () {
    $card = app(Cards::class)->forPlayer($this->club, $this->player);
    app(Cards::class)->topUp($card, 20000, $this->owner);                                     // not counted
    $this->money->charge($this->club, $this->owner, 'booking', 6000, 'card', $this->player);   // counted (card money used)
    $this->money->charge($this->club, $this->owner, 'training', 4000, 'cash', $this->player);
    $this->money->charge($this->club, $this->owner, 'tournament', 3000, 'cash', $this->player);
    $this->money->charge($this->club, $this->owner, 'shop', 1500, 'cash', $this->player);      // not counted
    // a game played today, never paid at the desk: its price
    Booking::create(['code' => 'BB-9901', 'venue_id' => $this->club->id, 'field_id' => $this->club->fields->first()->id, 'status' => BookingStatus::Completed,
        'source' => 'owner', 'contact_name' => 'Walk-in', 'starts_at' => now()->startOfHour(), 'ends_at' => now()->startOfHour()->addHour(), 'price' => 2000]);
    [$from, $to] = Commission::period('month');
    $r = app(Commission::class)->between($this->club, $from, $to);
    expect($r['byKind'])->toBe(['booking' => 6000, 'training' => 4000, 'tournament' => 3000, 'unpaid' => 2000])
        ->and($r['base'])->toBe(15000)->and($r['pct'])->toBe(5.0)->and($r['fee'])->toBe(750);

    // the admin sets another % for this place
    $this->club->update(['commission_pct' => 2]);
    expect(app(Commission::class)->between($this->club->fresh(), $from, $to)['fee'])->toBe(300);

    // the owner sees it on Money, the admin on ZoneSpot fee
    $this->actingAs($this->owner)->get(route('owner.money', ['period' => 'month']))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('fee.fee', 300)->where('fee.base', 15000));
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(PlatformFee::class)->assertOk()->assertSee($this->club->name)->assertSee('300 ֏')
        ->call('setPeriod', 'day')->assertSee('300 ֏')->call('move', -1)->assertDontSee('300 ֏');
});

it('shows the fee to the owner of a place without the money feature', function () {
    $park = makeVenue();
    $park->staff()->attach($this->owner->id, ['role' => 'owner']);
    $this->actingAs($this->owner)->withSession(['venue_id' => $park->id])->get(route('owner.money'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('report', null)->where('fee.pct', 5));
});

it('lets the owner message a player and make a card that takes money at once (back to the page it came from)', function () {
    $this->actingAs($this->owner)->post(route('owner.players.chat', $this->player->id))->assertRedirect();
    expect(Conversation::where('type', 'venue')->where('venue_id', $this->club->id)->exists())->toBeTrue();
    $res = $this->actingAs($this->owner)->post(route('owner.cards.store'), ['user_id' => $this->player->id, 'back' => '/owner/tournaments/5']);
    $card = VenueCard::where('user_id', $this->player->id)->firstOrFail();
    $res->assertRedirect(route('owner.cards.show', ['code' => $card->code, 'back' => '/owner/tournaments/5']));
    // made right now at the desk: a top-up without a scan
    $this->actingAs($this->owner)->post(route('owner.cards.topup', $card->code), ['amount' => 5000])->assertSessionHasNoErrors();
    expect($card->fresh()->balance)->toBe(5000);
    $this->actingAs($this->owner)->get(route('owner.cards.show', ['code' => $card->code, 'back' => 'https://evil.example']))
        ->assertInertia(fn (Assert $p) => $p->where('back', null));
});
