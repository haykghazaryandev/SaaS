<?php

namespace App\Domain\Social;

use App\Models\Friendship;
use App\Models\Report;
use App\Models\User;
use App\Models\UserBlock;
use App\Notifications\Notice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/** Friend requests, friends, blocking and reports. */
class Friends
{
    public const REQUESTS_PER_DAY = 30;

    /** Ask to be friends. If the other person already asked you, you become friends at once. */
    public function request(User $from, User $to): Friendship
    {
        if ($from->id === $to->id) {
            throw new SocialException('self');
        }
        if (UserBlock::between($from->id, $to->id)) {
            throw new SocialException('blocked');
        }
        $existing = Friendship::between($from->id, $to->id);
        if ($existing) {
            if ($existing->status === 'accepted') {
                return $existing;
            }
            if ($existing->friend_id === $from->id) {
                return $this->accept($from, $existing);   // they asked me already
            }

            return $existing;                              // my request is still waiting
        }
        $key = 'friend-req:'.$from->id;
        if (RateLimiter::tooManyAttempts($key, self::REQUESTS_PER_DAY)) {
            throw new SocialException('too_many_requests');
        }
        RateLimiter::hit($key, 86400);
        $f = Friendship::create(['user_id' => $from->id, 'friend_id' => $to->id, 'status' => 'pending']);
        $to->notify(new Notice('friend_request', ['name' => $from->name], '/friends'));

        return $f;
    }

    /** $me accepts a request that was sent to me. */
    public function accept(User $me, Friendship $f): Friendship
    {
        if ($f->friend_id !== $me->id || $f->status !== 'pending') {
            throw new SocialException('not_found');
        }
        $f->update(['status' => 'accepted', 'accepted_at' => now()]);
        $f->user->notify(new Notice('friend_accepted', ['name' => $me->name], '/friends'));

        return $f;
    }

    /** Decline a request sent to me, cancel my own request, or remove a friend. */
    public function remove(User $me, User $other): void
    {
        Friendship::where(fn ($q) => $q->where('user_id', $me->id)->where('friend_id', $other->id))
            ->orWhere(fn ($q) => $q->where('user_id', $other->id)->where('friend_id', $me->id))->delete();
    }

    public function block(User $me, User $other): void
    {
        if ($me->id === $other->id) {
            throw new SocialException('self');
        }
        DB::transaction(function () use ($me, $other) {
            UserBlock::firstOrCreate(['blocker_id' => $me->id, 'blocked_id' => $other->id]);
            $this->remove($me, $other);
        });
    }

    public function unblock(User $me, User $other): void
    {
        UserBlock::where('blocker_id', $me->id)->where('blocked_id', $other->id)->delete();
    }

    public function report(User $me, string $type, int $id, string $reason): Report
    {
        return Report::create(['reporter_id' => $me->id, 'target_type' => $type, 'target_id' => $id, 'reason' => mb_substr($reason, 0, 500)]);
    }

    /** Relation of $me to $other: self | friends | sent | received | blocked | none */
    public function relation(User $me, User $other): string
    {
        if ($me->id === $other->id) {
            return 'self';
        }
        if (UserBlock::between($me->id, $other->id)) {
            return 'blocked';
        }
        $f = Friendship::between($me->id, $other->id);

        return ! $f ? 'none' : ($f->status === 'accepted' ? 'friends' : ($f->user_id === $me->id ? 'sent' : 'received'));
    }
}
