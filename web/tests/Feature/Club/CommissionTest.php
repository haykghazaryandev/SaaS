<?php

use App\Domain\Club\Cards;
use App\Domain\Club\Commission;
use App\Domain\Club\Money;
use App\Enums\BookingStatus;
use App\Filament\Pages\PlatformFee;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Payment;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueCard;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
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

it('takes the % of bookings, trainings and tournaments only — not the shop, not top-ups — refunds taken off', function () {
    $card = app(Cards::class)->forPlayer($this->club, $this->player);
    app(Cards::class)->topUp($card, 20000, $this->owner);                                     // not counted (counted when used)
    $this->money->charge($this->club, $this->owner, 'booking', 6000, 'card', $this->player);   // card money used: counted
    $this->money->charge($this->club, $this->owner, 'training', 4000, 'cash', $this->player);
    $this->money->charge($this->club, $this->owner, 'tournament', 3000, 'cash', $this->player);
    $this->money->charge($this->club, $this->owner, 'shop', 1500, 'cash', $this->player);      // not counted
    // a place with the desk: a game played but never paid is not money taken
    Booking::create(['code' => 'BB-9901', 'venue_id' => $this->club->id, 'field_id' => $this->club->fields->first()->id, 'status' => BookingStatus::Completed,
        'source' => 'owner', 'contact_name' => 'Walk-in', 'starts_at' => now()->startOfHour(), 'ends_at' => now()->startOfHour()->addHour(), 'price' => 2000]);
    [$from, $to] = Commission::period('month');
    $r = app(Commission::class)->between($this->club, $from, $to);
    expect($r['byKind'])->toBe(['booking' => 6000, 'training' => 4000, 'tournament' => 3000, 'unpaid' => 0])
        ->and($r['base'])->toBe(13000)->and($r['pct'])->toBe(5.0)->and($r['fee'])->toBe(650)
        ->and(array_sum(array_column($r['days'], 'base')))->toBe(13000);

    // a refunded tournament fee comes off
    Payment::create(['venue_id' => $this->club->id, 'kind' => 'tournament', 'method' => 'cash', 'amount' => -3000, 'created_by' => $this->owner->id]);
    expect(app(Commission::class)->between($this->club, $from, $to)['base'])->toBe(10000);

    // the admin sets another % for this place
    $this->club->update(['commission_pct' => 2]);
    expect(app(Commission::class)->between($this->club->fresh(), $from, $to)['fee'])->toBe(200);

    // the owner sees only the % (Owner → ZoneSpot fee, no history; Money is the place's own money), the admin the sums
    $this->actingAs($this->owner)->get(route('owner.fee'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('owner/Fee')->where('pct', 2));
    $this->actingAs($this->owner)->get(route('owner.money', ['period' => 'month']))->assertOk()->assertInertia(fn (Assert $p) => $p->missing('fee'));
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(PlatformFee::class)->assertOk()->assertSee($this->club->name)->assertSee('200 ֏')
        ->call('setPeriod', 'day')->assertSee('200 ֏')->call('move', -1)->assertDontSee('200 ֏');
});

it('counts the played bookings of a place without the desk, once (a bill paid there is the payment, not the booking)', function () {
    $park = makeVenue();
    $field = $park->fields->first();
    $mk = fn ($code, $status, $price, $hour, $paid = null) => Booking::create(['code' => $code, 'venue_id' => $park->id, 'field_id' => $field->id, 'status' => $status,
        'source' => 'owner', 'contact_name' => 'Team', 'starts_at' => LocalTime::now()->startOfDay()->addHours($hour)->utc(), 'ends_at' => LocalTime::now()->startOfDay()->addHours($hour + 1)->utc(), 'price' => $price, 'paid_at' => $paid]);
    $mk('BB-1', BookingStatus::Completed, 10000, 1);
    $mk('BB-2', BookingStatus::Cancelled, 9000, 2);                 // not played
    $mk('BB-3', BookingStatus::NoShow, 8000, 3);                     // not played
    $mk('BB-4', BookingStatus::Pending, 7000, 4);                    // not played
    $paid = $mk('BB-5', BookingStatus::Completed, 12000, 5, now());  // paid at a bill: its payment counts, not the booking
    $this->money->charge($park, $this->owner, 'booking', 12000, 'cash', null, $paid->id);
    [$from, $to] = Commission::period('day');
    $r = app(Commission::class)->between($park, $from, $to);
    expect($r['byKind']['unpaid'])->toBe(10000)->and($r['byKind']['booking'])->toBe(12000)->and($r['base'])->toBe(22000)->and($r['fee'])->toBe(1100)
        ->and($r['desk'])->toBeFalse();
});

it('cuts the periods in Yerevan time and rounds the fee', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00', 'Asia/Yerevan'));
    $pay = function (string $at, int $amount) {
        $p = $this->money->charge($this->club, $this->owner, 'booking', $amount, 'cash', $this->player);
        $p->forceFill(['created_at' => CarbonImmutable::parse($at, 'Asia/Yerevan')->utc()])->save();
    };
    $pay('2026-10-31 23:30', 1000);   // the last minutes of October in Yerevan
    $pay('2026-11-01 00:10', 1999);   // November in Yerevan (still October 31 in UTC)
    $this->club->update(['commission_pct' => 2.5]);
    $club = $this->club->fresh();
    [$f, $t] = Commission::period('month');
    $nov = app(Commission::class)->between($club, $f, $t);
    [$pf, $pt] = Commission::period('prev_month');
    $oct = app(Commission::class)->between($club, $pf, $pt);
    expect($nov['base'])->toBe(1999)->and($nov['fee'])->toBe(50)       // 49.975 → 50
        ->and($oct['base'])->toBe(1000)->and($oct['fee'])->toBe(25)
        ->and($nov['days'][0]['date'])->toBe('2026-11-01');
    // the admin's totals are the sum of the places
    $all = app(Commission::class)->forVenues(collect([$club, makeVenue()]), $pf, $t);
    expect($all['base'])->toBe(2999)->and($all['fee'])->toBe(75);
});

it('shows the fee to the owner of a place without the money feature', function () {
    $park = makeVenue();
    $park->staff()->attach($this->owner->id, ['role' => 'owner']);
    // no money feature: Money is the fee page
    $this->actingAs($this->owner)->withSession(['venue_id' => $park->id])->get(route('owner.money'))->assertRedirect(route('owner.fee'));
    $this->actingAs($this->owner)->get(route('owner.fee'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('pct', 5));
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
