<?php

use App\Domain\Games\Games;
use App\Domain\Social\SocialException;
use App\Models\Game;
use App\Models\User;
use App\Models\Venue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->club = makeClub(2, ['features' => [...Venue::DEFAULT_FEATURES]]);
    $this->me = User::factory()->create(['name' => 'Arman']);
});

it('creates a ping-pong 1×1 that is open at once, so anybody can join as the opponent', function () {
    $game = app(Games::class)->create($this->me, ['sport' => 'pingpong', 'players_per_side' => 1], []);
    expect($game->sport)->toBe('pingpong')->and($game->format())->toBe('1×1')
        ->and($game->fresh()->status->value)->toBe('open')->and($game->fresh()->conversation)->not->toBeNull();
    $other = User::factory()->create();
    app(Games::class)->join($game->fresh(), $other);
    expect($game->fresh()->status->value)->toBe('full');
    // football sizes are not ping-pong ones and the other way round
    expect(fn () => app(Games::class)->create($this->me, ['sport' => 'pingpong', 'players_per_side' => 5], []))->toThrow(SocialException::class)
        ->and(fn () => app(Games::class)->create(User::factory()->create(), ['sport' => 'football', 'players_per_side' => 1], []))->toThrow(SocialException::class);
});

it('lists games of the chosen sport and creates them from the pages', function () {
    app(Games::class)->create($this->me, ['sport' => 'pingpong', 'players_per_side' => 2], []);
    $this->actingAs($this->me)->get('/games?sport=pingpong')->assertInertia(fn (Assert $p) => $p->where('sport', 'pingpong')->where('sides', [1, 2])
        ->has('games', 1)->where('games.0.format', '2×2'));
    $this->actingAs($this->me)->get('/games?sport=football')->assertInertia(fn (Assert $p) => $p->where('sport', 'football')->has('games', 0));
    $this->actingAs($this->me)->get('/games/new?sport=pingpong')->assertInertia(fn (Assert $p) => $p->where('sides', [1, 2])
        ->where('venues', fn ($v) => collect($v)->pluck('id')->all() === [$this->club->id]));
    $this->actingAs($this->me)->post('/games', ['sport' => 'pingpong', 'players_per_side' => 1])->assertRedirect();
    expect(Game::where('sport', 'pingpong')->where('players_per_side', 1)->exists())->toBeTrue();
    // the sport page shows them in its Games tab
    $this->get('/?sport=pingpong&tab=games')->assertInertia(fn (Assert $p) => $p->where('tab', 'games')->where('openGames', 2)->has('games', 2));
});

it('shows no football positions on a ping-pong game page', function () {
    $game = app(Games::class)->create($this->me, ['sport' => 'pingpong', 'players_per_side' => 1], []);
    $this->actingAs($this->me)->get('/games/'.$game->id)->assertInertia(fn (Assert $p) => $p->where('positions', []));
});
