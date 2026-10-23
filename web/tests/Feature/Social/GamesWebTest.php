<?php

use App\Domain\Games\Games;
use App\Domain\Social\Friends;
use App\Enums\GameStatus;
use App\Models\Booking;
use App\Models\Game;
use App\Models\User;
use App\Models\UserBlock;
use App\Support\LocalTime;
use Inertia\Testing\AssertableInertia as Assert;

/** creator with $n friends (HTTP tests) */
function webCrew(int $n): array
{
    $creator = User::factory()->create();
    $friends = User::factory()->count($n)->create();
    foreach ($friends as $f) {
        app(Friends::class)->accept($f, app(Friends::class)->request($creator, $f));
    }

    return [$creator, $friends];
}

/** an open 3×3 game: creator + 1 friend in, 1 more friend invited */
function openGame(): array
{
    [$c, $friends] = webCrew(2);
    $game = app(Games::class)->create($c, ['players_per_side' => 3, 'level' => 'mid', 'district' => 'arabkir'], $friends->pluck('id')->all());
    app(Games::class)->respond($game, $friends[0], true);

    return [$game->fresh(), $c, $friends];
}

beforeEach(fn () => $this->g = app(Games::class));

it('lists open games for everyone and hides forming games', function () {
    [$open] = openGame();
    [$c2, $f2] = webCrew(3);
    $forming = $this->g->create($c2, ['players_per_side' => 5], $f2->pluck('id')->all());

    $this->get('/games')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('games/Index')
        ->has('games', 1)
        ->where('games.0.id', $open->id)
        ->where('games.0.in', 2)
        ->where('games.0.total', 6)
        ->where('games.0.status', 'open')
        ->where('mine', null)
    );
    // filters: size and level
    $this->get('/games?size=big')->assertInertia(fn (Assert $page) => $page->has('games', 0));
    $this->get('/games?size=small&level=mid')->assertInertia(fn (Assert $page) => $page->has('games', 1)->where('filters.size', 'small'));
    $this->get('/games?size=3')->assertInertia(fn (Assert $page) => $page->has('games', 1));
    $this->get('/games?district=kentron')->assertInertia(fn (Assert $page) => $page->has('games', 0));
    expect($forming->status)->toBe(GameStatus::Forming);
});

it('filters open games by day and leaves out games whose time has passed', function () {
    [$game] = openGame();
    $day = at(0);
    $game->update(['when_from' => LocalTime::at($day, 19)->utc(), 'when_to' => LocalTime::at($day, 22)->utc()]);
    $this->get('/games?day='.$day->toDateString())->assertInertia(fn (Assert $page) => $page->has('games', 1)->where('games.0.window.from', '19:00'));
    $this->get('/games?day='.$day->addDay()->toDateString())->assertInertia(fn (Assert $page) => $page->has('games', 0));
    $this->get('/games?time=morning')->assertInertia(fn (Assert $page) => $page->has('games', 0));
    $this->get('/games?time=evening')->assertInertia(fn (Assert $page) => $page->has('games', 1));
    $game->update(['when_from' => now()->subHours(5), 'when_to' => now()->subHours(3)]);
    $this->get('/games')->assertInertia(fn (Assert $page) => $page->has('games', 0));
});

it('hides games of people who blocked me', function () {
    [$game, $c] = openGame();
    $me = User::factory()->create();
    UserBlock::create(['blocker_id' => $c->id, 'blocked_id' => $me->id]);
    $this->actingAs($me)->get('/games')->assertInertia(fn (Assert $page) => $page->has('games', 0));
});

it('groups my games: invites, forming, active', function () {
    [$open, $c, $friends] = openGame();          // friends[1] is still invited
    [$c2, $f2] = webCrew(3);
    $forming = $this->g->create($c2, ['players_per_side' => 5], $f2->pluck('id')->all());

    $this->actingAs($friends[1])->get('/games?tab=mine')->assertInertia(fn (Assert $page) => $page
        ->where('tab', 'mine')
        ->has('mine.invites', 1)->where('mine.invites.0.id', $open->id)->where('mine.invites.0.me', 'invited')
        ->has('mine.active', 0)
    );
    $this->actingAs($c2)->get('/games?tab=mine')->assertInertia(fn (Assert $page) => $page
        ->has('mine.forming', 1)->where('mine.forming.0.id', $forming->id)->where('mine.forming.0.mine', true)
    );
    $this->actingAs($c)->get('/games')->assertInertia(fn (Assert $page) => $page->has('mine.active', 1));
});

it('shows a forming game only to its creator and invited players', function () {
    [$c, $friends] = webCrew(3);
    $game = $this->g->create($c, ['players_per_side' => 5], $friends->pluck('id')->all());

    $this->get("/games/{$game->id}")->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->get("/games/{$game->id}")->assertNotFound();
    $this->actingAs($friends[0])->get("/games/{$game->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('games/Show')
        ->where('can.respond', true)
        ->where('can.join', false)
        ->where('conversationId', null)
        ->has('players.in', 1)
        ->has('players.invited', 3)
        ->has('players.declined', 0)
    );
    $this->actingAs($c)->get("/games/{$game->id}")->assertInertia(fn (Assert $page) => $page
        ->where('can.cancel', true)->where('can.invite', true)->where('can.book', false)->where('can.leave', false)
    );
});

