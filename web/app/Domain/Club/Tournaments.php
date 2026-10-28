<?php

namespace App\Domain\Club;

use App\Domain\Booking\BookingException;
use App\Domain\Chat\Chat;
use App\Domain\Club\Events\ClubChanged;
use App\Domain\Club\Events\TournamentChanged;
use App\Models\Conversation;
use App\Models\Payment;
use App\Models\SportRating;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Models\VenueCard;
use App\Notifications\Notice;
use App\Support\LocalTime;
use App\Support\Phone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tournaments: the place opens one with up to 3 price options and puts its link (/t/{code}) in the Instagram bio.
 * People register with name, surname, phone and level (no account needed), within the rating range and the number of
 * players the place set; the place accepts them (they hear about it), takes the fee, makes the draw (any time before,
 * so players know who they play), marks the winners on the bracket (live for everybody) and talks to the accepted
 * players in the tournament chat or sends them news. The results stay as the history.
 */
class Tournaments
{
    public function __construct(private Money $money, private Chat $chat) {}

    public function save(Tournament $t, array $data): Tournament
    {
        if (! $t->exists && ! $t->venue?->hasFeature('tournaments')) {
            throw new BookingException('feature_off');
        }
        $options = array_values(array_filter(array_map(fn ($o) => ['label' => trim((string) ($o['label'] ?? '')), 'price' => max(0, (int) ($o['price'] ?? 0))],
            $data['options'] ?? []), fn ($o) => $o['label'] !== ''));
        if (! $options || count($options) > 3) {
            throw new BookingException('bad_options');
        }
        $min = isset($data['min_rating']) && $data['min_rating'] !== '' ? (int) $data['min_rating'] : null;
        $max = isset($data['max_rating']) && $data['max_rating'] !== '' ? (int) $data['max_rating'] : null;
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }
        $t->fill(['title' => trim($data['title']), 'sport' => $data['sport'], 'starts_at' => $data['starts_at'],
            'description' => $data['description'] ?? null, 'options' => $options, 'status' => $data['status'] ?? ($t->status ?: 'open'),
            'min_rating' => $min, 'max_rating' => $max,
            'max_players' => isset($data['max_players']) && $data['max_players'] !== '' ? max(2, (int) $data['max_players']) : null]);
        if (! $t->code) {
            $t->code = Str::lower(Str::random(8));
        }
        $t->save();

