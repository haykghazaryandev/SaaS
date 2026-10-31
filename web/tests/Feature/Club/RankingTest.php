<?php

use App\Domain\Club\FeePayments;
use App\Domain\Club\Ranking;
use App\Domain\Social\Friends;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\SportRating;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use App\Support\LocalTime;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->club = makeClub(2);
    $this->owner = User::factory()->create();
    $this->club->staff()->attach($this->owner->id, ['role' => 'owner']);
    [$this->a, $this->b, $this->c] = [User::factory()->create(['name' => 'Aram']), User::factory()->create(['name' => 'Davit']), User::factory()->create(['name' => 'Gor'])];
    foreach ([[$this->a, 1520], [$this->b, 1450], [$this->c, 1450]] as [$u, $r]) {
        SportRating::create(['user_id' => $u->id, 'sport' => 'pingpong', 'rating' => $r, 'venue_id' => $this->club->id]);
    }
});

it('ranks a club by the rating it sets; equal ratings share a place; the place page and its ranking page show it', function () {
    $rows = app(Ranking::class)->club($this->club);
    expect($rows->pluck('rank')->all())->toBe([1, 2, 2])->and($rows->pluck('name')->all())->toBe(['Aram', 'Davit', 'Gor']);
    $this->actingAs($this->b)->get('/places/'.$this->club->slug)
        ->assertInertia(fn (Assert $p) => $p->where('club.ranking.total', 3)->where('club.ranking.top.0.name', 'Aram')->where('club.ranking.me.rank', 2));
    $this->get('/places/'.$this->club->slug.'/ranking')->assertOk()->assertInertia(fn (Assert $p) => $p->component('player/Ranking')->has('rows', 3));
    // football has no ranking
    $field = makeVenue();
    $this->get('/places/'.$field->slug.'/ranking')->assertNotFound();
});

it('shows each rating with the club that set it on the profile', function () {
    $this->actingAs($this->b)->get('/u/'.$this->a->id)
        ->assertInertia(fn (Assert $p) => $p->where('player.ratings.pingpong.rating', 1520)->where('player.ratings.pingpong.club.name', $this->club->name)->missing('player.history'));
});

it('lets the admin mark a month of ZoneSpot fee as paid, and the owner sees it', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();
    $month = LocalTime::now()->startOfMonth();
    $fp = app(FeePayments::class)->markPaid($this->club, $month, $admin, 'cash');
    expect($fp->month->toDateString())->toBe($month->toDateString());
    $this->actingAs($this->owner)->withSession(['venue_id' => $this->club->id])->get('/owner/fee?period=month')
        ->assertInertia(fn (Assert $p) => $p->where('paidOn', $fp->paid_at->setTimezone(LocalTime::tz())->format('d.m.Y')));
    app(FeePayments::class)->unmark($this->club, $month);
    $this->actingAs($this->owner)->withSession(['venue_id' => $this->club->id])->get('/owner/fee?period=month')->assertInertia(fn (Assert $p) => $p->where('paidOn', null));
    $this->actingAs($admin)->get('/admin/fee')->assertOk();
});

it('lists players\' reports for the admin, open first, and marks them handled', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();
    $r = app(Friends::class)->report($this->a, 'user', $this->b->id, 'Rude in the chat');
    $this->actingAs($admin)->get('/admin/reports')->assertOk()->assertSee('Rude in the chat');
    expect(ReportResource::getNavigationBadge())->toBe('1')
        ->and(ReportResource::targetUser($r)->id)->toBe($this->b->id);
});

it('shows tournament medals on the profile', function () {
    $t = Tournament::create(['venue_id' => $this->club->id, 'sport' => 'pingpong', 'title' => 'Cup', 'code' => 'cup00001', 'starts_at' => now()->subDay(), 'status' => 'done', 'options' => [['label' => '', 'price' => 0]]]);
    foreach ([1, 1, 3] as $i => $place) {
        TournamentEntry::create(['tournament_id' => $t->id, 'user_id' => $this->a->id, 'first_name' => 'Aram', 'last_name' => 'P', 'phone' => '+3749300090'.$i, 'option' => 0, 'status' => 'accepted', 'place' => $place]);
    }
    $this->actingAs($this->b)->get('/u/'.$this->a->id)->assertInertia(fn (Assert $p) => $p->where('player.medals', [1 => 2, 3 => 1]));
});
