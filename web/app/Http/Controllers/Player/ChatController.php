<?php

namespace App\Http\Controllers\Player;

use App\Domain\Chat\Chat;
use App\Domain\Chat\ChatPresenter;
use App\Domain\Social\SocialException;
use App\Http\Controllers\Controller;
use App\Http\Presenters\PlayerGames;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\Venue;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Messages: the chat list and a chat screen (direct and game chats).
 * All rules are in App\Domain\Chat\Chat; live updates come over Reverb (see README "Real time").
 * The chat screen talks JSON (send / edit / delete / read / history) so it never reloads the page.
 */
class ChatController extends Controller
{
    public const PAGE = 40;

    public function __construct(private Chat $chat, private PlayerGames $games) {}

    // ---------- pages ----------

    public function index(Request $request): Response
    {
        $me = $request->user();
        $unread = $this->chat->unread($me);
        $chats = Conversation::query()
            // my chats; one I deleted comes back only when somebody writes after that
            ->whereHas('participants', fn ($q) => $q->where('user_id', $me->id)->whereNull('left_at')
                ->where(fn ($w) => $w->whereNull('hidden_at')->orWhereColumn('conversation_participants.hidden_at', '<', 'conversations.last_message_at')))
            ->with(['game' => fn ($q) => $q->withCount('inPlayers'), 'game.venue', 'game.booking.venue', 'game.booking.field', 'tournament', 'venue:id,name,slug', 'latestMessage.user', 'participants.user'])
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->get()
            ->map(fn (Conversation $c) => $this->item($c, $me, $unread[$c->id] ?? 0));

        return Inertia::render('messages/Index', [
            'chats' => $chats->where('archived', false)->values()->all(),
            'archived' => $chats->where('archived', true)->values()->all(),
            // "New message": friends to start a direct chat with (loaded when the picker opens)
            'friends' => Inertia::optional(fn () => User::whereIn('id', $me->friendIds())->orderBy('name')->get()
                ->map(fn (User $u) => ['id' => $u->id, 'name' => self::name($u), 'username' => $u->username, 'avatar' => $u->avatarUrl()])->values()->all()),
        ]);
    }

    public function show(Request $request, Conversation $conversation): Response
    {
        $me = $request->user();
        $this->authorizeMember($conversation, $me);
        $conversation->load(['game' => fn ($q) => $q->withCount('inPlayers'), 'game.venue', 'game.booking.venue', 'game.booking.field', 'tournament', 'venue:id,name,slug', 'participants.user']);
        $mine = $conversation->participants->firstWhere('user_id', $me->id);
        $msgs = $this->query($conversation, $mine?->hidden_at)->limit(self::PAGE + 1)->get();

        return Inertia::render('messages/Show', [
            'chat' => $this->item($conversation, $me, 0) + [
                'members' => $conversation->participants->whereNull('left_at')->count(),
                // game chats: how many other members are online now (direct chats have other.online / other.lastSeen)
                'online' => $this->onlineCount($conversation, $me),
                'lastReadId' => $mine?->last_read_message_id,
                // my settings: muted; a group chat on now can be left, a direct / place / finished chat deleted (for me)
                'muted' => (bool) ($mine?->muted_until?->isFuture()),
                // a group chat: who is in it (the organiser / the place first), for the Members list and "@" mentions
                'memberList' => $conversation->type === 'direct' ? [] : $this->memberList($conversation),
                'canLeave' => Chat::groupChat($conversation) && ! $conversation->isArchived(),
                // "seen" ticks on my messages: how far each other member has read
                'reads' => $conversation->participants->whereNull('left_at')->where('user_id', '!=', $me->id)
                    ->mapWithKeys(fn ($p) => [$p->user_id => (int) $p->last_read_message_id])->all(),
                // a group chat: tap someone → their profile / "Add friend" (my relation to each member)
                'people' => $conversation->type === 'direct' ? (object) [] : (object) self::relations($me, $conversation->participants->pluck('user_id')->all()),
            ],
            'messages' => $msgs->take(self::PAGE)->reverse()->values()->map(fn ($m) => ChatPresenter::message($m))->all(),
            'hasMore' => $msgs->count() > self::PAGE,
            'editMinutes' => Chat::EDIT_MINUTES,
            'maxLength' => Message::MAX_LENGTH,
            'canReport' => Route::has('reports.store'),
        ]);
    }

