<?php

namespace App\Http\Controllers\Player;

use App\Domain\Booking\BookingException;
use App\Domain\Games\Games;
use App\Domain\Social\SocialException;
use App\Enums\GamePlayerStatus as P;
use App\Enums\GameStatus as S;
use App\Enums\Sport;
use App\Http\Controllers\Controller;
use App\Http\Presenters\PlayerGames;
use App\Http\Presenters\PlayerVenues;
use App\Models\Field;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use App\Models\Venue;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Games pages of the player web: open games + my games, create a game, the game page and its actions.
 * Every rule lives in App\Domain\Games\Games; here we only check input, call it and show its errors.
 */
class GameController extends Controller
{

    public const SIZES = ['small' => [3, 6], 'big' => [7, 11]];

    public function __construct(private Games $games, private PlayerGames $present) {}

    // ---------- pages ----------

    /** GET /games: open games (everyone) and "My games" (logged in). */
    public function index(Request $request, PlayerVenues $venues)
    {
        $request->session()->put('sport', 'football');   // games are football for now
        $me = $request->user();
        if (! $me) {
            // after logging in from this page, come back here
            $request->session()->put('url.intended', $request->fullUrl());
        }
        $f = [
            'size' => $this->pick($request->query('size'), ['small', 'big', ...array_map('strval', range(3, 11))]),
            'district' => $this->pick($request->query('district'), $this->districts()),
            'day' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('day')) ? $venues->pickDay($request->query('day'))->toDateString() : null,
            'time' => $this->pick($request->query('time'), ['morning', 'day', 'evening']),
        ];

        $open = Game::query()->with(PlayerGames::WITH)->visible()
            ->where(fn ($q) => $q->whereNull('when_to')->orWhere('when_to', '>', now()))
            ->where(fn ($q) => $q->where('status', '!=', 'booked')->orWhereHas('booking', fn ($b) => $b->where('ends_at', '>', now())))
            ->when($me, fn ($q) => $q->whereNotExists(fn ($s) => $s->from('user_blocks')
                ->where(fn ($w) => $w->whereColumn('blocker_id', 'games.creator_id')->where('blocked_id', $me->id))
                ->orWhere(fn ($w) => $w->where('blocker_id', $me->id)->whereColumn('blocked_id', 'games.creator_id'))))
            ->when($f['size'], function ($q, $size) {
                [$a, $b] = self::SIZES[$size] ?? [(int) $size, (int) $size];
                $q->whereBetween('players_per_side', [$a, $b]);
            })
            ->when($f['district'], fn ($q, $d) => $q->where(fn ($w) => $w->where('district', $d)->orWhereHas('venue', fn ($v) => $v->where('district', $d))))
            ->when($f['day'], function ($q, $day) {
                $from = LocalTime::day($day);
                $q->where(fn ($w) => $w->whereNull('when_from')->orWhere(fn ($x) => $x->where('when_from', '<', $from->addDay()->utc())->where('when_to', '>', $from->utc())));
            })
            ->orderByRaw('when_from is null')->orderBy('when_from')->latest('opened_at')
            ->limit(200)->get()
            ->filter(fn (Game $g) => $this->inTime($g, $f['time']))
            ->take(60)->values();

        $mine = null;
        if ($me) {
            $rows = Game::query()->with(PlayerGames::WITH)
                ->whereHas('players', fn ($q) => $q->where('user_id', $me->id)->whereIn('status', ['invited', 'accepted', 'joined']))
                ->latest('id')->limit(80)->get();
            $myStatus = fn (Game $g) => $g->players->firstWhere('user_id', $me->id)?->status->value;
            $card = fn (Game $g) => $this->present->card($g, $me);
            $mine = [
                'invites' => $rows->filter(fn ($g) => $myStatus($g) === 'invited' && in_array($g->status->value, ['forming', 'open'], true))->map($card)->values()->all(),
                'forming' => $rows->filter(fn ($g) => $myStatus($g) !== 'invited' && $g->status === S::Forming)->map($card)->values()->all(),
                'active' => $rows->filter(fn ($g) => $myStatus($g) !== 'invited' && in_array($g->status->value, ['open', 'full', 'booked'], true))->map($card)->values()->all(),
                'past' => $rows->filter(fn ($g) => $myStatus($g) !== 'invited' && in_array($g->status->value, ['played', 'cancelled', 'archived'], true))->take(20)->map($card)->values()->all(),
            ];
        }

