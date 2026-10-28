<?php

namespace App\Http\Controllers\Owner\Club;

use App\Domain\Club\Tournaments;
use App\Enums\Sport;
use App\Http\Controllers\Owner\OwnerController;
use App\Http\Presenters\TournamentBracket;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\TournamentMatch;
use App\Models\VenueCard;
use App\Support\LocalTime;
use App\Support\Phone;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Owner → Tournaments: open one (title, date, up to 3 prices), share its link, accept people, take fees, write results. */
class TournamentController extends OwnerController
{
    /** The list: every tournament of the place with how many registered / accepted / paid; "New tournament". */
    public function index(Request $request)
    {
        $venue = $this->venue($request);
        $this->ensureAny($venue, ['tournaments']);
        // old links (?t=5) open the tournament's own page
        if ($request->query('t') && ($t = Tournament::where('venue_id', $venue->id)->find((int) $request->query('t')))) {
            return redirect()->route('owner.tournaments.show', $t->id);
        }
        // a tournament with divisions: one row, its divisions under it
        $list = Tournament::where('venue_id', $venue->id)->whereNull('parent_id')
            ->with(['entries:id,tournament_id,status,option,payment_id', 'divisions.entries:id,tournament_id,status,option,payment_id'])
            ->orderByDesc('starts_at')->get();
        $row = fn (Tournament $t) => $this->present($t) + [
            'phase' => Tournaments::phase($t),
            'entries' => $t->entries->where('status', '!=', 'declined')->count(),
            'waiting' => $t->entries->where('status', 'applied')->count(),
            'accepted' => $t->entries->where('status', 'accepted')->count(),
            'unpaid' => $t->entries->where('status', 'accepted')->filter(fn ($e) => ! $e->payment_id && Tournaments::fee($t, $e) > 0)->count(),
        ];

        return Inertia::render('owner/Tournaments', [
            'tournaments' => $list->map(fn (Tournament $t) => $row($t) + ['divisions' => $t->divisions->each->setRelation('parent', $t)->map($row)->values()])->values(),
            'sport' => ($venue->sports()[0] ?? Sport::Football)->value,
        ]);
    }

    /** One tournament: its settings, the players (filters: waiting / accepted / paid …), the bracket, news, chat. */
    public function show(Request $request, Tournament $tournament)
    {
        $this->own($request, $tournament);
        $t = $tournament->load(['parent', 'divisions.entries:id,tournament_id,status,option,payment_id']);
        // a tournament with divisions: its settings, news to every division and the divisions (each one: its own page)
        if ($t->divisions->isNotEmpty()) {
            return Inertia::render('owner/Tournament', ['tournament' => $this->present($t) + [
                'phase' => Tournaments::phase($t), 'list' => [], 'bracket' => null, 'chat' => null, 'unpaid' => 0, 'siblings' => [],
                'divisions' => $t->divisions->each->setRelation('parent', $t)->map(fn (Tournament $d) => $this->present($d) + ['phase' => Tournaments::phase($d),
                    'accepted' => $d->entries->where('status', 'accepted')->count(), 'waiting' => $d->entries->where('status', 'applied')->count()])->values(),
            ]]);
        }
        // the players' cards here: "from the card" only for those who have one (and its balance)
        $cards = VenueCard::where('venue_id', $t->venue_id)->whereIn('user_id', $t->entries()->whereNotNull('user_id')->select('user_id'))
            ->pluck('balance', 'user_id');

        return Inertia::render('owner/Tournament', [
            'tournament' => $this->present($t) + [
                'phase' => Tournaments::phase($t),
                'unpaid' => Tournaments::unpaid($t),
                'divisions' => [],
                // the other divisions of the same tournament (tabs)
                'siblings' => $t->parent_id ? Tournament::where('parent_id', $t->parent_id)->orderByRaw('min_rating asc nulls first')->orderBy('id')->get(['id', 'division'])
                    ->map(fn ($d) => ['id' => $d->id, 'division' => $d->division])->values() : [],
                'list' => $t->entries()->with('payment:id,amount,method')->orderBy('id')->get()->map(fn (TournamentEntry $e) => [
                    'card' => $e->user_id && $cards->has($e->user_id) ? (int) $cards[$e->user_id] : null,
                    'id' => $e->id, 'name' => $e->first_name.' '.$e->last_name, 'phone' => Phone::format($e->phone), 'tel' => $e->phone,
                    'level' => Tournaments::ratingOf($t, $e->user_id, $e->level),
                    'option' => $t->options[$e->option]['label'] ?? '—', 'price' => Tournaments::fee($t, $e),
                    'status' => $e->status, 'place' => $e->place, 'account' => $e->user_id !== null,
                    'paid' => $e->payment ? ['amount' => $e->payment->amount, 'method' => $e->payment->method] : null,
                ])->values(),
                'bracket' => TournamentBracket::of($t),
                'chat' => $t->conversation()->value('id'),
            ],
        ]);
    }

