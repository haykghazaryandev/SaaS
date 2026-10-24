<?php

namespace App\Domain\Games;

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\RequestBooking;
use App\Domain\Chat\Chat;
use App\Domain\Social\SocialException;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\GamePlayerStatus as P;
use App\Enums\GameStatus as S;
use App\Models\Booking;
use App\Models\Field;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use App\Models\UserBlock;
use App\Notifications\Notice;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Games "N vs N" (3–11 per side) without a field:
 * 1. the creator invites friends → status "forming", only invited people see it;
 * 2. when ~30% of the players are in (creator + accepted friends) → "open": everyone sees it, the chat starts;
 * 3. players join until all places are taken → "full";
 * 4. the creator books a field for the game; when the place accepts → "booked"; after the time → "played";
 * 5. "Play again" creates a new game and invites the same people.
 * A forming game that doesn't reach 30% in 48 hours is cancelled.
 */
class Games
{
    public const MAX_ACTIVE_PER_CREATOR = 3;

    public const FORMING_HOURS = 48;

    public function __construct(private Chat $chat) {}

    /** $data: players_per_side (3–11), district?, venue_id?, when_from?, when_to?, note? */
    public function create(User $creator, array $data, array $inviteIds, ?Game $previous = null): Game
    {
        $perSide = (int) ($data['players_per_side'] ?? 0);
        if ($perSide < 3 || $perSide > 11) {
            throw new SocialException('bad_size');
        }
        $active = Game::where('creator_id', $creator->id)->whereIn('status', ['forming', 'open', 'full'])->count();
        if ($active >= self::MAX_ACTIVE_PER_CREATOR) {
            throw new SocialException('too_many_games', ['n' => self::MAX_ACTIVE_PER_CREATOR]);
        }
        $total = $perSide * 2;
        $min = Game::minToOpen($total);
        // only friends can be invited (people from the previous game for "play again")
        $allowed = $previous ? $previous->inPlayers()->pluck('user_id')->all() : $creator->friendIds();
        $invite = collect($inviteIds)->map(fn ($id) => (int) $id)->unique()->reject(fn ($id) => $id === $creator->id)
            ->filter(fn ($id) => in_array($id, $allowed, true) && ! UserBlock::between($creator->id, $id))->take($total - 1)->values();
        if ($invite->count() < $min - 1) {
            throw new SocialException('invite_more', ['n' => $min - 1]);
        }

        return DB::transaction(function () use ($creator, $data, $perSide, $total, $min, $invite, $previous) {
            $game = Game::create([
                'creator_id' => $creator->id, 'players_per_side' => $perSide, 'total_players' => $total, 'min_to_open' => $min,
                'district' => $data['district'] ?? null, 'venue_id' => $data['venue_id'] ?? null,
                'when_from' => $data['when_from'] ?? null, 'when_to' => $data['when_to'] ?? null, 'note' => $data['note'] ?? null,
                'status' => S::Forming, 'previous_game_id' => $previous?->id,
            ]);
            GamePlayer::create(['game_id' => $game->id, 'user_id' => $creator->id, 'status' => P::Joined, 'responded_at' => now()]);
            foreach ($invite as $uid) {
                GamePlayer::create(['game_id' => $game->id, 'user_id' => $uid, 'status' => P::Invited, 'invited_by' => $creator->id]);
            }
            $users = User::whereIn('id', $invite)->get();
            DB::afterCommit(fn () => $users->each->notify(new Notice('game_invite', ['name' => $creator->name, 'format' => $game->format()], '/games/'.$game->id)));

            return $game;
        });
    }

