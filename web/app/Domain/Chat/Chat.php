<?php

namespace App\Domain\Chat;

use App\Domain\Chat\Events\ChatUpdated;
use App\Domain\Chat\Events\MessageSent;
use App\Domain\Social\SocialException;
use App\Enums\BookingStatus;
use App\Enums\GamePlayerStatus;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Message;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Chats: direct (two people) and game chats (all players of an open game).
 * - direct chat: allowed between friends or people who play in the same game; not if one blocked the other
 * - game chat: starts when the game opens; players are added when they join and removed when they leave
 * - a game chat with no messages for 7 days (and no upcoming booking) becomes read-only, deleted 30 days later
 */
class Chat
{
    public const MESSAGES_PER_MINUTE = 20;

    public const QUIET_DAYS = 7;

    /** own messages can be edited / deleted for this long */
    public const EDIT_MINUTES = 15;

    public const DELETE_AFTER_DAYS = 30;

    // ---------- direct ----------

    public function canMessage(User $a, User $b): bool
    {
        if ($a->id === $b->id || UserBlock::between($a->id, $b->id)) {
            return false;
        }
        if ($a->isFriendOf($b)) {
            return true;
        }

        // played or plays in the same game
        return GamePlayer::where('user_id', $a->id)->whereIn('status', GamePlayerStatus::IN)
            ->whereIn('game_id', GamePlayer::where('user_id', $b->id)->whereIn('status', GamePlayerStatus::IN)->select('game_id'))->exists();
    }

    public function direct(User $me, User $other): Conversation
    {
        if (! $this->canMessage($me, $other)) {
            throw new SocialException('cannot_message');
        }

        return DB::transaction(function () use ($me, $other) {
            $c = Conversation::firstOrCreate(['direct_key' => Conversation::directKey($me->id, $other->id)], ['type' => 'direct']);
            foreach ([$me->id, $other->id] as $uid) {
                ConversationParticipant::firstOrCreate(['conversation_id' => $c->id, 'user_id' => $uid], ['joined_at' => now()]);
            }

            return $c;
        });
    }

    // ---------- game chats ----------

    /** Called when a game opens: creates the chat with every player who is in. */
    public function openForGame(Game $game): Conversation
    {
        $c = Conversation::firstOrCreate(['game_id' => $game->id], ['type' => 'game', 'last_message_at' => now()]);
        foreach ($game->inPlayers()->pluck('user_id') as $uid) {
            $this->join($c, $uid);
        }
        $this->system($c, 'chat.sys.opened', ['format' => $game->format()]);

        return $c;
    }

    public function join(Conversation $c, int $userId): void
    {
        $p = ConversationParticipant::firstOrNew(['conversation_id' => $c->id, 'user_id' => $userId]);
        $p->fill(['joined_at' => $p->joined_at ?? now(), 'left_at' => null])->save();
        DB::afterCommit(fn () => ChatUpdated::dispatch($userId, $c->id));
    }

    public function leave(Conversation $c, int $userId): void
    {
        ConversationParticipant::where('conversation_id', $c->id)->where('user_id', $userId)->update(['left_at' => now()]);
        DB::afterCommit(fn () => ChatUpdated::dispatch($userId, $c->id));
    }

    // ---------- messages ----------

    public function isMember(Conversation $c, User $u): bool
    {
        return ConversationParticipant::where('conversation_id', $c->id)->where('user_id', $u->id)->whereNull('left_at')->exists();
    }

