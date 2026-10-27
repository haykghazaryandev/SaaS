<?php

use App\Domain\Booking\BookingException;
use App\Domain\Chat\Chat;
use App\Domain\Club\Events\TournamentChanged;
use App\Domain\Club\Tournaments;
use App\Http\Presenters\TournamentBracket;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SportRating;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\Notice;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->club = makeClub(2, ['features' => [...Venue::DEFAULT_FEATURES, 'rating', 'tournaments']]);
    $this->owner = User::factory()->create(['name' => 'Sahak']);
    $this->club->staff()->attach($this->owner->id, ['role' => 'owner']);
    $this->tt = app(Tournaments::class);
    $this->t = $this->tt->save(new Tournament(['venue_id' => $this->club->id]), ['title' => 'November Open', 'sport' => 'pingpong',
        'starts_at' => now()->addDays(5), 'options' => [['label' => 'Free', 'price' => 0]], 'min_rating' => 1000, 'max_rating' => 1600, 'max_players' => 6]);
    // registers $n players with accounts, ratings 1500, 1450, … (the first is the best)
    $this->players = function (int $n, bool $accept = true) {
        $base = 2000 + User::count();   // a second batch gets other phones

        return collect(range(1, $n))->map(function ($i) use ($accept, $base) {
            $u = User::factory()->create(['name' => "P$i", 'phone' => '+3749300'.($base + $i)]);
            SportRating::create(['user_id' => $u->id, 'sport' => 'pingpong', 'rating' => 1550 - 50 * $i]);
            $e = $this->tt->register($this->t, ['first_name' => "P$i", 'last_name' => 'Test', 'phone' => $u->phone, 'option' => 0], $u);
            if ($accept) {
                $this->tt->decide($e, 'accepted');
            }

            return $e->fresh();
        });
    };
});

it('lets in only players within the rating range, up to the number of players', function () {
    $rated = fn ($r, $phone) => tap(User::factory()->create(['phone' => $phone]), fn ($u) => SportRating::create(['user_id' => $u->id, 'sport' => 'pingpong', 'rating' => $r]));
    $reg = fn ($u, $level = null) => $this->tt->register($this->t, ['first_name' => 'A', 'last_name' => 'B', 'phone' => $u?->phone ?? '+37491999999', 'option' => 0, 'level' => $level], $u);
    expect(fn () => $reg($rated(1700, '+37491000901')))->toThrow(BookingException::class, __('booking.error.rating_out_of_range'))
        ->and(fn () => $reg($rated(900, '+37491000902')))->toThrow(BookingException::class, __('booking.error.rating_out_of_range'))
        // no rating in the app: the level they give counts
        ->and(fn () => $reg(null))->toThrow(BookingException::class, __('booking.error.rating_needed'));
    $reg(null, 1200);
    ($this->players)(5, false);   // 6 registered: full
    expect(fn () => $reg($rated(1300, '+37491000903')))->toThrow(BookingException::class, __('booking.error.tournament_full'));
});

it('tells players they are accepted and puts them in the tournament chat with the organiser', function () {
    Notification::fake();
    [$p1, $p2] = ($this->players)(2)->all();
    $u1 = User::find($p1->user_id);
    Notification::assertSentTo($u1, Notice::class, fn ($n) => $n->type === 'tournament_accepted');
    $c = Conversation::where('tournament_id', $this->t->id)->firstOrFail();
    $chat = app(Chat::class);
    expect($chat->isMember($c, $u1))->toBeTrue()->and($chat->isMember($c, $this->owner))->toBeTrue();
    // declined: out of the chat, and told
    $this->tt->decide($p2, 'declined');
    expect($chat->isMember($c, User::find($p2->user_id)))->toBeFalse();
    Notification::assertSentTo(User::find($p2->user_id), Notice::class, fn ($n) => $n->type === 'tournament_declined');

    // the organiser's news: to everybody registered and into the chat; the chat marks the organiser
    $this->actingAs($this->owner)->post("/owner/tournaments/{$this->t->id}/announce", ['text' => 'Start at 11:00, bring rackets'])->assertRedirect();
    Notification::assertSentTo($u1, Notice::class, fn ($n) => $n->type === 'tournament_news' && $n->params['text'] === 'Start at 11:00, bring rackets');
    expect(Message::where('conversation_id', $c->id)->where('user_id', $this->owner->id)->value('body'))->toBe('Start at 11:00, bring rackets');
    // only the accepted ones: the applied player doesn't get it
    $applied = ($this->players)(1, false)->first();
    $this->actingAs($this->owner)->post("/owner/tournaments/{$this->t->id}/announce", ['text' => 'Accepted only', 'to' => 'accepted'])->assertRedirect();
    Notification::assertNotSentTo(User::find($applied->user_id), Notice::class, fn ($n) => $n->type === 'tournament_news' && $n->params['text'] === 'Accepted only');
    Notification::assertSentTo($u1, Notice::class, fn ($n) => $n->type === 'tournament_news' && $n->params['text'] === 'Accepted only');
    $this->actingAs($u1)->get("/messages/{$c->id}")->assertInertia(fn (Assert $p) => $p->where('chat.type', 'tournament')
        ->where('chat.tournament.title', 'November Open')->where('chat.tournament.hostIds', [$this->owner->id]));
    $this->actingAs($this->owner)->post("/owner/tournaments/{$this->t->id}/chat")->assertRedirect("/messages/{$c->id}");
});