    /** A player of the game invites more friends (any time before the game is full). */
    public function invite(Game $game, User $by, array $ids): int
    {
        $this->mustBeIn($game, $by);
        if (! in_array($game->status->value, ['forming', 'open'], true)) {
            throw new SocialException('game_closed');
        }
        $friends = $by->friendIds();
        $n = 0;
        foreach (array_unique(array_map('intval', $ids)) as $uid) {
            if (! in_array($uid, $friends, true) || UserBlock::between($game->creator_id, $uid)) {
                continue;
            }
            $row = GamePlayer::firstOrNew(['game_id' => $game->id, 'user_id' => $uid]);
            if ($row->exists && in_array($row->status->value, ['accepted', 'joined', 'invited', 'removed'], true)) {
                continue;
            }
            $row->fill(['status' => P::Invited, 'invited_by' => $by->id, 'responded_at' => null])->save();
            User::find($uid)?->notify(new Notice('game_invite', ['name' => $by->name, 'format' => $game->format()], '/games/'.$game->id));
            $n++;
        }

        return $n;
    }

    /** $position: optional, the place I want to play in this game (see GamePlayer::POSITIONS). */
    public function respond(Game $game, User $user, bool $accept, ?string $position = null): Game
    {
        $position = $this->position($position);

        return DB::transaction(function () use ($game, $user, $accept, $position) {
            $game = Game::lockForUpdate()->findOrFail($game->id);
            $row = GamePlayer::where('game_id', $game->id)->where('user_id', $user->id)->where('status', P::Invited)->first();
            if (! $row) {
                throw new SocialException('not_invited');
            }
            if (! $accept) {
                $row->update(['status' => P::Declined, 'responded_at' => now()]);

                return $game;
            }
            if (! in_array($game->status->value, ['forming', 'open'], true) || $game->inCount() >= $game->total_players) {
                throw new SocialException('game_closed');
            }
            $row->update(['status' => P::Accepted, 'position' => $position, 'responded_at' => now()]);
            $this->afterJoin($game, $user);

            return $this->settle($game);
        });
    }

    /** Join an open game that is shown to everyone ($position: optional, see respond()). */
    public function join(Game $game, User $user, ?string $position = null): Game
    {
        $position = $this->position($position);

        return DB::transaction(function () use ($game, $user, $position) {
            $game = Game::lockForUpdate()->findOrFail($game->id);
            if ($game->status !== S::Open) {
                throw new SocialException('game_closed');
            }
            if (UserBlock::between($game->creator_id, $user->id)) {
                throw new SocialException('blocked');
            }
            $row = GamePlayer::firstOrNew(['game_id' => $game->id, 'user_id' => $user->id]);
            if ($row->exists && $row->status === P::Removed) {
                throw new SocialException('removed');
            }
            if ($row->exists && $row->isIn()) {
                return $game;
            }
            if ($game->inCount() >= $game->total_players) {
                throw new SocialException('game_closed');
            }
            $row->fill(['status' => P::Joined, 'position' => $position, 'responded_at' => now()])->save();
            $this->afterJoin($game, $user);
            $game->creator->notify(new Notice('game_joined', ['name' => $user->name, 'format' => $game->format()], '/games/'.$game->id));

            return $this->settle($game);
        });
    }

    /** Change or clear (null) my own position; only players who are in an active game. */
    public function setPosition(Game $game, User $user, ?string $position): GamePlayer
    {
        $position = $this->position($position);
        if (! in_array($game->status->value, S::ACTIVE, true)) {
            throw new SocialException('game_closed');
        }
        $row = GamePlayer::where('game_id', $game->id)->where('user_id', $user->id)->whereIn('status', P::IN)->first();
        if (! $row) {
            throw new SocialException('not_member');
        }
        $row->update(['position' => $position]);

        return $row;
    }

    public function leave(Game $game, User $user): Game
    {
        if ($game->creator_id === $user->id) {
            throw new SocialException('creator_cannot_leave');
        }

        return $this->out($game, $user, P::Left);
    }

    public function remove(Game $game, User $by, User $player): Game
    {
        if ($game->creator_id !== $by->id || $player->id === $by->id) {
            throw new SocialException('not_allowed');
        }
        $game = $this->out($game, $player, P::Removed);
        $player->notify(new Notice('game_removed', ['format' => $game->format()], '/games'));

        return $game;
    }