    // ---------- JSON (the chat screen) ----------

    /** ?before=id: the 40 messages before it (older); ?after=id: everything newer (the polling fallback / catching up after a reconnect). */
    public function history(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeMember($conversation, $request->user());
        $data = $request->validate(['before' => ['nullable', 'integer', 'min:1'], 'after' => ['nullable', 'integer', 'min:0']]);
        $hidden = $conversation->participants()->where('user_id', $request->user()->id)->value('hidden_at');
        if (isset($data['after'])) {
            $msgs = $this->query($conversation, $hidden)->reorder('id')->where('id', '>', $data['after'])->limit(200)->get();

            return response()->json(['messages' => $msgs->map(fn ($m) => ChatPresenter::message($m))->all(), 'hasMore' => false]);
        }
        $q = $this->query($conversation, $hidden);
        if (isset($data['before'])) {
            $q->where('id', '<', $data['before']);
        }
        $msgs = $q->limit(self::PAGE + 1)->get();

        return response()->json([
            'messages' => $msgs->take(self::PAGE)->reverse()->values()->map(fn ($m) => ChatPresenter::message($m))->all(),
            'hasMore' => $msgs->count() > self::PAGE,
        ]);
    }

    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:'.Message::MAX_LENGTH], 'reply_to' => ['nullable', 'integer'],
            'mentions' => ['nullable', 'array', 'max:20'], 'mentions.*' => ['integer']]);
        $request->user()->touchLastSeen(10);

        return $this->attempt(fn () => ChatPresenter::message(
            $this->chat->send($conversation, $request->user(), $data['body'], $data['reply_to'] ?? null, $data['mentions'] ?? [])->load(['user', 'replyTo.user'])
        ), 201);
    }

    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        $this->authorizeMember($conversation, $me);
        $id = (int) $request->validate(['id' => ['required', 'integer', 'min:1']])['id'];
        // never past the newest message of this chat
        $id = min($id, (int) $conversation->messages()->withTrashed()->max('id'));
        if ($id > 0) {
            $this->chat->markRead($conversation, $me, $id);
        }
        $me->touchLastSeen(10);   // reading a chat = online (more often than the once-a-minute middleware)

        return response()->json(['ok' => true]);
    }

    /** The chat header refreshes "online" / "last seen" with this every minute (members only). */
    public function presence(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        $this->authorizeMember($conversation, $me);
        $conversation->load('participants.user');
        $other = $conversation->type === 'direct' ? $conversation->participants->firstWhere('user_id', '!=', $me->id)?->user : null;

        return response()->json([
            'other' => $other ? self::seen($other) : null,
            'online' => $this->onlineCount($conversation, $me),
        ]);
    }

    public function update(Request $request, Message $message): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:'.Message::MAX_LENGTH]]);
        $message->loadMissing('conversation');

        return $this->attempt(fn () => ChatPresenter::message($this->chat->edit($message, $request->user(), $data['body'])->load(['user', 'replyTo.user'])));
    }

    public function destroy(Request $request, Message $message): JsonResponse
    {
        $message->loadMissing('conversation');

        return $this->attempt(function () use ($message, $request) {
            $this->chat->delete($message, $request->user());

            return ChatPresenter::message($message->load('user'));
        });
    }

    /** "Message the place" on its page: open (or start) my chat with the place. */
    public function venue(Request $request, Venue $venue)
    {
        try {
            $c = $this->chat->withVenue($request->user(), $venue);
        } catch (SocialException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('messages.show', $c);
    }

    /** "Message" on a profile / player list: open (or create) the direct chat. */
    public function direct(Request $request, User $user)
    {
        try {
            $c = $this->chat->direct($request->user(), $user);
        } catch (SocialException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('messages.show', $c);
    }

    // ---------- helpers ----------

    private function authorizeMember(Conversation $c, User $u): void
    {
        abort_unless($this->chat->isMember($c, $u), 403);
    }

    /** messages of a chat, newest first, deleted ones included (shown as "deleted"); after I deleted the chat: only newer ones */
    private function query(Conversation $c, $hiddenAt = null)
    {
        return Message::withTrashed()->where('conversation_id', $c->id)->when($hiddenAt, fn ($q) => $q->where('created_at', '>', $hiddenAt))
            ->with(['user', 'replyTo.user'])->orderByDesc('id');
    }

    // ---------- my settings of a chat ----------

    public function mute(Request $request, Conversation $conversation)
    {
        $this->authorizeMember($conversation, $request->user());
        $on = $request->boolean('on');
        $this->chat->mute($conversation, $request->user(), $on);

        return back()->with('success', __($on ? 'messages.muted' : 'messages.unmuted'));
    }

    /** Delete for me (a direct, place or finished chat): back to the list. */
    public function remove(Request $request, Conversation $conversation)
    {
        abort_unless(ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $request->user()->id)->exists(), 403);
        try {
            $this->chat->hide($conversation, $request->user());
        } catch (SocialException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('messages.index')->with('success', __('messages.removed'));
    }

    /** Leave a group chat (game / tournament): back to the list. */
    public function leave(Request $request, Conversation $conversation)
    {
        $this->authorizeMember($conversation, $request->user());
        try {
            $this->chat->leaveGroup($conversation, $request->user());
        } catch (SocialException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('messages.index')->with('success', __('messages.left'));
    }

    private function attempt(callable $fn, int $status = 200): JsonResponse
    {
        try {
            return response()->json(['message' => $fn()], $status);
        } catch (SocialException $e) {
            return response()->json(['error' => $e->getMessage(), 'reason' => $e->reason], 422);
        }
    }

    /** one row of the chat list (and the header of a chat) */
    private function item(Conversation $c, User $me, int $unread): array
    {
        $other = null;
        if ($c->type === 'direct') {
            $u = $c->participants->firstWhere('user_id', '!=', $me->id)?->user;
            $other = $u ? ['id' => $u->id, 'name' => self::name($u), 'username' => $u->username, 'avatar' => $u->avatarUrl()] + self::seen($u) : null;
        }
        $g = $c->game;
        $last = $c->relationLoaded('latestMessage') ? $c->latestMessage : null;

        return [
            'id' => $c->id,
            'type' => $c->type,
            'archived' => $c->isArchived(),
            'other' => $other,
            'game' => $g ? [
                'id' => $g->id,
                'format' => $g->format(),
                'status' => $g->status->value,
                'players' => (int) ($g->in_players_count ?? 0),
                'total' => (int) ($g->total_players ?: $g->players_per_side * 2),
                'url' => Route::has('games.show') ? route('games.show', $g->id) : null,
                'creatorId' => $g->creator_id,
                // the pinned game bar of the chat: "Thu 21:00 · Zangak · field not booked → Book"
                'window' => $this->games->window($g),
                'place' => $g->venue?->name,
                'district' => $g->district ?? $g->venue?->district,
                'booking' => $g->relationLoaded('booking') && $g->booking && $g->booking->status->holdsTime() ? $this->games->booking($g->booking) : null,
            ] : null,
            // a tournament chat: its title, its page, and who is the organiser (the place's staff: their messages stand out)
            'tournament' => $c->type === 'tournament' && $c->tournament ? [
                'title' => $c->tournament->title, 'url' => route('tournament.show', $c->tournament->code),
                // the place's staff open it in their panel
                'manage' => DB::table('venue_users')->where('venue_id', $c->tournament->venue_id)->where('user_id', $me->id)->exists()
                    ? route('owner.tournaments.show', $c->tournament->id) : null,
                'hostIds' => DB::table('venue_users')->where('venue_id', $c->tournament->venue_id)->pluck('user_id')->map(fn ($id) => (int) $id)->all(),
            ] : null,
            // a player and a place: the place's staff answer (their messages carry the place's name); the staff see the player
            'venue' => $c->type === 'venue' && $c->venue ? (function () use ($c, $me) {
                $hosts = DB::table('venue_users')->where('venue_id', $c->venue_id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
                $player = in_array($me->id, $hosts, true) ? $c->participants->first(fn ($p) => ! in_array($p->user_id, $hosts, true))?->user : null;

                return ['name' => $c->venue->name, 'url' => route('places.show', $c->venue->slug), 'hostIds' => $hosts, 'staff' => in_array($me->id, $hosts, true),
                    'player' => $player ? ['id' => $player->id, 'name' => self::name($player), 'avatar' => $player->avatarUrl()] : null];
            })() : null,
            'last' => $last ? ChatPresenter::message($last) : null,
            'unread' => $unread,
            'muted' => (bool) $c->participants->firstWhere('user_id', $me->id)?->muted_until?->isFuture(),
            'at' => $c->last_message_at?->toIso8601String(),
        ];
    }

    /** The members of a group chat: the organiser / the place's staff first, then by name. */
    private function memberList(Conversation $c): array
    {
        $hosts = $c->tournament_id || $c->venue_id
            ? DB::table('venue_users')->where('venue_id', $c->venue_id ?? $c->tournament?->venue_id)->pluck('user_id')->map(fn ($id) => (int) $id)->all() : [];

        return $c->participants->whereNull('left_at')->filter(fn ($p) => $p->user)
            ->map(fn ($p) => ['id' => $p->user->id, 'name' => self::name($p->user), 'username' => $p->user->username, 'avatar' => $p->user->avatarUrl(),
                'host' => in_array($p->user->id, $hosts, true) || ($c->game && $c->game->creator_id === $p->user->id), 'online' => $p->user->isActiveNow()])
            ->sortBy(fn ($m) => [$m['host'] ? 0 : 1, mb_strtolower($m['name'])])->values()->all();
    }

    /** My relation to each of these people: friends | sent | received | blocked | none (me left out). */
    private static function relations(User $me, array $ids): array
    {
        $ids = array_values(array_diff(array_unique($ids), [$me->id]));
        $out = array_fill_keys($ids, 'none');
        foreach (Friendship::where(fn ($q) => $q->where('user_id', $me->id)->whereIn('friend_id', $ids))
            ->orWhere(fn ($q) => $q->where('friend_id', $me->id)->whereIn('user_id', $ids))->get() as $f) {
            $other = $f->user_id === $me->id ? $f->friend_id : $f->user_id;
            $out[$other] = $f->status === 'accepted' ? 'friends' : ($f->user_id === $me->id ? 'sent' : 'received');
        }
        foreach (UserBlock::where(fn ($q) => $q->where('blocker_id', $me->id)->whereIn('blocked_id', $ids))
            ->orWhere(fn ($q) => $q->where('blocked_id', $me->id)->whereIn('blocker_id', $ids))->get() as $b) {
            $out[$b->blocker_id === $me->id ? $b->blocked_id : $b->blocker_id] = 'blocked';
        }

        return $out;
    }

    /** "online" / "last seen" of a user, only ever shown to people who share a chat with them */
    private static function seen(User $u): array
    {
        return ['online' => $u->isActiveNow(), 'lastSeen' => $u->last_seen_at?->toIso8601String()];
    }

    /** game chats: members (not me) online now; the participants with their users are loaded already */
    private function onlineCount(Conversation $c, User $me): ?int
    {
        if ($c->type === 'direct') {
            return null;
        }

        return $c->participants->whereNull('left_at')->where('user_id', '!=', $me->id)->filter(fn ($p) => $p->user?->isActiveNow())->count();
    }

    private static function name(User $u): string
    {
        return $u->name ?: ($u->username ? '@'.$u->username : Phone::mask($u->phone));
    }
}
