<?php

namespace App\Http\Presenters;

use App\Models\Friendship;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Support\Collection;

/** Small player cards for lists (friends, requests, search) with the viewer's relation to each player. */
class PlayerCards
{
    /** Relation of $me to many users at once: [user_id => friends|sent|received|blocked|none|self]. */
    public function relations(User $me, Collection|array $ids): array
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $out = $ids->mapWithKeys(fn ($id) => [$id => $id === $me->id ? 'self' : 'none'])->all();
        Friendship::query()
            ->where(fn ($q) => $q->where('user_id', $me->id)->whereIn('friend_id', $ids))
            ->orWhere(fn ($q) => $q->where('friend_id', $me->id)->whereIn('user_id', $ids))
            ->get(['user_id', 'friend_id', 'status'])
            ->each(function ($f) use ($me, &$out) {
                $other = $f->user_id === $me->id ? $f->friend_id : $f->user_id;
                $out[$other] = $f->status === 'accepted' ? 'friends' : ($f->user_id === $me->id ? 'sent' : 'received');
            });
        foreach ($this->blockedIds($me) as $id) {
            if (isset($out[$id])) {
                $out[$id] = 'blocked';
            }
        }

        return $out;
    }

    /** Users I blocked or who blocked me. */
    public function blockedIds(User $me): array
    {
        return UserBlock::where('blocker_id', $me->id)->pluck('blocked_id')
            ->merge(UserBlock::where('blocked_id', $me->id)->pluck('blocker_id'))->unique()->values()->all();
    }

    /** Profile link: /u/<username>, or /u/<id> when there is no username. */
    public static function url(User $u): string
    {
        return route('users.show', ['player' => $u->username ?: $u->id]);
    }

    public function card(User $u, string $relation = 'none', array $extra = []): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name ?: '—',
            'username' => $u->username,
            'level' => $u->level,
            'position' => $u->position,
            'district' => $u->district,
            'url' => self::url($u),
            'relation' => $relation,
            ...$extra,
        ];
    }

    /** Cards for a list of users, with relations looked up in one go. */
    public function cards(User $me, Collection $users, ?callable $extra = null): array
    {
        $rel = $this->relations($me, $users->pluck('id'));

        return $users->map(fn (User $u) => $this->card($u, $rel[$u->id] ?? 'none', $extra ? $extra($u) : []))->values()->all();
    }
}
