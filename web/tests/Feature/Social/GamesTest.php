<?php

use App\Domain\Booking\Actions\DecideBooking;
use App\Domain\Chat\Chat;
use App\Domain\Games\Games;
use App\Domain\Social\Friends;
use App\Domain\Social\SocialException;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\User;

/** creator with $n friends */
function crew(int $n): array
{
    $creator = User::factory()->create();
    $friends = User::factory()->count($n)->create();
    foreach ($friends as $f) {
        app(Friends::class)->accept($f, app(Friends::class)->request($creator, $f));
    }

    return [$creator, $friends];
}

beforeEach(fn () => $this->g = app(Games::class));

it('needs ~30% of the players: 5×5 opens with the creator + 2 friends', function () {
    expect(Game::minToOpen(10))->toBe(3)->and(Game::minToOpen(22))->toBe(7)->and(Game::minToOpen(6))->toBe(2);
    [$c, $friends] = crew(3);
    $game = $this->g->create($c, ['players_per_side' => 5], $friends->pluck('id')->all());
    expect($game->status)->toBe(GameStatus::Forming)->and($friends[0]->notifications()->where('data->type', 'game_invite')->count())->toBe(1);

    $this->g->respond($game, $friends[0], true);
    expect($game->fresh()->status)->toBe(GameStatus::Forming);   // 2 of 10: not yet
    $this->g->respond($game, $friends[1], true);
    $game->refresh();
    expect($game->status)->toBe(GameStatus::Open)                  // 3 of 10: open
        ->and($game->conversation->members()->count())->toBe(3);
});

it('refuses a game without enough invited friends', function () {
    [$c, $friends] = crew(1);
    expect(fn () => $this->g->create($c, ['players_per_side' => 5], $friends->pluck('id')->all()))->toThrow(SocialException::class);
});

it('only invites friends', function () {
    [$c, $friends] = crew(2);
    $stranger = User::factory()->create();
    $game = $this->g->create($c, ['players_per_side' => 3], [...$friends->pluck('id')->all(), $stranger->id]);
    expect($game->players()->where('user_id', $stranger->id)->exists())->toBeFalse();
});

it('fills up, lets players book a field and follows the booking', function () {
    [$c, $friends] = crew(2);
    $game = $this->g->create($c, ['players_per_side' => 3], $friends->pluck('id')->all());   // 3×3: 6 players, opens at 2
    $this->g->respond($game, $friends[0], true);
    expect($game->fresh()->status)->toBe(GameStatus::Open);
    foreach (User::factory()->count(3)->create() as $u) {
        $this->g->join($game->fresh(), $u);
    }
    $this->g->respond($game->fresh(), $friends[1], true);
    $game->refresh();
    expect($game->status)->toBe(GameStatus::Full)->and($game->inCount())->toBe(6);
    expect(fn () => $this->g->join($game->fresh(), User::factory()->create()))->toThrow(SocialException::class);

    $venue = makeVenue();
    $field = $venue->fields->firstWhere('size.value', 'small');
    $booking = $this->g->book($game, $c, $field, at(20), 60);
    expect(fn () => $this->g->book($game->fresh(), $friends[0], $field, at(22), 60))->toThrow(SocialException::class);   // only the creator books
    expect($booking->game_id)->toBe($game->id)->and($game->fresh()->booking_id)->toBe($booking->id);

    app(DecideBooking::class)->accept($booking, User::factory()->create());
    expect($game->fresh()->status)->toBe(GameStatus::Booked)
        ->and($game->conversation->messages()->where('body', 'chat.sys.booked')->exists())->toBeTrue();
});

it('goes back to open when a player leaves a full game and updates the chat', function () {
    [$c, $friends] = crew(5);
    $game = $this->g->create($c, ['players_per_side' => 3], $friends->pluck('id')->all());
    foreach ($friends as $f) {
        $this->g->respond($game->fresh(), $f, true);
    }
    expect($game->fresh()->status)->toBe(GameStatus::Full);
    $this->g->leave($game->fresh(), $friends[0]);
    $game->refresh();
    expect($game->status)->toBe(GameStatus::Open)
        ->and(app(Chat::class)->isMember($game->conversation, $friends[0]))->toBeFalse();
});

it('cancels forming games after 48 hours and archives quiet chats after 7 days', function () {
    [$c, $friends] = crew(2);
    $forming = $this->g->create($c, ['players_per_side' => 5], $friends->pluck('id')->all());
    $open = $this->g->create($c, ['players_per_side' => 3], $friends->pluck('id')->all());
    $this->g->respond($open, $friends[0], true);
    $this->travel(49)->hours();
    $this->g->expireForming();
    expect($forming->fresh()->status)->toBe(GameStatus::Cancelled);
    $this->travel(6)->days();
    app(Chat::class)->archiveQuiet();
    $conv = $open->fresh()->conversation;
    expect($conv->status)->toBe('archived')
        ->and(fn () => app(Chat::class)->send($conv, $c, 'hi'))->toThrow(SocialException::class);
});

it('play again invites the same people', function () {
    [$c, $friends] = crew(2);
    $game = $this->g->create($c, ['players_per_side' => 3], $friends->pluck('id')->all());
    $this->g->respond($game, $friends[0], true);
    $stranger = User::factory()->create();
    $this->g->join($game->fresh(), $stranger);
    $again = $this->g->playAgain($game->fresh(), $stranger);   // not a friend of anyone, still allowed
    expect($again->previous_game_id)->toBe($game->id)
        ->and($again->players()->where('status', 'invited')->count())->toBe(2);
});