    public function cancel(Game $game, User $by): Game
    {
        if ($game->creator_id !== $by->id) {
            throw new SocialException('not_allowed');
        }
        if (! in_array($game->status->value, S::ACTIVE, true)) {
            throw new SocialException('game_closed');
        }

        return DB::transaction(function () use ($game, $by) {
            $game->update(['status' => S::Cancelled, 'cancelled_at' => now()]);
            $booking = $game->booking;
            if ($booking && $booking->status->holdsTime() && $booking->starts_at->isFuture()) {
                app(CancelBooking::class)->handle($booking, $by, 'player', 'game cancelled');
            }
            if ($c = $game->conversation) {
                $this->chat->system($c, 'chat.sys.cancelled');
                $c->update(['status' => 'archived', 'archived_at' => now(), 'delete_after' => now()->addDays(Chat::DELETE_AFTER_DAYS)]);
            }
            $others = User::whereIn('id', $game->inPlayers()->where('user_id', '!=', $by->id)->pluck('user_id'))->get();
            DB::afterCommit(fn () => $others->each->notify(new Notice('game_cancelled', ['format' => $game->format()], '/games')));

            return $game;
        });
    }

    /** The creator books a field for the game: a normal booking request linked to the game (only the creator may book). */
    public function book(Game $game, User $by, Field $field, CarbonImmutable $start, int $minutes): Booking
    {
        if ($game->creator_id !== $by->id) {
            throw new SocialException('only_creator_books');
        }
        if (! in_array($game->status->value, ['open', 'full'], true)) {
            throw new SocialException('game_closed');
        }
        if ($game->booking && $game->booking->status->holdsTime()) {
            throw new SocialException('already_booked');
        }
        $booking = app(RequestBooking::class)->handle($by, $field, $start, $minutes, ['source' => BookingSource::Game, 'game_id' => $game->id, 'team_name' => 'Game '.$game->format()]);
        $game->update(['booking_id' => $booking->id]);
        if ($c = $game->conversation) {
            $this->chat->system($c, 'chat.sys.booking_requested', $this->bookingParams($booking->load('venue', 'field'), $by));
        }

        return $booking;
    }

    /** Listener for BookingChanged: keeps the game and its chat in step with its booking. */
    public function bookingChanged(Booking $booking, string $type): void
    {
        $game = $booking->game_id ? Game::with('conversation')->find($booking->game_id) : null;
        if (! $game || $game->booking_id !== $booking->id || ! in_array($game->status->value, ['open', 'full', 'booked'], true)) {
            return;
        }
        $params = $this->bookingParams($booking->loadMissing('venue', 'field'));
        $players = User::whereIn('id', $game->inPlayers()->pluck('user_id'))->get();
        if ($type === 'accepted') {
            $game->update(['status' => S::Booked]);
            $game->conversation && $this->chat->system($game->conversation, 'chat.sys.booked', $params);
            $players->each->notify(new Notice('game_booked', $params, '/games/'.$game->id));
        } elseif (in_array($type, ['declined', 'expired', 'cancelled'], true)) {
            $game->update(['status' => $game->inCount() >= $game->total_players ? S::Full : S::Open]);
            $game->conversation && $this->chat->system($game->conversation, 'chat.sys.booking_'.$type, $params);
            if ($type !== 'cancelled') {
                $players->each->notify(new Notice('game_booking_declined', $params, '/games/'.$game->id));
            }
        } elseif ($type === 'changed') {
            $game->conversation && $this->chat->system($game->conversation, 'chat.sys.booking_changed', $params);
        }
    }

    /** "Play again": a new game with the same format; everybody from this game is invited. */
    public function playAgain(Game $game, User $by): Game
    {
        $this->mustBeIn($game, $by);
        $others = $game->inPlayers()->where('user_id', '!=', $by->id)->pluck('user_id')->all();

        return $this->create($by, ['players_per_side' => $game->players_per_side, 'district' => $game->district, 'venue_id' => $game->venue_id], $others, $game);
    }

    // ---------- scheduler ----------