        return $t;
    }

    public const MAX_DIVISIONS = 6;

    /**
     * A tournament with divisions by rating: the parent (its link lists the divisions; nobody registers on it) and one
     * full tournament per division ({name, min_rating, max_rating, max_players, id?}) with the parent's date, note,
     * prices and status; the division's title is "Cup · Amateur". A division left out is removed when nobody is in it.
     */
    public function saveWithDivisions(Tournament $parent, array $data, array $divisions): Tournament
    {
        $rows = array_values(array_filter(array_map(fn ($d) => [
            'id' => isset($d['id']) ? (int) $d['id'] : null, 'name' => trim((string) ($d['name'] ?? '')),
            'min_rating' => $d['min_rating'] ?? null, 'max_rating' => $d['max_rating'] ?? null, 'max_players' => $d['max_players'] ?? null,
        ], $divisions), fn ($d) => $d['name'] !== ''));
        if (count($rows) < 2 || count($rows) > self::MAX_DIVISIONS) {
            throw new BookingException('bad_divisions');
        }
        if ($parent->exists && $parent->entries()->exists()) {
            throw new BookingException('bad_divisions');   // a tournament with players can't be split any more
        }

        return DB::transaction(function () use ($parent, $data, $rows) {
            $parent = $this->save($parent, ['min_rating' => null, 'max_rating' => null, 'max_players' => null] + $data);
            $keep = [];
            foreach ($rows as $d) {
                $child = $d['id'] ? $parent->divisions()->find($d['id']) : null;
                $child ??= new Tournament(['venue_id' => $parent->venue_id]);
                $child->setRelation('venue', $parent->venue);
                $child->forceFill(['parent_id' => $parent->id, 'division' => mb_substr($d['name'], 0, 40)]);
                $this->save($child, ['title' => $parent->title.' · '.$child->division, 'status' => $child->exists ? $child->status : $parent->status,
                    'min_rating' => $d['min_rating'], 'max_rating' => $d['max_rating'], 'max_players' => $d['max_players']] + $data);
                $keep[] = $child->id;
            }
            foreach ($parent->divisions()->whereNotIn('id', $keep)->get() as $gone) {
                if (! $gone->entries()->exists()) {
                    $gone->delete();
                }
            }

            return $parent->fresh('divisions');
        });
    }

    /** A division's settings: its own title is "Parent · name". */
    public function saveDivision(Tournament $child, array $data): Tournament
    {
        $parent = $child->parent;
        $child->division = mb_substr(trim((string) ($data['division'] ?? $child->division)), 0, 40) ?: $child->division;

        return $this->save($child, ['title' => $parent->title.' · '.$child->division] + $data);
    }

    public function register(Tournament $t, array $data, ?User $user = null): TournamentEntry
    {
        if ($t->isParent()) {
            throw new BookingException('choose_division');
        }
        if (! self::registrationOpen($t)) {
            throw new BookingException($t->drawn_at ? 'already_drawn_closed' : 'tournament_closed');
        }
        $phone = Phone::normalize($data['phone'] ?? null);
        if (! $phone) {
            throw new BookingException('bad_phone');
        }
        if (! isset($t->options[(int) $data['option']])) {
            throw new BookingException('bad_options');
        }
        if (TournamentEntry::where('tournament_id', $t->id)->where(fn ($q) => $q->where('phone', $phone)->when($user, fn ($w) => $w->orWhere('user_id', $user->id)))->exists()) {
            throw new BookingException('already_registered');
        }
        if ($t->max_players && TournamentEntry::where('tournament_id', $t->id)->where('status', '!=', 'declined')->count() >= $t->max_players) {
            throw new BookingException('tournament_full');
        }
        $userId = $user?->id ?? User::where('phone', $phone)->value('id');
        // the rating range: the player's rating in this sport (kept by the clubs), else the level they give
        if ($t->min_rating !== null || $t->max_rating !== null) {
            $rating = self::ratingOf($t, $userId, $data['level'] ?? null);
            if ($rating === null) {
                throw new BookingException('rating_needed');
            }
            if (($t->min_rating !== null && $rating < $t->min_rating) || ($t->max_rating !== null && $rating > $t->max_rating)) {
                throw new BookingException('rating_out_of_range');
            }
        }

        return TournamentEntry::create(['tournament_id' => $t->id, 'user_id' => $userId,
            'first_name' => trim($data['first_name']), 'last_name' => trim($data['last_name']), 'phone' => $phone,
            'level' => isset($data['level']) && $data['level'] !== '' ? (int) $data['level'] : null, 'option' => (int) $data['option']]);
    }

    public function decide(TournamentEntry $e, string $status): TournamentEntry
    {
        if (! in_array($status, ['applied', 'accepted', 'declined'], true)) {
            throw new BookingException('bad_options');
        }
        $e->loadMissing('tournament.venue');
        $t = $e->tournament;
        if ($t->status === 'cancelled') {
            throw new BookingException('tournament_closed');
        }
        if ($status === 'accepted' && $e->status !== 'accepted' && $t->max_players
            && $t->entries()->where('status', 'accepted')->count() >= $t->max_players) {
            throw new BookingException('tournament_full');
        }
        // after the draw the players are fixed: redo the draw first (an accepted one would not be in the bracket)
        if ($t->drawn_at && ($status === 'accepted') !== ($e->status === 'accepted')) {
            throw new BookingException('already_drawn');
        }
        $was = $e->status;
        DB::transaction(function () use ($e, $t, $status, $was) {
            $e->update(['status' => $status]);
            if ($e->user_id && $was !== $status) {
                // accepted players are in the tournament chat; the answer reaches them in the app and on Telegram
                if ($status === 'accepted') {
                    $this->chat->join($this->chatOf($t), $e->user_id);
                } elseif ($was === 'accepted' && $t->conversation) {
                    $this->chat->leave($t->conversation, $e->user_id);
                }
                if ($status !== 'applied') {
                    $user = User::find($e->user_id);
                    // accepted with a fee still to pay: the message says how much
                    $fee = $status === 'accepted' && ! $e->payment_id ? self::fee($t, $e) : 0;
                    $type = 'tournament_'.$status.($fee ? '_fee' : '');
                    DB::afterCommit(fn () => $user?->notify(new Notice($type, ['title' => $t->title, 'venue' => $t->venue->name,
                        'when' => self::when($t)] + ($fee ? ['amount' => Cards::amd($fee)] : []), '/t/'.$t->code)));
                }
            }
        });
        // the player's page shows the answer at once
        TournamentChanged::dispatch($t->code);

        return $e;
    }

    /** The divisions of a tournament (empty: none), loaded or not. */
    public static function divisionsOf(Tournament $t): Collection
    {
        return $t->relationLoaded('divisions') ? $t->divisions : $t->divisions()->get();
    }

    /** Registration: open, not started, not drawn yet (after the draw the bracket is fixed). */
    public static function registrationOpen(Tournament $t): bool
    {
        return $t->status === 'open' && $t->starts_at->isFuture() && ! $t->drawn_at;
    }

    /**
     * Where the tournament is, for its page: registration | closed (by the club) | drawn (pairs known, not started) |
     * live (a match played or being played) | done.
     */
    public static function phase(Tournament $t): string
    {
        // a tournament with divisions: where its divisions are (one on = on; all over = over)
        if ($t->isParent()) {
            $all = self::divisionsOf($t)->map(fn (Tournament $d) => self::phase($d));
            foreach (['live', 'registration', 'drawn', 'closed', 'done'] as $p) {
                if ($all->contains($p)) {
                    return $p;
                }
            }

            return 'cancelled';
        }
        if ($t->status === 'cancelled') {
            return 'cancelled';
        }
        if ($t->status === 'done') {
            return 'done';
        }
        if ($t->drawn_at) {
            $started = TournamentMatch::where('tournament_id', $t->id)->where(fn ($q) => $q->where('live', true)
                ->orWhere(fn ($w) => $w->whereNotNull('winner_id')->whereNotNull('a_id')->whereNotNull('b_id')))->exists();

            return $started || $t->starts_at->isPast() ? 'live' : 'drawn';
        }

        return self::registrationOpen($t) ? 'registration' : 'closed';
    }

    // ---------- chat and news ----------

    /** The tournament chat: the accepted players (with an account) and the place's owners; made on first use. */
    public function chatOf(Tournament $t): Conversation
    {
        $c = Conversation::firstOrCreate(['tournament_id' => $t->id], ['type' => 'tournament', 'last_message_at' => now()]);
        if ($c->wasRecentlyCreated) {
            foreach ($t->venue->staff()->wherePivot('role', 'owner')->pluck('users.id') as $uid) {
                $this->chat->join($c, $uid);
            }
            foreach ($t->entries()->where('status', 'accepted')->whereNotNull('user_id')->pluck('user_id') as $uid) {
                $this->chat->join($c, $uid);
            }
            $this->chat->system($c, 'chat.sys.tournament_opened', ['title' => $t->title]);
        }
        $t->setRelation('conversation', $c);

        return $c;
    }

    /** Staff open the chat from the owner page: they become a member (as the organiser). */
    public function openChat(Tournament $t, User $staff): Conversation
    {
        $c = $this->chatOf($t);
        $this->chat->join($c, $staff->id);

        return $c;
    }

    public const AUDIENCES = ['all', 'accepted', 'applied'];

    /**
     * News from the organiser to the registered players with an account: 'all' (not declined), 'accepted' or 'applied'
     * (still waiting for the answer). A notification (app + Telegram); for the accepted ones the same text goes into the
     * tournament chat too — the waiting ones are not in the chat and never see it. Returns how many were told.
     */
    public function announce(Tournament $t, User $by, string $text, string $to = 'all'): int
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 1000 || ! in_array($to, self::AUDIENCES, true)) {
            throw new BookingException('bad_options');
        }
        if ($t->isParent()) {
            return self::divisionsOf($t)->filter(fn (Tournament $d) => ! in_array($d->status, ['done', 'cancelled'], true))->sum(fn (Tournament $d) => $this->announce($d, $by, $text, $to));
        }
        // a finished tournament: its chat is closed (the history stays), no more news
        if (in_array($t->status, ['done', 'cancelled'], true)) {
            throw new BookingException('tournament_over');
        }
        if ($to !== 'applied') {
            $this->chat->send($this->openChat($t, $by), $by, $text);
        }
        $q = $t->entries()->whereNotNull('user_id');
        match ($to) {
            'accepted' => $q->where('status', 'accepted'),
            'applied' => $q->where('status', 'applied'),
            default => $q->where('status', '!=', 'declined'),
        };
        $users = User::whereIn('id', $q->pluck('user_id'))->get();
        foreach ($users as $u) {
            $u->notify(new Notice('tournament_news', ['title' => $t->title, 'text' => mb_substr($text, 0, 300)], '/t/'.$t->code));
        }

        return $users->count();
    }

    // ---------- the fee ----------

    /** Accepted players who still owe their fee: the tournament starts (a match live / a result) only when everybody paid. */
    public static function unpaid(Tournament $t): int
    {
        return $t->entries()->where('status', 'accepted')->whereNull('payment_id')->get()->filter(fn ($e) => self::fee($t, $e) > 0)->count();
    }

    private static function ensurePaid(Tournament $t): void
    {
        if (self::unpaid($t) > 0) {
            throw new BookingException('unpaid_first');
        }
    }

    /** The fee of the entry's option (0: free). */
    public static function fee(Tournament $t, TournamentEntry $e): int
    {
        return (int) ($t->options[$e->option]['price'] ?? 0);
    }

    /**
     * "You haven't paid for the tournament yet": to the accepted players with a fee and no payment (with an account).
     * The owner's button, and by itself the day before (scheduler). Returns how many were told.
     */
    public function remindUnpaid(Tournament $t): int
    {
        $n = 0;
        if (in_array($t->status, ['done', 'cancelled'], true)) {
            return 0;
        }
        foreach ($t->entries()->where('status', 'accepted')->whereNull('payment_id')->whereNotNull('user_id')->get() as $e) {
            $fee = self::fee($t, $e);
            if ($fee > 0 && ($user = User::find($e->user_id))) {
                $user->notify(new Notice('tournament_unpaid', ['title' => $t->title, 'amount' => Cards::amd($fee), 'when' => self::when($t)], '/t/'.$t->code));
                $n++;
            }
        }

        return $n;
    }

    /** Scheduler, daily: the tournaments starting tomorrow remind their unpaid players. */
    public function remindTomorrow(): int
    {
        $from = LocalTime::now()->startOfDay()->addDay();
        $n = 0;
        foreach (Tournament::whereIn('status', ['open', 'closed'])->whereBetween('starts_at', [$from->utc(), $from->addDay()->utc()])->get() as $t) {
            $n += $this->remindUnpaid($t);
        }

        return $n;
    }

    // ---------- the draw and the bracket ----------

    /**
     * The draw: the accepted players by rating (the best ones meet last), the bracket rounded up to 4, 8, 16 …, the
     * best seeds get a bye when there are fewer players. Can be done days before; redone while no match is played.
     */
    public function draw(Tournament $t): void
    {
        $t->loadMissing('venue');
        if (in_array($t->status, ['done', 'cancelled'], true)) {
            throw new BookingException('tournament_closed');
        }
        if ($t->matches()->whereNotNull('winner_id')->whereNotNull('a_id')->whereNotNull('b_id')->exists()) {
            throw new BookingException('already_started');
        }
        $entries = $t->entries()->where('status', 'accepted')->get()
            ->sortByDesc(fn (TournamentEntry $e) => [self::ratingOf($t, $e->user_id, $e->level) ?? -1, -$e->id])->values();
        if ($entries->count() < 2) {
            throw new BookingException('too_few_players');
        }
        $size = 2;
        while ($size < $entries->count()) {
            $size *= 2;
        }
        $rounds = (int) log($size, 2);
        $order = self::seedOrder($size);   // seed numbers (1 = best) in bracket order

        DB::transaction(function () use ($t, $entries, $size, $rounds, $order) {
            TournamentMatch::where('tournament_id', $t->id)->delete();
            for ($r = 1; $r <= $rounds; $r++) {
                for ($s = 0; $s < $size >> $r; $s++) {
                    $m = ['tournament_id' => $t->id, 'round' => $r, 'slot' => $s];
                    if ($r === 1) {
                        $m['a_id'] = $entries[$order[2 * $s] - 1]->id ?? null;
                        $m['b_id'] = $entries[$order[2 * $s + 1] - 1]->id ?? null;
                    }
                    TournamentMatch::create($m);
                }
            }
            // byes: the player goes straight on
            foreach (TournamentMatch::where('tournament_id', $t->id)->where('round', 1)->get() as $m) {
                if (($m->a_id === null) !== ($m->b_id === null)) {
                    $m->update(['winner_id' => $m->a_id ?? $m->b_id]);
                    $this->advance($t, $m, $rounds);
                }
            }
            $t->update(['drawn_at' => now()]);
            TournamentEntry::where('tournament_id', $t->id)->update(['place' => null]);
            // everybody hears who they play first
            $matches = TournamentMatch::where('tournament_id', $t->id)->where('round', 1)->get();
            $byId = $entries->keyBy('id');
            foreach ($matches as $m) {
                foreach ([[$m->a_id, $m->b_id], [$m->b_id, $m->a_id]] as [$me, $vs]) {
                    $e = $me ? $byId[$me] : null;
                    if ($e?->user_id) {
                        $user = User::find($e->user_id);
                        $params = ['title' => $t->title, 'vs' => $vs ? self::name($byId[$vs]) : '—', 'when' => self::when($t)];
                        DB::afterCommit(fn () => $user?->notify(new Notice($vs ? 'tournament_draw' : 'tournament_bye', $params, '/t/'.$t->code)));
                    }
                }
            }
            if ($t->conversation()->exists() || $t->entries()->where('status', 'accepted')->whereNotNull('user_id')->exists()) {
                $this->chat->system($this->chatOf($t), 'chat.sys.tournament_drawn', ['title' => $t->title]);
            }
        });
        TournamentChanged::dispatch($t->code);
        ClubChanged::dispatch($t->venue_id);
    }

    /** A match is being played now (a red dot on the bracket), or not any more. */
    public function setLive(TournamentMatch $m, bool $live): void
    {
        if ($live) {
            self::ensurePaid($m->tournament);
        }
        $m->update(['live' => $live && ! $m->winner_id && $m->a_id && $m->b_id]);
        TournamentChanged::dispatch($m->tournament->code);
    }

    /**
     * The organiser marks the winner (and the score): the winner moves on, both players hear it, the chat gets a line,
     * the bracket updates live. The final decides 1st and 2nd, the semi-final losers are 3rd. A result can be changed
     * while the winner's next match is not played.
     */
    public function result(TournamentMatch $m, int $winnerId, ?string $score = null): TournamentMatch
    {
        $m->loadMissing('tournament.venue', 'a', 'b');
        $t = $m->tournament;
        if (! $m->a_id || ! $m->b_id || ! in_array($winnerId, [$m->a_id, $m->b_id], true)) {
            throw new BookingException('bad_options');
        }
        $rounds = (int) TournamentMatch::where('tournament_id', $t->id)->max('round');
        $next = $m->round < $rounds ? TournamentMatch::where('tournament_id', $t->id)->where('round', $m->round + 1)->where('slot', intdiv($m->slot, 2))->first() : null;
        if ($next?->winner_id && $next->a_id && $next->b_id) {
            throw new BookingException('already_started');
        }
        self::ensurePaid($t);
        $score = $score !== null ? mb_substr(trim($score), 0, 20) : null;

        DB::transaction(function () use ($m, $t, $winnerId, $score, $rounds) {
            $m->update(['winner_id' => $winnerId, 'score' => $score ?: null, 'live' => false]);
            $this->advance($t, $m, $rounds);
            $winner = $winnerId === $m->a_id ? $m->a : $m->b;
            $loser = $winnerId === $m->a_id ? $m->b : $m->a;
            if ($m->round === $rounds) {
                $winner->update(['place' => 1]);
                $loser->update(['place' => 2]);
                $t->update(['status' => 'done']);
                // the last division over: the whole tournament is over
                if ($t->parent_id && ! Tournament::where('parent_id', $t->parent_id)->whereNotIn('status', ['done', 'cancelled'])->exists()) {
                    Tournament::whereKey($t->parent_id)->update(['status' => 'done']);
                }
                // the chat stays readable (the history), nobody writes any more; it is not deleted
                if ($c = $t->conversation()->first()) {
                    $this->chat->system($c, 'chat.sys.match_result', ['title' => $t->title, 'winner' => self::name($winner), 'loser' => self::name($loser), 'score' => $score ?: '—']);
                    $this->chat->system($c, 'chat.sys.tournament_over', ['title' => $t->title, 'winner' => self::name($winner)]);
                    $c->update(['status' => 'archived', 'archived_at' => now(), 'delete_after' => null]);
                }
            } elseif ($m->round === $rounds - 1) {
                $loser->update(['place' => 3]);
            }
            $params = ['title' => $t->title, 'winner' => self::name($winner), 'loser' => self::name($loser), 'score' => $score ?: '—'];
            foreach ([$winner, $loser] as $e) {
                if ($e->user_id) {
                    $user = User::find($e->user_id);
                    $type = $m->round === $rounds && $e->id === $winner->id ? 'tournament_champion' : 'tournament_result';
                    DB::afterCommit(fn () => $user?->notify(new Notice($type, $params, '/t/'.$t->code)));
                }
            }
            if ($m->round !== $rounds && $t->conversation()->exists()) {
                $this->chat->system($this->chatOf($t), 'chat.sys.match_result', $params);
            }
        });
        TournamentChanged::dispatch($t->code);
        ClubChanged::dispatch($t->venue_id);

        return $m;
    }

    /** The winner of $m goes into the next round's match (slot / 2; an even slot is side a). */
    private function advance(Tournament $t, TournamentMatch $m, int $rounds): void
    {
        if ($m->round >= $rounds) {
            return;
        }
        TournamentMatch::where('tournament_id', $t->id)->where('round', $m->round + 1)->where('slot', intdiv($m->slot, 2))
            ->update([$m->slot % 2 === 0 ? 'a_id' : 'b_id' => $m->winner_id, 'winner_id' => null, 'score' => null]);
    }

    /** Standard seeding: 1 meets 16, 8 meets 9 … and 1 and 2 can only meet in the final. */
    public static function seedOrder(int $size): array
    {
        $order = [1, 2];
        while (count($order) < $size) {
            $n = count($order) * 2 + 1;
            $order = collect($order)->flatMap(fn ($s) => [$s, $n - $s])->all();
        }

        return $order;
    }

    /** A player's rating for a tournament: their rating in its sport, else the level they gave. */
    public static function ratingOf(Tournament $t, ?int $userId, $level): ?int
    {
        $r = $userId ? SportRating::where('user_id', $userId)->where('sport', $t->sport)->value('rating') : null;

        return $r !== null ? (int) $r : ($level !== null && $level !== '' ? (int) $level : null);
    }

    public static function name(TournamentEntry $e): string
    {
        return trim($e->first_name.' '.$e->last_name);
    }

    private static function when(Tournament $t): string
    {
        return $t->starts_at->setTimezone(LocalTime::tz())->format('d.m H:i');
    }

    /**
     * The organiser takes a player off the tournament before it starts (no match played or being played). A paid fee
     * goes back (to the card, or the desk hands the cash back: a minus payment either way); an accepted player after
     * the draw: the draw is undone (make it again). The player hears about it and leaves the chat.
     */
    public function remove(TournamentEntry $e, User $by): void
    {
        $e->loadMissing('tournament.venue', 'payment');
        $t = $e->tournament;
        if (in_array(self::phase($t), ['live', 'done', 'cancelled'], true)) {
            throw new BookingException('tournament_started');
        }
        DB::transaction(function () use ($e, $t, $by) {
            $this->refund($e, $t, $by);
            if ($t->drawn_at && $e->status === 'accepted') {
                TournamentMatch::where('tournament_id', $t->id)->delete();
                $t->update(['drawn_at' => null]);
            }
            if ($e->user_id && $t->conversation) {
                $this->chat->leave($t->conversation, $e->user_id);
            }
            if ($e->user_id && $e->status !== 'declined' && ($user = User::find($e->user_id))) {
                DB::afterCommit(fn () => $user->notify(new Notice('tournament_removed', ['title' => $t->title, 'venue' => $t->venue->name], '/t/'.$t->code)));
            }
            $e->delete();
        });
        TournamentChanged::dispatch($t->code);
        ClubChanged::dispatch($t->venue_id);
    }

    /** The fee back: a minus payment (the report and ZoneSpot's fee go down); paid by card: the money goes back on it. */
    private function refund(TournamentEntry $e, Tournament $t, User $by): void
    {
        $p = $e->payment;
        if (! $p) {
            return;
        }
        $refund = Payment::create(['venue_id' => $p->venue_id, 'user_id' => $p->user_id, 'card_id' => $p->card_id, 'kind' => 'tournament',
            'method' => $p->method, 'amount' => -$p->amount, 'ref_id' => $e->id, 'note' => mb_substr(__('owner.tournament.refund_note', ['title' => $t->title]), 0, 200), 'created_by' => $by->id]);
        if ($p->method === 'card' && $p->card_id && ($card = VenueCard::lockForUpdate()->find($p->card_id))) {
            app(Cards::class)->entry($card, $p->amount, 'adjust', $by, $refund->note, $refund);
        }
    }

    /**
     * Cancel before the start (not enough players, the place closed …): every paid fee goes back, everybody registered
     * hears the reason (app + Telegram), the chat says it and closes. A tournament with divisions: every division not
     * started yet; it is cancelled itself when none is left to play.
     */
    public function cancel(Tournament $t, User $by, string $reason): void
    {
        $reason = mb_substr(trim($reason), 0, 300);
        if ($reason === '') {
            throw new BookingException('bad_options');
        }
        if ($t->isParent()) {
            $left = self::divisionsOf($t)->filter(fn (Tournament $d) => ! in_array(self::phase($d), ['live', 'done', 'cancelled'], true));
            if ($left->isEmpty()) {
                throw new BookingException('tournament_started');
            }
            foreach ($left as $d) {
                $this->cancel($d->setRelation('venue', $t->venue), $by, $reason);
            }
            if (! $t->divisions()->whereNotIn('status', ['cancelled'])->exists()) {
                $t->update(['status' => 'cancelled', 'cancel_reason' => $reason]);
            }
            TournamentChanged::dispatch($t->code);

            return;
        }
        if (in_array(self::phase($t), ['live', 'done', 'cancelled'], true)) {
            throw new BookingException('tournament_started');
        }
        $t->loadMissing('venue');
        DB::transaction(function () use ($t, $by, $reason) {
            foreach ($t->entries()->with('payment')->get() as $e) {
                if ($e->payment) {
                    $this->refund($e, $t, $by);
                    $e->update(['payment_id' => null]);
                }
                if ($e->user_id && $e->status !== 'declined' && ($user = User::find($e->user_id))) {
                    DB::afterCommit(fn () => $user->notify(new Notice('tournament_cancelled', ['title' => $t->title, 'venue' => $t->venue->name, 'reason' => $reason], '/t/'.$t->code)));
                }
            }
            $t->update(['status' => 'cancelled', 'cancel_reason' => $reason]);
            if ($c = $t->conversation()->first()) {
                $this->chat->system($c, 'chat.sys.tournament_cancelled', ['title' => $t->title, 'reason' => $reason]);
                $c->update(['status' => 'archived', 'archived_at' => now(), 'delete_after' => null]);
            }
            // the last division left: the whole tournament is over / cancelled
            if ($t->parent_id && ! Tournament::where('parent_id', $t->parent_id)->whereNotIn('status', ['done', 'cancelled'])->exists()) {
                $all = ! Tournament::where('parent_id', $t->parent_id)->where('status', 'done')->exists();
                Tournament::whereKey($t->parent_id)->update($all ? ['status' => 'cancelled', 'cancel_reason' => $reason] : ['status' => 'done']);
            }
        });
        TournamentChanged::dispatch($t->code);
        ClubChanged::dispatch($t->venue_id);
    }

    /** A player didn't come: the other one wins the match without playing ("W/O", a technical loss). */
    public function walkover(TournamentMatch $m, int $absentId): TournamentMatch
    {
        if (! $m->a_id || ! $m->b_id || ! in_array($absentId, [$m->a_id, $m->b_id], true)) {
            throw new BookingException('bad_options');
        }

        return $this->result($m, $absentId === $m->a_id ? $m->b_id : $m->a_id, self::WALKOVER);
    }

    public const WALKOVER = 'W/O';

    /** A place written by hand (a tournament played without the bracket here). */
    public function place(TournamentEntry $e, ?int $place): TournamentEntry
    {
        $e->update(['place' => $place && $place > 0 ? $place : null]);

        return $e;
    }

    /** The fee of the chosen option, by card (the player's card at this place) or cash. */
    public function pay(TournamentEntry $e, string $method, User $by): Payment
    {
        $e->loadMissing('tournament.venue');
        if ($e->payment_id) {
            throw new BookingException('already_paid');
        }
        if ($e->tournament->status === 'cancelled') {
            throw new BookingException('tournament_closed');
        }
        // no money while people can still register: the tournament may still be cancelled (too few players)
        if (self::registrationOpen($e->tournament)) {
            throw new BookingException('pay_after_registration');
        }

        return DB::transaction(function () use ($e, $method, $by) {
            $t = $e->tournament;
            $player = $e->user_id ? User::find($e->user_id) : null;
            $payment = $this->money->charge($t->venue, $by, 'tournament', (int) ($t->options[$e->option]['price'] ?? 0), $method, $player, $e->id, $t->title);
            $e->update(['payment_id' => $payment->id]);
            TournamentChanged::dispatch($t->code);   // "not paid yet" goes away on their page

            return $payment;
        });
    }
}
