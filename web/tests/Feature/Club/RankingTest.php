<?php

use App\Domain\Club\FeePayments;
use App\Domain\Club\Ranking;
use App\Models\SportRating;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\TournamentMatch;
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

it('shows a player their tournament matches (score from their side, won / lost, titles) and their place', function () {
    $t = Tournament::create(['venue_id' => $this->club->id, 'sport' => 'pingpong', 'title' => 'Autumn Cup', 'code' => 'autumn01', 'starts_at' => now()->subDay(), 'status' => 'done', 'options' => [['label' => '', 'price' => 0]]]);
    $ea = TournamentEntry::create(['tournament_id' => $t->id, 'user_id' => $this->a->id, 'first_name' => 'Aram', 'last_name' => 'P', 'phone' => '+37493000901', 'option' => 0, 'status' => 'accepted', 'place' => 1]);
    $eb = TournamentEntry::create(['tournament_id' => $t->id, 'user_id' => $this->b->id, 'first_name' => 'Davit', 'last_name' => 'H', 'phone' => '+37493000902', 'option' => 0, 'status' => 'accepted', 'place' => 2]);
    TournamentMatch::create(['tournament_id' => $t->id, 'round' => 1, 'slot' => 0, 'a_id' => $eb->id, 'b_id' => $ea->id, 'winner_id' => $ea->id, 'score' => '1:3']);
    $h = app(Ranking::class)->history($this->a);
    expect($h)->played->toBe(1)->won->toBe(1)->titles->toBe(1)
        ->and($h['matches'][0])->score->toBe('3:1')->won->toBeTrue()->fromEnd->toBe(1);
    $this->actingAs($this->b)->get('/u/'.$this->a->id)
        ->assertInertia(fn (Assert $p) => $p->where('player.history.played', 1)->where('player.rank.rank', 1));
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