    public function expireForming(): int
    {
        $n = 0;
        Game::with('creator')->where('status', S::Forming)->where('created_at', '<', now()->subHours(self::FORMING_HOURS))->each(function (Game $g) use (&$n) {
            $g->update(['status' => S::Cancelled, 'cancelled_at' => now()]);
            $g->creator->notify(new Notice('game_expired', ['format' => $g->format()], '/games/new'));
            $n++;
        });

        return $n;
    }

    public function markPlayed(): int
    {
        return Game::where('status', S::Booked)->whereHas('booking', fn ($q) => $q->where('ends_at', '<', now())->whereIn('status', ['accepted', 'completed']))->update(['status' => S::Played]);
    }

    // ---------- helpers ----------

    /** The controller validates; this only keeps bad values out of the table when called from elsewhere. */
    private function position(?string $position): ?string
    {
        if ($position !== null && ! in_array($position, GamePlayer::POSITIONS, true)) {
            throw new \InvalidArgumentException("Unknown position: {$position}");
        }

        return $position;
    }

    private function mustBeIn(Game $game, User $u): void
    {
        if (! GamePlayer::where('game_id', $game->id)->where('user_id', $u->id)->whereIn('status', P::IN)->exists()) {
            throw new SocialException('not_member');
        }
    }

    private function out(Game $game, User $user, P $status): Game
    {
        return DB::transaction(function () use ($game, $user, $status) {
            $game = Game::lockForUpdate()->findOrFail($game->id);
            $row = GamePlayer::where('game_id', $game->id)->where('user_id', $user->id)->first();
            if (! $row || ! $row->isIn()) {
                throw new SocialException('not_member');
            }
            $row->update(['status' => $status, 'responded_at' => now()]);
            if ($c = $game->conversation) {
                $this->chat->system($c, $status === P::Left ? 'chat.sys.left' : 'chat.sys.removed', ['name' => $user->name]);
                $this->chat->leave($c, $user->id);
            }
            if ($game->status === S::Full) {
                $game->update(['status' => S::Open, 'full_at' => null]);
            }

            return $game;
        });
    }

    private function afterJoin(Game $game, User $user): void
    {
        if ($c = $game->conversation()->first()) {
            $this->chat->join($c, $user->id);
            $this->chat->system($c, 'chat.sys.joined', ['name' => $user->name]);
        }
    }

    /** Move the game forward when enough players are in: forming → open → full. */
    private function settle(Game $game): Game
    {
        $in = $game->inCount();
        if ($game->status === S::Forming && $in >= $game->min_to_open) {
            $game->update(['status' => S::Open, 'opened_at' => now()]);
            $this->chat->openForGame($game);
            $players = User::whereIn('id', $game->inPlayers()->pluck('user_id'))->get();
            DB::afterCommit(fn () => $players->each->notify(new Notice('game_opened', ['format' => $game->format()], '/games/'.$game->id)));
        }
        if ($game->status === S::Open && $in >= $game->total_players) {
            $game->update(['status' => S::Full, 'full_at' => now()]);
            if ($c = $game->conversation()->first()) {
                $this->chat->system($c, 'chat.sys.full', ['n' => $in]);
            }
            $players = User::whereIn('id', $game->inPlayers()->pluck('user_id'))->get();
            DB::afterCommit(fn () => $players->each->notify(new Notice('game_full', ['format' => $game->format()], '/games/'.$game->id)));
        }

        return $game;
    }

    private function bookingParams(Booking $b, ?User $by = null): array
    {
        $t = $b->starts_at->toImmutable()->setTimezone(LocalTime::tz());

        return array_filter([
            'venue' => $b->venue->name, 'field' => $b->field->format, 'date' => $t->format('d.m'), 'time' => $t->format('H:i').'–'.$b->ends_at->toImmutable()->setTimezone(LocalTime::tz())->format('H:i'),
            'name' => $by?->name, 'booking_id' => $b->id,
        ], fn ($v) => $v !== null);
    }
}