    public function store(Request $request, Tournaments $tournaments)
    {
        $this->ensureOwner($request);
        $venue = $this->venue($request);
        $this->ensureAny($venue, ['tournaments']);
        $t = new Tournament(['venue_id' => $venue->id]);
        $t->setRelation('venue', $venue);
        $data = $this->data($request);
        $divisions = $this->divisions($request);
        $t = $this->attempt(fn () => $divisions ? $tournaments->saveWithDivisions($t, $data, $divisions) : $tournaments->save($t, $data));

        return redirect()->route('owner.tournaments.show', $t->id)->with('success', __('owner.flash.saved'));
    }

    public function update(Request $request, Tournament $tournament, Tournaments $tournaments)
    {
        $this->ensureOwner($request);
        abort_unless($tournament->venue_id === $this->venue($request)->id, 404);
        $data = $this->data($request);
        $divisions = $this->divisions($request);
        $tournament->setRelation('venue', $this->venue($request));
        $this->attempt(fn () => match (true) {
            $tournament->parent_id !== null => $tournaments->saveDivision($tournament, $data + ['division' => $request->input('division')]),
            $divisions !== [] || $tournament->isParent() => $tournaments->saveWithDivisions($tournament, $data, $divisions),
            default => $tournaments->save($tournament, $data),
        });

        return back()->with('success', __('owner.flash.saved'));
    }

    public function entry(Request $request, TournamentEntry $entry, Tournaments $tournaments)
    {
        abort_unless($entry->tournament->venue_id === $this->venue($request)->id, 404);
        $data = $request->validate(['status' => 'nullable|in:applied,accepted,declined', 'pay' => 'nullable|in:card,cash']);
        $this->attempt(function () use ($data, $entry, $tournaments, $request) {
            if (! empty($data['status'])) {
                $tournaments->decide($entry, $data['status']);
            }
            if (! empty($data['pay'])) {
                $tournaments->pay($entry, $data['pay'], $request->user());
            }
        });

        return back()->with('success', __('owner.flash.saved'));
    }

    /** Take a player off the tournament (before it starts): a paid fee goes back. */
    public function remove(Request $request, TournamentEntry $entry, Tournaments $tournaments)
    {
        $this->own($request, $entry->tournament);
        $this->attempt(fn () => $tournaments->remove($entry, $request->user()));

        return back()->with('success', __('owner.tournament.removed'));
    }

    /** The draw (again, while nothing is played): who plays whom; the players hear about it. */
    public function draw(Request $request, Tournament $tournament, Tournaments $tournaments)
    {
        $this->own($request, $tournament);
        $this->attempt(fn () => $tournaments->draw($tournament));

        return back()->with('success', __('owner.tournament.drawn'));
    }

    /** A match on the bracket: the winner and score, or "being played now". */
    public function match(Request $request, TournamentMatch $match, Tournaments $tournaments)
    {
        $this->own($request, $match->tournament);
        $data = $request->validate(['winner' => 'nullable|integer', 'score' => 'nullable|string|max:20', 'live' => 'nullable|boolean', 'walkover' => 'nullable|integer',
            // the score of each side: the higher one wins ("3" and "1" → a wins 3:1)
            'score_a' => 'nullable|integer|min:0|max:99|required_with:score_b', 'score_b' => 'nullable|integer|min:0|max:99|required_with:score_a|different:score_a']);
        $this->attempt(function () use ($data, $match, $tournaments) {
            if (! empty($data['walkover'])) {
                $tournaments->walkover($match, (int) $data['walkover']);   // that player didn't come
            } elseif (isset($data['score_a'], $data['score_b'])) {
                [$a, $b] = [(int) $data['score_a'], (int) $data['score_b']];
                $tournaments->result($match, (int) ($a > $b ? $match->a_id : $match->b_id), "$a:$b");
            } elseif (! empty($data['winner'])) {
                $tournaments->result($match, (int) $data['winner'], $data['score'] ?? null);
            } elseif (array_key_exists('live', $data)) {
                $tournaments->setLive($match, (bool) $data['live']);
            }
        });

        return back();
    }