    public function send(Conversation $c, User $from, string $body, ?int $replyTo = null): Message
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > Message::MAX_LENGTH) {
            throw new SocialException('bad_message');
        }
        if (! $this->isMember($c, $from)) {
            throw new SocialException('not_member');
        }
        if ($c->isArchived()) {
            throw new SocialException('chat_archived');
        }
        if ($c->type === 'direct') {
            $otherId = (int) collect(explode(':', $c->direct_key))->first(fn ($id) => (int) $id !== $from->id);
            if (UserBlock::between($from->id, $otherId)) {
                throw new SocialException('cannot_message');
            }
        }
        $key = 'chat-send:'.$from->id;
        if (RateLimiter::tooManyAttempts($key, self::MESSAGES_PER_MINUTE)) {
            throw new SocialException('too_fast');
        }
        RateLimiter::hit($key, 60);
        if ($replyTo && ! Message::where('id', $replyTo)->where('conversation_id', $c->id)->exists()) {
            $replyTo = null;
        }

        return $this->store($c, $from->id, 'text', $body, null, $replyTo);
    }

    /** A system line in a chat ("Davit joined"): body is a translation key, meta its parameters. */
    public function system(Conversation $c, string $key, array $params = []): Message
    {
        return $this->store($c, null, 'system', $key, $params);
    }

    public function edit(Message $m, User $by, string $body): Message
    {
        if ($m->user_id !== $by->id || $m->type !== 'text' || $m->created_at->lt(now()->subMinutes(self::EDIT_MINUTES)) || $m->conversation->isArchived()) {
            throw new SocialException('cannot_edit');
        }
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > Message::MAX_LENGTH) {
            throw new SocialException('bad_message');
        }
        $m->update(['body' => $body, 'edited_at' => now()]);
        MessageSent::dispatch($m, 'edited');

        return $m;
    }

    public function delete(Message $m, User $by): void
    {
        // the author within 15 minutes (like editing); an admin any time
        if (! $by->is_admin && ($m->user_id !== $by->id || $m->created_at->lt(now()->subMinutes(self::EDIT_MINUTES)) || $m->conversation->isArchived())) {
            throw new SocialException('cannot_edit');
        }
        $m->delete();
        MessageSent::dispatch($m, 'deleted');
    }

    public function markRead(Conversation $c, User $u, int $messageId): void
    {
        $changed = ConversationParticipant::where('conversation_id', $c->id)->where('user_id', $u->id)
            ->where(fn ($q) => $q->whereNull('last_read_message_id')->orWhere('last_read_message_id', '<', $messageId))
            ->update(['last_read_message_id' => $messageId]);
        if ($changed) {
            ChatUpdated::dispatch($u->id, $c->id);   // the user's other tabs / devices refresh their unread counts
        }
    }

    /** Unread messages per conversation for a user: [conversation_id => count]. */
    public function unread(User $u): array
    {
        return DB::table('conversation_participants as p')
            ->join('messages as m', 'm.conversation_id', '=', 'p.conversation_id')
            ->where('p.user_id', $u->id)->whereNull('p.left_at')->whereNull('m.deleted_at')
            ->where(fn ($q) => $q->whereNull('m.user_id')->orWhere('m.user_id', '!=', $u->id))
            ->whereRaw('m.id > COALESCE(p.last_read_message_id, 0)')
            ->groupBy('p.conversation_id')->selectRaw('p.conversation_id, count(*) as n')->pluck('n', 'conversation_id')->map(fn ($n) => (int) $n)->all();
    }

    private function store(Conversation $c, ?int $userId, string $type, string $body, ?array $meta = null, ?int $replyTo = null): Message
    {
        $m = Message::create(['conversation_id' => $c->id, 'user_id' => $userId, 'type' => $type, 'body' => $body, 'meta' => $meta, 'reply_to_id' => $replyTo]);
        $c->forceFill(['last_message_at' => now()])->save();
        $memberIds = ConversationParticipant::where('conversation_id', $c->id)->whereNull('left_at')->pluck('user_id');
        DB::afterCommit(function () use ($m, $memberIds, $c) {
            MessageSent::dispatch($m);
            foreach ($memberIds as $uid) {
                ChatUpdated::dispatch($uid, $c->id);
            }
        });

        return $m;
    }

    // ---------- cleanup (scheduler) ----------

    /** Game chats quiet for 7 days with no upcoming booking become read-only. Returns how many. */
    public function archiveQuiet(): int
    {
        $n = 0;
        $quiet = Conversation::with('game.booking')->where('type', 'game')->where('status', 'active')
            ->where('last_message_at', '<', now()->subDays(self::QUIET_DAYS))->get();
        foreach ($quiet as $c) {
            $b = $c->game?->booking;
            if ($b && in_array($b->status->value, BookingStatus::HOLDING, true) && $b->ends_at->isFuture()) {
                continue;
            }
            DB::transaction(function () use ($c) {
                $this->system($c, 'chat.sys.archived', ['days' => self::DELETE_AFTER_DAYS]);
                $c->update(['status' => 'archived', 'archived_at' => now(), 'delete_after' => now()->addDays(self::DELETE_AFTER_DAYS)]);
                $c->game?->update(['status' => 'archived', 'archived_at' => now()]);
            });
            $n++;
        }

        return $n;
    }

    public function deleteExpired(): int
    {
        $n = 0;
        Conversation::where('status', 'archived')->where('delete_after', '<', now())->each(function ($c) use (&$n) {
            $c->delete();   // messages and participants go with it (cascade)
            $n++;
        });

        return $n;
    }
}