it('draws a bracket by rating with byes for the best, moves winners on and ends with the final', function () {
    Notification::fake();
    Event::fake([TournamentChanged::class]);
    $e = ($this->players)(5);   // 5 players → 8 places, 3 byes for the best three
    expect(fn () => $this->tt->decide($e[0], 'declined'))->not->toThrow(BookingException::class);
    $this->tt->decide($e[0]->fresh(), 'accepted');
    $this->tt->draw($this->t->fresh());
    $t = $this->t->fresh();
    expect($t->drawn_at)->not->toBeNull()
        ->and(TournamentMatch::where('tournament_id', $t->id)->count())->toBe(4 + 2 + 1);
    $r1 = TournamentMatch::where('tournament_id', $t->id)->where('round', 1)->orderBy('slot')->get();
    // seed 1 meets "seed 8" (nobody): a bye, already in the quarter… semi-final
    expect($r1[0]->a_id)->toBe($e[0]->id)->and($r1[0]->b_id)->toBeNull()->and($r1[0]->winner_id)->toBe($e[0]->id)
        ->and(TournamentMatch::where('tournament_id', $t->id)->where('round', 2)->where('slot', 0)->value('a_id'))->toBe($e[0]->id);
    // the only real first-round match: seeds 4 and 5
    $real = $r1->first(fn ($m) => $m->a_id && $m->b_id);
    expect([$real->a_id, $real->b_id])->toBe([$e[3]->id, $e[4]->id]);
    Notification::assertSentTo(User::find($e[3]->user_id), Notice::class, fn ($n) => $n->type === 'tournament_draw' && $n->params['vs'] === 'P5 Test');
    Notification::assertSentTo(User::find($e[0]->user_id), Notice::class, fn ($n) => $n->type === 'tournament_bye');
    Event::assertDispatched(TournamentChanged::class);

    // live, then the result: P5 wins 3:2 and goes on against seed 1
    $this->actingAs($this->owner)->patch("/owner/tournament-matches/{$real->id}", ['live' => true]);
    expect($real->fresh()->live)->toBeTrue();
    $this->actingAs($this->owner)->patch("/owner/tournament-matches/{$real->id}", ['winner' => $e[4]->id, 'score' => '3:2'])->assertSessionHasNoErrors();
    expect($real->fresh()->live)->toBeFalse()
        ->and(TournamentMatch::where('tournament_id', $t->id)->where('round', 2)->where('slot', 0)->value('b_id'))->toBe($e[4]->id);
    Notification::assertSentTo(User::find($e[3]->user_id), Notice::class, fn ($n) => $n->type === 'tournament_result' && $n->params['winner'] === 'P5 Test');
    // no redraw once a match is played; a stranger can't be the winner
    expect(fn () => $this->tt->draw($t->fresh()))->toThrow(BookingException::class)
        ->and(fn () => $this->tt->result($real->fresh(), $e[0]->id))->toThrow(BookingException::class);

    // semi-finals and the final
    $semi = fn ($slot) => TournamentMatch::where('tournament_id', $t->id)->where('round', 2)->where('slot', $slot)->first();
    $this->tt->result($semi(0), $e[0]->id, '3:0');
    $this->tt->result($semi(1), $semi(1)->a_id, '3:1');
    $final = TournamentMatch::where('tournament_id', $t->id)->where('round', 3)->first();
    $this->tt->result($final, $final->b_id, '3:2');
    expect($t->fresh()->status)->toBe('done')
        ->and(TournamentEntry::find($final->b_id)->place)->toBe(1)
        ->and(TournamentEntry::find($final->a_id)->place)->toBe(2)
        ->and(TournamentEntry::find($e[4]->id)->place)->toBe(3);
    Notification::assertSentTo(User::find(TournamentEntry::find($final->b_id)->user_id), Notice::class, fn ($n) => $n->type === 'tournament_champion');
    // the semi-final can't change any more: its winner played the final
    expect(fn () => $this->tt->result($semi(0), $semi(0)->b_id))->toThrow(BookingException::class);
});

