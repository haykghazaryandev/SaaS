<?php

namespace App\Http\Presenters;

use App\Enums\GamePlayerStatus as P;
use App\Models\Booking;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonInterface;

/**
 * Turns games into the arrays the games pages need (list cards, game page).
 * No rules here: what a player may do comes from the statuses the Games domain sets.
 * Expects the game with creator, venue, players.user and booking.venue/field loaded.
 */
class PlayerGames
{
    /** Relations every presenter method needs (lazy loading is off). */
    public const WITH = ['creator', 'venue', 'players.user', 'booking.venue', 'booking.field'];

    public static function initials(?string $name): string
    {
        $parts = array_slice(preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY), 0, 2);

        return $parts ? mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), $parts))) : '?';
    }

    public function person(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->name ?: '—', 'short' => $u->shortName(), 'initials' => self::initials($u->name), 'avatar' => $u->avatarUrl()];
    }

    /** The preferred time window in local time: date, from, to (null when the game has none). */
    public function window(Game $g): ?array
    {
        if (! $g->when_from) {
            return null;
        }
        $from = $g->when_from->toImmutable()->setTimezone(LocalTime::tz());
        $to = $g->when_to?->toImmutable()->setTimezone(LocalTime::tz());
        $allDay = $from->format('H:i') === '00:00' && $to && $to->equalTo($from->addDay());

        return [
            'date' => $from->toDateString(),
            'from' => $allDay ? null : $from->format('H:i'),
            'to' => $allDay || ! $to ? null : $this->hm($to, $from),
        ];
    }

    public function booking(?Booking $b): ?array
    {
        if (! $b) {
            return null;
        }
        $start = $b->starts_at->toImmutable()->setTimezone(LocalTime::tz());
        $end = $b->ends_at->toImmutable()->setTimezone(LocalTime::tz());

        return [
            'id' => $b->id, 'status' => $b->status->value, 'code' => $b->code, 'price' => $b->price,
            'venue' => ['slug' => $b->venue->slug, 'name' => $b->venue->name, 'district' => $b->venue->district],
            'field' => ['size' => $b->field->size->value, 'format' => $b->field->format ?: $b->field->size->format()],
            'date' => $start->toDateString(), 'start' => $start->format('H:i'), 'end' => $this->hm($end, $start),
        ];
    }

    /** A game in a list (Open games, My games). */
    public function card(Game $g, ?User $me = null): array
    {
        $in = $g->players->filter(fn (GamePlayer $p) => $p->isIn())
            ->sortBy(fn (GamePlayer $p) => [$p->user_id === $g->creator_id ? 0 : 1, $p->responded_at?->timestamp ?? 0])->values();
        $mine = $me ? $g->players->firstWhere('user_id', $me->id) : null;

        return [
            'id' => $g->id, 'format' => $g->format(), 'perSide' => $g->players_per_side, 'total' => $g->total_players, 'minToOpen' => $g->min_to_open,
            'in' => $in->count(), 'status' => $g->status->value,
            'district' => $g->district,
            'venue' => $g->venue ? ['slug' => $g->venue->slug, 'name' => $g->venue->name, 'district' => $g->venue->district] : null,
            'window' => $this->window($g), 'note' => $g->note,
            'creator' => $this->person($g->creator),
            'avatars' => $in->take(6)->map(fn (GamePlayer $p) => ['id' => $p->user_id, 'initials' => self::initials($p->user?->name), 'name' => $p->user?->name, 'avatar' => $p->user?->avatarUrl()])->all(),
            'booking' => $g->booking && $g->booking->status->holdsTime() ? $this->booking($g->booking) : null,
            'me' => $mine ? $mine->status->value : null,
            'mine' => $me && $g->creator_id === $me->id,
            // who invited me ("Aram invited you"): the inviter if they are in the game, else the organiser
            'invitedBy' => $mine && $mine->status === P::Invited ? $this->person($g->players->firstWhere('user_id', $mine->invited_by)?->user ?? $g->creator) : null,
        ];
    }

    /** Players grouped for the game page: in / invited (people in the game see them) / declined (creator only). */
    public function players(Game $g, ?User $me): array
    {
        $rows = $g->players->filter(fn (GamePlayer $p) => $p->user !== null)->sortBy(fn (GamePlayer $p) => [$p->user_id === $g->creator_id ? 0 : 1, $p->responded_at?->timestamp ?? PHP_INT_MAX, $p->id]);
        $member = $me && $g->players->contains(fn (GamePlayer $p) => $p->user_id === $me->id && in_array($p->status->value, ['accepted', 'joined', 'invited'], true));
        $creator = $me && $me->id === $g->creator_id;
        $map = fn ($list) => $list->map(fn (GamePlayer $p) => [
            ...$this->person($p->user), 'status' => $p->status->value, 'creator' => $p->user_id === $g->creator_id, 'me' => $me && $p->user_id === $me->id,
            'position' => $p->position,
        ])->values()->all();

        return [
            'in' => $map($rows->filter(fn (GamePlayer $p) => $p->isIn())),
            'invited' => $member ? $map($rows->where('status', P::Invited)) : [],
            'invitedCount' => $rows->where('status', P::Invited)->count(),
            'declined' => $creator ? $map($rows->where('status', P::Declined)) : [],
        ];
    }

    /** "24:00" for midnight at the end of the day, else H:i. */
    private function hm(CarbonInterface $t, CarbonInterface $from): string
    {
        return $t->format('H:i') === '00:00' && ! $t->isSameDay($from) ? '24:00' : $t->format('H:i');
    }
}