it('shows an open game to everyone, with join for strangers', function () {
    [$game] = openGame();
    $this->get("/games/{$game->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('can.joinLogin', true)->where('can.join', false)->has('players.invited', 0)->where('players.invitedCount', 1)
    );
    $this->actingAs(User::factory()->create())->get("/games/{$game->id}")->assertInertia(fn (Assert $page) => $page
        ->where('can.join', true)->where('conversationId', null)
    );
});

it('sends registration-less users to the welcome page before creating a game', function () {
    $this->get('/games/new')->assertRedirect('/login');
    $this->actingAs(User::factory()->guest()->create())->get('/games/new')->assertRedirect('/welcome');
    [$c, $friends] = webCrew(2);
    $this->actingAs($c)->get('/games/new')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('games/Create')->has('friends', 2)->where('active', 0)->where('maxActive', 3)
    );
});

it('creates a game with invites and a time window', function () {
    [$c, $friends] = webCrew(3);
    $venue = makeVenue();
    $day = at(0)->toDateString();
    $res = $this->actingAs($c)->post('/games', [
        'players_per_side' => 5, 'level' => 'mid', 'district' => 'arabkir', 'venue_id' => $venue->id,
        'date' => $day, 'from' => '19:00', 'to' => '22:00', 'note' => ' Friendly ', 'invite' => $friends->pluck('id')->all(),
    ]);
    $game = Game::latest('id')->first();
    $res->assertRedirect("/games/{$game->id}");
    expect($game->players_per_side)->toBe(5)->and($game->status)->toBe(GameStatus::Forming)
        ->and($game->venue_id)->toBe($venue->id)->and($game->note)->toBe('Friendly')
        ->and($game->when_from->toImmutable()->setTimezone(LocalTime::tz())->format('Y-m-d H:i'))->toBe("$day 19:00")
        ->and($game->when_to->toImmutable()->setTimezone(LocalTime::tz())->format('H:i'))->toBe('22:00')
        ->and($game->players()->where('status', 'invited')->count())->toBe(3);
});

it('validates the create form', function () {
    [$c, $friends] = webCrew(1);
    // 5×5 needs 2 friends
    $this->actingAs($c)->post('/games', ['players_per_side' => 5, 'invite' => $friends->pluck('id')->all()])->assertSessionHasErrors('invite');
    $this->actingAs($c)->post('/games', ['players_per_side' => 12, 'invite' => []])->assertSessionHasErrors('players_per_side');
    $this->actingAs($c)->post('/games', ['players_per_side' => 3, 'level' => 'god', 'invite' => $friends->pluck('id')->all()])->assertSessionHasErrors('level');
    $this->actingAs($c)->post('/games', ['players_per_side' => 3, 'from' => '19:00', 'invite' => $friends->pluck('id')->all()])->assertSessionHasErrors('date');
    $this->actingAs($c)->post('/games', ['players_per_side' => 3, 'date' => at(0)->toDateString(), 'from' => '20:00', 'to' => '19:00', 'invite' => $friends->pluck('id')->all()])->assertSessionHasErrors('to');
    $this->actingAs($c)->post('/games', ['players_per_side' => 3, 'date' => LocalTime::now()->subDay()->toDateString(), 'invite' => $friends->pluck('id')->all()])->assertSessionHasErrors('date');
    expect(Game::count())->toBe(0);
    // 3×3 needs 1 friend: ok
    $this->actingAs($c)->post('/games', ['players_per_side' => 3, 'invite' => $friends->pluck('id')->all()])->assertSessionHasNoErrors();
    expect(Game::count())->toBe(1);
});

it('accepts an invite, opens the game and lets strangers join and leave', function () {
    [$c, $friends] = webCrew(2);
    $game = $this->g->create($c, ['players_per_side' => 3], $friends->pluck('id')->all());

    $this->actingAs($friends[0])->from("/games/{$game->id}")->post("/games/{$game->id}/respond", ['accept' => true])
        ->assertRedirect("/games/{$game->id}")->assertSessionHas('success');
    expect($game->fresh()->status)->toBe(GameStatus::Open);
    $this->actingAs($friends[1])->post("/games/{$game->id}/respond", ['accept' => false])->assertSessionHas('success');
    expect($game->players()->where('user_id', $friends[1]->id)->value('status')?->value)->toBe('declined');

    $stranger = User::factory()->create();
    $this->actingAs($stranger)->post("/games/{$game->id}/join")->assertSessionHas('success');
    expect($game->fresh()->inCount())->toBe(3);
    $this->actingAs($stranger)->get("/games/{$game->id}")->assertInertia(fn (Assert $page) => $page
        ->where('can.leave', true)->where('can.join', false)->where('can.book', true)->whereType('conversationId', 'integer')
    );
    $this->actingAs($stranger)->post("/games/{$game->id}/leave")->assertSessionHas('success');
    expect($game->fresh()->inCount())->toBe(2);

    // the creator can't leave; a stranger can't respond
    $this->actingAs($c)->post("/games/{$game->id}/leave")->assertSessionHas('error', __('social.error.creator_cannot_leave'));
    $this->actingAs($stranger)->post("/games/{$game->id}/respond", ['accept' => true])->assertSessionHas('error', __('social.error.not_invited'));
});