it('shows the bracket on the public page with my match, and on the owner page', function () {
    $e = ($this->players)(4);
    $this->actingAs($this->owner)->post("/owner/tournaments/{$this->t->id}/draw")->assertSessionHasNoErrors();
    $me = User::find($e[1]->user_id);
    $this->actingAs($me)->get('/t/'.$this->t->code)->assertInertia(fn (Assert $p) => $p
        ->where('bracket.total', 2)->has('bracket.rounds', 2)->has('bracket.rounds.0.matches', 2)
        ->where('entry.status', 'accepted')->where('chat', Conversation::where('tournament_id', $this->t->id)->value('id'))
        ->where('bracket.rounds.0.matches', fn ($ms) => collect($ms)->contains(fn ($m) => ($m['a']['me'] ?? false) || ($m['b']['me'] ?? false))));
    auth()->logout();
    $this->get('/t/'.$this->t->code)->assertInertia(fn (Assert $p) => $p->where('chat', null)->has('bracket.rounds', 2));
    $this->actingAs($this->owner)->get("/owner/tournaments/{$this->t->id}")->assertInertia(fn (Assert $p) => $p->component('owner/Tournament')
        ->where('tournament.bracket.total', 2)->where('tournament.drawn', true)->where('tournament.max_players', 6));
    $this->actingAs($this->owner)->get('/owner/tournaments')->assertInertia(fn (Assert $p) => $p->component('owner/Tournaments')
        ->where('tournaments.0.accepted', 4)->where('tournaments.0.drawn', true)->where('tournaments.0.phase', 'drawn'));
    // players see it in the sport's Tournaments tab with where it is
    auth()->logout();
    $this->get('/?sport=pingpong&tab=tournaments')->assertInertia(fn (Assert $p) => $p->where('tournaments.0.phase', 'drawn'));
    $this->actingAs($this->owner)->get("/owner/tournaments?t={$this->t->id}")->assertRedirect("/owner/tournaments/{$this->t->id}");
    // another place's owner: no
    $other = User::factory()->create();
    makeClub(1, ['features' => ['tournaments']])->staff()->attach($other->id, ['role' => 'owner']);
    $this->actingAs($other)->post("/owner/tournaments/{$this->t->id}/draw")->assertNotFound();
});

it('sends news to the registered ones only (no chat) and reminds the unpaid', function () {
    Notification::fake();
    $this->t->update(['options' => [['label' => 'Standard', 'price' => 5000]]]);
    [$a, $b] = ($this->players)(2)->all();
    $waiting = ($this->players)(1, false)->first();
    $ua = User::find($a->user_id);
    // accepted with a fee: the message says how much
    Notification::assertSentTo($ua, Notice::class, fn ($n) => $n->type === 'tournament_accepted_fee' && $n->params['amount'] === '5 000 ֏');

    // the one-player message is gone
    $this->actingAs($this->owner)->post("/owner/tournament-entries/{$a->id}/message", ['text' => 'Hi'])->assertNotFound();

    // news to the waiting ones: a notification, nothing in the chat
    $before = Message::count();
    $this->actingAs($this->owner)->post("/owner/tournaments/{$this->t->id}/announce", ['text' => 'We answer tomorrow', 'to' => 'applied'])->assertSessionHasNoErrors();
    Notification::assertSentTo(User::find($waiting->user_id), Notice::class, fn ($n) => $n->type === 'tournament_news' && $n->params['text'] === 'We answer tomorrow');
    Notification::assertNotSentTo($ua, Notice::class, fn ($n) => $n->type === 'tournament_news' && $n->params['text'] === 'We answer tomorrow');
    expect(Message::count())->toBe($before);

    // the unpaid: a reminder (the paid one doesn't get it); the public page shows it too
    $this->tt->pay($b->fresh(), 'cash', $this->owner);
    $this->actingAs($this->owner)->post("/owner/tournaments/{$this->t->id}/remind")->assertSessionHasNoErrors();
    Notification::assertSentTo($ua, Notice::class, fn ($n) => $n->type === 'tournament_unpaid');
    Notification::assertNotSentTo(User::find($b->user_id), Notice::class, fn ($n) => $n->type === 'tournament_unpaid');
    $this->actingAs($ua)->get('/t/'.$this->t->code)->assertInertia(fn (Assert $p) => $p->where('entry.unpaid', 5000));
    $this->actingAs(User::find($b->user_id))->get('/t/'.$this->t->code)->assertInertia(fn (Assert $p) => $p->where('entry.unpaid', 0));

    // by itself the day before
    $this->travelTo($this->t->starts_at->subDay()->setTime(10, 0));
    expect(app(Tournaments::class)->remindTomorrow())->toBe(1);
});

