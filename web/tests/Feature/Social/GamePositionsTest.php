<?php

use App\Domain\Games\Games;
use App\Domain\Social\Friends;
use App\Models\Game;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/** a forming 3×3 game: creator + 2 invited friends (own helper, so this file also runs alone) */
function posGame(): array
{
    $creator = User::factory()->create();
    $friends = User::factory()->count(2)->create();
    foreach ($friends as $f) {
        app(Friends::class)->accept($f, app(Friends::class)->request($creator, $f));
    }
    $game = app(Games::class)->create($creator, ['players_per_side' => 3], $friends->pluck('id')->all());

    return [$game, $creator, $friends];
}

function positionOf(Game $game, User $u): ?string
{
    return $game->players()->where('user_id', $u->id)->value('position');
}

it('accepts an invite with a position', function () {
    [$game, $c, $friends] = posGame();
    $this->actingAs($friends[0])->post("/games/{$game->id}/respond", ['accept' => true, 'position' => 'goalkeeper'])->assertSessionHas('success');
    expect(positionOf($game, $friends[0]))->toBe('goalkeeper');
    // no position is fine too
    $this->actingAs($friends[1])->post("/games/{$game->id}/respond", ['accept' => true])->assertSessionHas('success');
    expect(positionOf($game, $friends[1]))->toBeNull();
});

it('joins an open game with a position', function () {
    [$game, $c, $friends] = posGame();
    app(Games::class)->respond($game, $friends[0], true);    // 2 of 6: open
    $stranger = User::factory()->create();
    $this->actingAs($stranger)->post("/games/{$game->id}/join", ['position' => 'forward'])->assertSessionHas('success');
    expect(positionOf($game, $stranger))->toBe('forward');
});

it('rejects an unknown position', function () {
    [$game, $c, $friends] = posGame();
    $this->actingAs($friends[0])->post("/games/{$game->id}/respond", ['accept' => true, 'position' => 'striker'])->assertSessionHasErrors('position');
    expect($game->players()->where('user_id', $friends[0]->id)->value('status')?->value)->toBe('invited');
    $this->actingAs($c)->patch("/games/{$game->id}/position", ['position' => 'libero'])->assertSessionHasErrors('position');
    expect(positionOf($game, $c))->toBeNull();
});

it('changes and clears my own position', function () {
    [$game, $c] = posGame();
    $this->actingAs($c)->patch("/games/{$game->id}/position", ['position' => 'defender'])->assertSessionHas('success', __('games.flash.position'));
    expect(positionOf($game, $c))->toBe('defender');
    $this->actingAs($c)->patch("/games/{$game->id}/position", ['position' => 'midfielder']);
    expect(positionOf($game, $c))->toBe('midfielder');
    $this->actingAs($c)->patch("/games/{$game->id}/position", ['position' => null])->assertSessionHas('success', __('games.flash.position_cleared'));
    expect(positionOf($game, $c))->toBeNull();
});

it('only changes the position of a player who is in the game', function () {
    [$game, $c, $friends] = posGame();
    app(Games::class)->respond($game, $friends[0], true, 'goalkeeper');
    // still invited, or a stranger: not in the game
    $this->actingAs($friends[1])->patch("/games/{$game->id}/position", ['position' => 'forward'])->assertSessionHas('error', __('social.error.not_member'));
    $this->actingAs(User::factory()->create())->patch("/games/{$game->id}/position", ['position' => 'forward'])->assertSessionHas('error', __('social.error.not_member'));
    // there is no way to name someone else: the creator's change only touches the creator's row
    $this->actingAs($c)->patch("/games/{$game->id}/position", ['position' => 'forward', 'user_id' => $friends[0]->id]);
    expect(positionOf($game, $friends[0]))->toBe('goalkeeper')->and(positionOf($game, $c))->toBe('forward')
        ->and(positionOf($game, $friends[1]))->toBeNull();
    // after leaving: no more changes
    app(Games::class)->leave($game, $friends[0]);
    $this->actingAs($friends[0])->patch("/games/{$game->id}/position", ['position' => 'defender'])->assertSessionHas('error', __('social.error.not_member'));
});

it('puts the positions on the game page', function () {
    [$game, $c, $friends] = posGame();
    $c->update(['position' => 'midfielder']);
    app(Games::class)->respond($game, $friends[0], true, 'goalkeeper');

    $this->actingAs($c)->get("/games/{$game->id}")->assertInertia(fn (Assert $page) => $page
        ->component('games/Show')
        ->where('players.in.0.id', $c->id)->where('players.in.0.position', null)
        ->where('players.in.1.id', $friends[0]->id)->where('players.in.1.position', 'goalkeeper')
        ->where('positions', ['goalkeeper', 'defender', 'midfielder', 'forward'])
        ->where('profilePosition', 'midfielder')
        ->where('can.position', true)
    );
    // an outsider sees the positions but can't set one
    $this->actingAs(User::factory()->create())->get("/games/{$game->id}")->assertInertia(fn (Assert $page) => $page
        ->where('players.in.1.position', 'goalkeeper')->where('can.position', false)
    );
});