    /** News to everybody registered (app + Telegram), also written in the tournament chat. */
    public function announce(Request $request, Tournament $tournament, Tournaments $tournaments)
    {
        $this->own($request, $tournament);
        $data = $request->validate(['text' => 'required|string|max:1000', 'to' => 'nullable|in:'.implode(',', Tournaments::AUDIENCES)]);
        $n = $this->attempt(fn () => $tournaments->announce($tournament, $request->user(), $data['text'], $data['to'] ?? 'all'));

        return back()->with('success', __('owner.tournament.sent', ['n' => $n]));
    }

    /** "You haven't paid yet" to the accepted players with an unpaid fee. */
    public function remind(Request $request, Tournament $tournament, Tournaments $tournaments)
    {
        $this->own($request, $tournament);
        $n = $tournaments->remindUnpaid($tournament);

        return back()->with('success', __('owner.tournament.reminded', ['n' => $n]));
    }

    /** The tournament chat: staff join it as the organiser and go to it. */
    public function chat(Request $request, Tournament $tournament, Tournaments $tournaments)
    {
        $this->own($request, $tournament);
        $c = $tournaments->openChat($tournament, $request->user());

        return redirect()->route('messages.show', $c->id);
    }

    private function own(Request $request, Tournament $t): void
    {
        abort_unless($t->venue_id === $this->venue($request)->id, 404);
    }

    private function data(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:100', 'division' => 'nullable|string|max:40', 'date' => 'required|date_format:Y-m-d', 'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'description' => 'nullable|string|max:1000', 'status' => 'nullable|in:open,closed,done',
            'min_rating' => 'nullable|integer|min:0|max:4000', 'max_rating' => 'nullable|integer|min:0|max:4000', 'max_players' => 'nullable|integer|min:2|max:256',
            'options' => 'required|array|min:1|max:3', 'options.*.label' => 'nullable|string|max:40', 'options.*.price' => 'nullable|integer|min:0|max:10000000',
        ]);
        [$h, $m] = array_map('intval', explode(':', $data['time']));
        $venue = $this->venue($request);

        return $data + [
            'starts_at' => LocalTime::at(LocalTime::day($data['date']), $h + $m / 60)->utc(),
            'sport' => ($venue->sports()[0] ?? Sport::Football)->value,
        ];
    }

    /** Divisions by rating (2–6 rows: name, rating range, places); empty = one tournament. */
    private function divisions(Request $request): array
    {
        $data = $request->validate([
            'divisions' => 'nullable|array|max:'.Tournaments::MAX_DIVISIONS, 'divisions.*.id' => 'nullable|integer',
            'divisions.*.name' => 'nullable|string|max:40', 'divisions.*.min_rating' => 'nullable|integer|min:0|max:4000',
            'divisions.*.max_rating' => 'nullable|integer|min:0|max:4000', 'divisions.*.max_players' => 'nullable|integer|min:2|max:256',
        ]);

        return array_values(array_filter($data['divisions'] ?? [], fn ($d) => trim((string) ($d['name'] ?? '')) !== ''));
    }

    private function present(Tournament $t): array
    {
        $at = $t->starts_at->setTimezone(LocalTime::tz());

        return ['id' => $t->id, 'title' => $t->title, 'date' => $at->toDateString(), 'time' => $at->format('H:i'), 'description' => $t->description,
            'options' => $t->options, 'status' => $t->status, 'link' => $t->link(),
            'min_rating' => $t->min_rating, 'max_rating' => $t->max_rating, 'max_players' => $t->max_players, 'drawn' => $t->drawn_at !== null,
            'division' => $t->division, 'parent' => $t->parent_id ? ['id' => $t->parent_id, 'title' => $t->parent?->title] : null];
    }
}