it('draws and shows brackets of any size: 2, 3, 11 and 100 players', function () {
    foreach ([2 => [1, 2], 3 => [2, 4], 11 => [4, 16], 100 => [7, 128]] as $n => [$rounds, $size]) {
        $t = $this->tt->save(new Tournament(['venue_id' => $this->club->id]), ['title' => "Cup $n", 'sport' => 'pingpong', 'starts_at' => now()->addDays(3),
            'options' => [['label' => 'Free', 'price' => 0]]]);
        foreach (range(1, $n) as $i) {
            TournamentEntry::create(['tournament_id' => $t->id, 'first_name' => "N$i", 'last_name' => 'X', 'phone' => '+3749'.(1000000 + 1000 * $n + $i), 'level' => 2000 - $i, 'option' => 0, 'status' => 'accepted']);
        }
        $this->tt->draw($t);
        $b = TournamentBracket::of($t->fresh());
        expect($b['total'])->toBe($rounds)->and($b['rounds'][0]['matches'])->toHaveCount($size / 2)
            // every player is in the first round once; byes = places left over, the best seeds get them
            ->and(collect($b['rounds'][0]['matches'])->flatMap(fn ($m) => [$m['a']['id'] ?? null, $m['b']['id'] ?? null])->filter()->unique())->toHaveCount($n)
            ->and(collect($b['rounds'][0]['matches'])->where('bye', true))->toHaveCount($size - $n);
        // play it to the end: the a side always wins
        foreach (range(1, $rounds) as $r) {
            foreach (TournamentMatch::where('tournament_id', $t->id)->where('round', $r)->orderBy('slot')->get() as $m) {
                if ($m->a_id && $m->b_id && ! $m->winner_id) {
                    $this->tt->result($m, $m->a_id, '3:0');
                }
            }
        }
        $top = TournamentEntry::where('tournament_id', $t->id)->orderByDesc('level')->first();
        expect($t->fresh()->status)->toBe('done')->and($top->fresh()->place)->toBe(1);
    }
});

it('takes a score for each side: the higher one wins', function () {
    ($this->players)(4);
    $this->tt->draw($this->t->fresh());
    $m = TournamentMatch::where('tournament_id', $this->t->id)->where('round', 1)->orderBy('slot')->first();
    $this->actingAs($this->owner)->patch("/owner/tournament-matches/{$m->id}", ['score_a' => 2, 'score_b' => 2])->assertSessionHasErrors('score_b');
    $this->actingAs($this->owner)->patch("/owner/tournament-matches/{$m->id}", ['score_a' => 1, 'score_b' => 3])->assertSessionHasNoErrors();
    expect($m->fresh()->winner_id)->toBe($m->b_id)->and($m->fresh()->score)->toBe('1:3')
        ->and(TournamentMatch::where('tournament_id', $this->t->id)->where('round', 2)->value('a_id'))->toBe($m->b_id);
});