        return Inertia::render('games/Index', [
            'tab' => $me && $request->query('tab') === 'mine' ? 'mine' : 'open',
            'filters' => $f,
            'days' => $venues->days(),
            'districts' => $this->districts(),
            'games' => $open->map(fn (Game $g) => $this->present->card($g, $me))->all(),
            'mine' => $mine,
            'canCreate' => (bool) $me,
        ]);
    }

    /** GET /games/new: the create form with my friends to invite. */
    public function create(Request $request, PlayerVenues $venues)
    {
        if ($redirect = $this->needsRegistration($request, route('games.create'))) {
            return $redirect;
        }
        $me = $request->user();
        $friends = User::whereIn('id', $me->friendIds())->orderBy('name')->get();

        return Inertia::render('games/Create', [
            'friends' => $friends->map(fn (User $u) => $this->present->person($u))->values()->all(),
            // games are football for now: places with games on and a football field
            'venues' => Venue::active()->with('fields')->orderBy('name')->get()
                ->filter(fn (Venue $v) => $v->hasFeature('games') && in_array(Sport::Football, $v->sports(), true))
                ->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'district' => $v->district])->values()->all(),
            'districts' => $this->districts(),
            'today' => LocalTime::now()->toDateString(),
            'days' => $venues->days(),
            'active' => Game::where('creator_id', $me->id)->whereIn('status', ['forming', 'open', 'full'])->count(),
            'maxActive' => Games::MAX_ACTIVE_PER_CREATOR,
            'friendsUrl' => \Illuminate\Support\Facades\Route::has('friends.index') ? route('friends.index') : null,
        ]);
    }

    /** POST /games */
    public function store(Request $request)
    {
        if ($redirect = $this->needsRegistration($request, route('games.create'))) {
            return $redirect;
        }
        $data = $request->validate([
            'players_per_side' => 'required|integer|min:3|max:11',
            'district' => ['nullable', Rule::in($this->districts())],
            'venue_id' => ['nullable', 'integer', Rule::exists('venues', 'id')->where('status', 'active')],
            'date' => 'nullable|required_with:from,to|date_format:Y-m-d',
            'from' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'to' => ['nullable', 'regex:/^(([01]\d|2[0-3]):[0-5]\d|24:00)$/'],
            'note' => 'nullable|string|max:200',
            'invite' => 'nullable|array|max:30',
            'invite.*' => 'integer',
        ], [
            'date.required_with' => __('games.error.date_needed'),
        ]);
        $window = $this->windowFrom($data);
        try {
            $game = $this->games->create($request->user(), [
                'players_per_side' => (int) $data['players_per_side'],
                'district' => $data['district'] ?? null, 'venue_id' => $data['venue_id'] ?? null,
                'note' => isset($data['note']) ? trim($data['note']) ?: null : null, ...$window,
            ], $data['invite'] ?? []);
        } catch (SocialException $e) {
            throw ValidationException::withMessages([$e->reason === 'invite_more' ? 'invite' : 'game' => $e->getMessage()]);
        }

        return redirect()->route('games.show', $game)->with('success', __('games.created'));
    }

    /** GET /games/{game} */
    public function show(Request $request, Game $game, PlayerVenues $venues)
    {
        $request->session()->put('sport', 'football');
        $me = $request->user();
        $game->load([...PlayerGames::WITH, 'conversation']);
        $row = $me ? $game->players->firstWhere('user_id', $me->id) : null;
        $public = in_array($game->status->value, ['open', 'full', 'booked', 'played'], true);
        $isCreator = $me && $me->id === $game->creator_id;
        $hasRow = $row && $row->status !== P::Removed;
        if (! $public && ! $isCreator && ! $hasRow) {
            if (! $me) {
                $request->session()->put('url.intended', $request->fullUrl());

                return redirect()->route('login');
            }
            abort(404);
        }
        if (! $me) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        $status = $game->status->value;
        $in = $row && $row->isIn();
        $holding = $game->booking && $game->booking->status->holdsTime();
        $blocked = $me && ! $isCreator && \App\Models\UserBlock::between($game->creator_id, $me->id);
        $can = [
            'respond' => $row?->status === P::Invited && in_array($status, ['forming', 'open'], true),
            'join' => $me && ! $in && $row?->status !== P::Removed && $row?->status !== P::Invited && $status === 'open' && ! $blocked,
            'joinLogin' => ! $me && $status === 'open',
            'leave' => $in && ! $isCreator && in_array($status, S::ACTIVE, true),
            'remove' => $isCreator && in_array($status, S::ACTIVE, true),
            'invite' => $in && in_array($status, ['forming', 'open'], true),
            'cancel' => $isCreator && in_array($status, S::ACTIVE, true),
            'book' => $isCreator && in_array($status, ['open', 'full'], true) && ! $holding,   // only the creator books the field
            'playAgain' => $in && in_array($status, ['played', 'archived'], true),
        ];

        $friends = [];
        if ($can['invite']) {
            $taken = $game->players->whereIn('status', [P::Invited, P::Accepted, P::Joined, P::Removed])->pluck('user_id')->all();
            $friends = User::whereIn('id', array_diff($me->friendIds(), $taken))->orderBy('name')->get()
                ->map(fn (User $u) => $this->present->person($u))->values()->all();
        }
        $places = [];
        if ($can['book']) {
            $places = Venue::active()->with(['fields' => fn ($q) => $q->where('active', true)->whereIn('size', Sport::Football->sizeValues())->orderBy('sort')])->orderBy('name')->get()
                ->filter(fn (Venue $v) => $v->hasFeature('games'))
                ->map(fn (Venue $v) => ['id' => $v->id, 'slug' => $v->slug, 'name' => $v->name, 'district' => $v->district,
                    'fields' => $v->fields->map(fn (Field $f) => ['id' => $f->id, 'size' => $f->size->value, 'format' => $f->format ?: $f->size->format(),
                        'durations' => array_values($f->durations ?? [60]), 'defaultDuration' => $f->default_duration])->values()->all()])
                ->filter(fn ($v) => $v['fields'])->values()->all();
        }

        return Inertia::render('games/Show', [
            'game' => [
                ...$this->present->card($game, $me),
                'booking' => $this->present->booking($game->booking),
                'previousId' => $game->previous_game_id,
                'invitedCount' => $game->players->where('status', P::Invited)->count(),
            ],
            'players' => $this->present->players($game, $me),
            'can' => [...$can, 'position' => $in && in_array($status, S::ACTIVE, true)],
            // the position choice (join, accept, change) starts from my profile position
            'positions' => GamePlayer::POSITIONS,
            'profilePosition' => $me?->position,
            'conversationId' => $in && $game->conversation ? $game->conversation->id : null,
            'friends' => $friends,
            'places' => $places,
            'days' => $can['book'] ? $venues->days() : [],
        ]);
    }

    // ---------- actions ----------

    public function respond(Request $request, Game $game)
    {
        $accept = $request->boolean('accept');
        $position = $this->positionFrom($request);

        return $this->run(fn () => $this->games->respond($game, $request->user(), $accept, $position), $accept ? __('games.flash.accepted') : __('games.flash.declined'), $request);
    }

    public function join(Request $request, Game $game)
    {
        $position = $this->positionFrom($request);

        return $this->run(fn () => $this->games->join($game, $request->user(), $position), __('games.flash.joined'), $request);
    }

    /** PATCH /games/{game}/position: change or clear my own position in the game. */
    public function position(Request $request, Game $game)
    {
        $position = $this->positionFrom($request);

        return $this->run(fn () => $this->games->setPosition($game, $request->user(), $position), $position ? __('games.flash.position') : __('games.flash.position_cleared'), $request);
    }

    public function leave(Request $request, Game $game)
    {
        return $this->run(fn () => $this->games->leave($game, $request->user()), __('games.flash.left'), $request);
    }

    public function remove(Request $request, Game $game, User $user)
    {
        return $this->run(fn () => $this->games->remove($game, $request->user(), $user), __('games.flash.removed', ['name' => $user->name]), $request);
    }

    public function invite(Request $request, Game $game)
    {
        $data = $request->validate(['ids' => 'required|array|min:1|max:30', 'ids.*' => 'integer']);
        $n = 0;

        return $this->run(function () use ($game, $request, $data, &$n) {
            $n = $this->games->invite($game, $request->user(), $data['ids']);
        }, fn () => trans_choice('games.flash.invited', $n, ['n' => $n]), $request);
    }

    public function cancel(Request $request, Game $game)
    {
        return $this->run(fn () => $this->games->cancel($game, $request->user()), __('games.flash.cancelled'), $request);
    }

    /** POST /games/{game}/book: a booking request for the game (the same rules as a normal request). */
    public function book(Request $request, Game $game)
    {
        $data = $request->validate([
            'field_id' => 'required|integer',
            'date' => 'required|date_format:Y-m-d',
            'start' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'minutes' => 'required|integer|min:30|max:600',
        ]);
        $field = Field::with(['venue.openingHours', 'priceRules'])->where('active', true)->find($data['field_id']);
        if (! $field || $field->venue->status !== 'active') {
            throw ValidationException::withMessages(['booking' => __('booking.error.closed')]);
        }
        [$h, $m] = array_map('intval', explode(':', $data['start']));
        $start = LocalTime::at(LocalTime::day($data['date']), $h + $m / 60);
        try {
            $this->games->book($game, $request->user(), $field, $start, (int) $data['minutes']);
        } catch (SocialException|BookingException $e) {
            throw ValidationException::withMessages(['booking' => $e->getMessage()]);
        }

        return redirect()->route('games.show', $game)->with('success', __('games.flash.booked'));
    }

    public function playAgain(Request $request, Game $game)
    {
        try {
            $new = $this->games->playAgain($game, $request->user());
        } catch (SocialException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('games.show', $new)->with('success', __('games.created'));
    }

    // ---------- helpers ----------

    /** Run a domain action; its SocialException becomes an error message. */
    private function run(callable $action, string|\Closure $done, Request $request)
    {
        if ($redirect = $this->needsRegistration($request, url()->previous())) {
            return $redirect;
        }
        try {
            $action();
        } catch (SocialException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $done instanceof \Closure ? $done() : $done);
    }

    /** The optional position of join / accept / change; empty means "no position". */
    private function positionFrom(Request $request): ?string
    {
        $data = $request->validate(['position' => ['nullable', 'string', Rule::in(GamePlayer::POSITIONS)]]);

        return $data['position'] ?? null;
    }

    /** Games need a finished registration (a name the other players can see). */
    private function needsRegistration(Request $request, string $back)
    {
        if ($request->user()->isRegistered()) {
            return null;
        }
        $request->session()->put('url.intended', $back);

        return redirect()->route('welcome');
    }

    /** when_from / when_to from date + from/to (local time), checked: in the future, within 30 days, to after from. */
    private function windowFrom(array $data): array
    {
        if (empty($data['date'])) {
            return [];
        }
        $day = LocalTime::day($data['date']);
        $today = LocalTime::now()->startOfDay();
        if ($day->lessThan($today) || $day->greaterThan($today->addDays(30))) {
            throw ValidationException::withMessages(['date' => __('games.error.date_range')]);
        }
        $hour = fn (?string $t, float $default) => $t ? (int) substr($t, 0, 2) + (int) substr($t, 3, 2) / 60 : $default;
        $from = $hour($data['from'] ?? null, 0);
        $to = $hour($data['to'] ?? null, 24);
        if ($to <= $from) {
            throw ValidationException::withMessages(['to' => __('common.end_after_start')]);
        }
        $whenTo = LocalTime::at($day, $to);
        if ($whenTo->isPast()) {
            throw ValidationException::withMessages(['from' => __('games.error.past')]);
        }

        return ['when_from' => LocalTime::at($day, $from)->utc(), 'when_to' => $whenTo->utc()];
    }

    /** Time-of-day filter on the game's window start (or its booking); games without a time always match. */
    private function inTime(Game $g, ?string $time): bool
    {
        if (! $time) {
            return true;
        }
        $at = $g->booking && $g->booking->status->holdsTime() ? $g->booking->starts_at : $g->when_from;
        $w = $this->present->window($g);
        if (! $at || ($w && $w['from'] === null && ! $g->booking)) {
            return true;
        }

        return PlayerVenues::inTime(LocalTime::hourOf($at->toImmutable()), $time);
    }

    private function pick(mixed $v, array $allowed): ?string
    {
        return is_string($v) && in_array($v, $allowed, true) ? $v : null;
    }

    private function districts(): array
    {
        return array_keys((array) trans('common.districts'));
    }
}