it('shows declined players only to the creator', function () {
    [$game, $c, $friends] = openGame();
    $this->g->respond($game, $friends[1], false);
    $this->actingAs($c)->get("/games/{$game->id}")->assertInertia(fn (Assert $page) => $page->has('players.declined', 1));
    $this->actingAs($friends[0])->get("/games/{$game->id}")->assertInertia(fn (Assert $page) => $page->has('players.declined', 0));
});

it('lets only the creator remove players and cancel', function () {
    [$game, $c, $friends] = openGame();
    $this->actingAs($friends[0])->post("/games/{$game->id}/remove/{$c->id}")->assertSessionHas('error', __('social.error.not_allowed'));
    $this->actingAs($friends[0])->post("/games/{$game->id}/cancel")->assertSessionHas('error', __('social.error.not_allowed'));

    $this->actingAs($c)->post("/games/{$game->id}/remove/{$friends[0]->id}")->assertSessionHas('success');
    expect($game->players()->where('user_id', $friends[0]->id)->value('status')?->value)->toBe('removed');
    // removed: can't join again
    $this->actingAs($friends[0])->post("/games/{$game->id}/join")->assertSessionHas('error');

    $this->actingAs($c)->post("/games/{$game->id}/cancel")->assertSessionHas('success');
    expect($game->fresh()->status)->toBe(GameStatus::Cancelled);
});

it('invites more friends from the game page', function () {
    [$game, $c] = openGame();
    $new = User::factory()->create();
    app(Friends::class)->accept($new, app(Friends::class)->request($c, $new));
    $this->actingAs($c)->get("/games/{$game->id}")->assertInertia(fn (Assert $page) => $page->has('friends', 1)->where('friends.0.id', $new->id));
    $this->actingAs($c)->post("/games/{$game->id}/invite", ['ids' => [$new->id]])->assertSessionHas('success');
    expect($game->players()->where('user_id', $new->id)->value('status')?->value)->toBe('invited');
    $this->actingAs($c)->post("/games/{$game->id}/invite", ['ids' => []])->assertSessionHasErrors('ids');
    $this->actingAs(User::factory()->create())->post("/games/{$game->id}/invite", ['ids' => [$new->id]])->assertSessionHas('error', __('social.error.not_member'));
});

it('books a field for the game through the booking rules', function () {
    [$game, $c, $friends] = openGame();
    $venue = makeVenue();
    $small = $venue->fields->firstWhere('size.value', 'small');
    $date = at(0)->toDateString();

    // not a player of the game
    $this->actingAs(User::factory()->create())->post("/games/{$game->id}/book", ['field_id' => $small->id, 'date' => $date, 'start' => '20:00', 'minutes' => 60])
        ->assertSessionHasErrors(['booking' => __('social.error.not_member')]);
    // a bad duration comes from the booking rules
    $this->actingAs($friends[0])->post("/games/{$game->id}/book", ['field_id' => $small->id, 'date' => $date, 'start' => '20:00', 'minutes' => 45])
        ->assertSessionHasErrors('booking');

    $this->actingAs($friends[0])->post("/games/{$game->id}/book", ['field_id' => $small->id, 'date' => $date, 'start' => '20:00', 'minutes' => 60])
        ->assertRedirect("/games/{$game->id}")->assertSessionHasNoErrors();
    $booking = Booking::where('game_id', $game->id)->first();
    expect($booking)->not->toBeNull()->and($booking->user_id)->toBe($friends[0]->id)->and($game->fresh()->booking_id)->toBe($booking->id);

    $this->actingAs($c)->get("/games/{$game->id}")->assertInertia(fn (Assert $page) => $page
        ->where('can.book', false)->where('game.booking.status', 'pending')->where('game.booking.start', '20:00')
    );
    $this->actingAs($c)->post("/games/{$game->id}/book", ['field_id' => $small->id, 'date' => $date, 'start' => '22:00', 'minutes' => 60])
        ->assertSessionHasErrors(['booking' => __('social.error.already_booked')]);
});

it('plays again with the same people', function () {
    [$game, $c, $friends] = openGame();
    $game->update(['status' => GameStatus::Played]);
    $this->actingAs($c)->get("/games/{$game->id}")->assertInertia(fn (Assert $page) => $page->where('can.playAgain', true)->where('can.join', false));
    $this->actingAs($c)->post("/games/{$game->id}/play-again");
    $new = Game::latest('id')->first();
    expect($new->id)->not->toBe($game->id)->and($new->previous_game_id)->toBe($game->id)
        ->and($new->players()->where('user_id', $friends[0]->id)->value('status')?->value)->toBe('invited');
    $this->actingAs(User::factory()->create())->post("/games/{$game->id}/play-again")->assertSessionHas('error');
});