it('shows players and guests where the tournament is, and closes registration once the draw is made', function () {
    $code = $this->t->code;
    $form = ['first_name' => 'Guest', 'last_name' => 'One', 'phone' => '+37491888001', 'option' => 0, 'level' => 1200];
    // registration: open; a player registers and sees their status
    $this->get("/t/$code")->assertInertia(fn (Assert $p) => $p->where('tournament.phase', 'registration')->where('tournament.open', true)->where('entry', null));
    $guest = User::factory()->create(['phone' => '+37491888001']);
    $this->actingAs($guest)->post("/t/$code", $form)->assertSessionHasNoErrors();
    $this->actingAs($guest)->get("/t/$code")->assertInertia(fn (Assert $p) => $p->where('entry.status', 'applied'));

    // before the draw the page shows who is in (the accepted players)
    $e = ($this->players)(3);
    $this->get("/t/$code")->assertInertia(fn (Assert $p) => $p->has('tournament.players', 3)->where('tournament.players.0.first', 'P1'));
    // the lists and the page hear about changes
    expect(collect((new TournamentChanged($code))->broadcastOn())->map->name->all())->toBe(["tournament.$code", 'tournaments']);

    $this->tt->draw($this->t->fresh());
    // drawn: no more registrations (also not by posting the form), no accepting new players
    auth()->logout();
    $this->flushSession();
    $this->get("/t/$code")->assertInertia(fn (Assert $p) => $p->where('tournament.phase', 'drawn')->where('tournament.open', false)->where('tournament.accepted', 3));
    $late = User::factory()->create(['phone' => '+37491888002']);
    $this->actingAs($late)->post("/t/$code", $form)->assertSessionHasErrors('register');
    auth()->logout();
    expect(TournamentEntry::where('phone', '+37491888002')->exists())->toBeFalse()
        ->and(fn () => $this->tt->decide(TournamentEntry::where('phone', '+37491888001')->first(), 'accepted'))->toThrow(BookingException::class, __('booking.error.already_drawn'));

    // a match is played: live
    $m = TournamentMatch::where('tournament_id', $this->t->id)->where('round', 1)->whereNotNull('a_id')->whereNotNull('b_id')->first();
    $this->tt->setLive($m, true);
    $this->get("/t/$code")->assertInertia(fn (Assert $p) => $p->where('tournament.phase', 'live'));
    // the end: the podium
    $this->tt->result($m->fresh(), $m->a_id, '3:0');
    $final = TournamentMatch::where('tournament_id', $this->t->id)->where('round', 2)->first();
    $this->tt->result($final->fresh(), $final->fresh()->a_id, '3:1');
    $this->get("/t/$code")->assertInertia(fn (Assert $p) => $p->where('tournament.phase', 'done')->has('tournament.podium', 3)
        ->where('tournament.podium.0.place', 1)->where('tournament.open', false));

    // the club closes registration before any draw
    $t2 = $this->tt->save(new Tournament(['venue_id' => $this->club->id]), ['title' => 'Closed', 'sport' => 'pingpong', 'starts_at' => now()->addDays(3),
        'options' => [['label' => 'Free', 'price' => 0]], 'status' => 'closed']);
    $this->get('/t/'.$t2->code)->assertInertia(fn (Assert $p) => $p->where('tournament.phase', 'closed')->where('tournament.open', false));
    $this->actingAs(User::factory()->create(['phone' => '+37491888003']))->post('/t/'.$t2->code, $form)->assertSessionHasErrors('register');
});

it('lists my tournaments: where each is, the answer, the fee and my place; finished ones stay', function () {
    $this->t->update(['options' => [['label' => 'Standard', 'price' => 5000]]]);
    [$a, $b] = ($this->players)(2)->all();
    $me = User::find($a->user_id);
    $this->actingAs($me)->get('/me/tournaments')->assertOk()->assertInertia(fn (Assert $p) => $p->component('player/MyTournaments')
        ->has('items', 1)->where('items.0.phase', 'registration')->where('items.0.status', 'accepted')->where('items.0.fee', 5000)->where('items.0.paid', null));
    $this->tt->pay($a->fresh(), 'cash', $this->owner);
    $this->tt->draw($this->t->fresh());
    $final = TournamentMatch::where('tournament_id', $this->t->id)->first();
    $this->tt->result($final, $a->id, '3:0');
    $this->actingAs($me)->get('/me/tournaments')->assertInertia(fn (Assert $p) => $p->where('items.0.phase', 'done')->where('items.0.place', 1)
        ->where('items.0.paid.method', 'cash'));
    // the menus know there is something to show
    $this->actingAs($me)->get('/')->assertInertia(fn (Assert $p) => $p->where('counts.tournaments', 1));
    $this->actingAs(User::factory()->create())->get('/me/tournaments')->assertInertia(fn (Assert $p) => $p->has('items', 0));
    auth()->logout();
    $this->get('/me/tournaments')->assertRedirect();
});
