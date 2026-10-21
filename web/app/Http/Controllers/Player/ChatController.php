<?php

namespace App\Http\Controllers\Player;

use App\Domain\Chat\Chat;
use App\Domain\Chat\ChatPresenter;
use App\Domain\Social\SocialException;
use App\Http\Controllers\Controller;
use App\Http\Presenters\PlayerGames;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->whereHas('participants', fn ($q) => $q->where('user_id', $me->id)->whereNull('left_at'))
            ->with(['game' => fn ($q) => $q->withCount('inPlayers'), 'game.venue', 'game.booking.venue', 'game.booking.field', 'latestMessage.user', 'participants.user'])
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
        $conversation->load(['game' => fn ($q) => $q->withCount('inPlayers'), 'game.venue', 'game.booking.venue', 'game.booking.field', 'participants.user']);
        $msgs = $this->query($conversation)->limit(self::PAGE + 1)->get();
        $mine = $conversation->participants->firstWhere('user_id', $me->id);

        return Inertia::render('messages/Show', [
            'chat' => $this->item($conversation, $me, 0) + [
                'members' => $conversation->participants->whereNull('left_at')->count(),
                'lastReadId' => $mine?->last_read_message_id,
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
        if (isset($data['after'])) {
            $msgs = $this->query($conversation)->reorder('id')->where('id', '>', $data['after'])->limit(200)->get();

            return response()->json(['messages' => $msgs->map(fn ($m) => ChatPresenter::message($m))->all(), 'hasMore' => false]);
        }
        $q = $this->query($conversation);
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
        $data = $request->validate(['body' => ['required', 'string', 'max:'.Message::MAX_LENGTH], 'reply_to' => ['nullable', 'integer']]);

        return $this->attempt(fn () => ChatPresenter::message(
            $this->chat->send($conversation, $request->user(), $data['body'], $data['reply_to'] ?? null)->load(['user', 'replyTo.user'])
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

        return response()->json(['ok' => true]);
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

    /** messages of a chat, newest first, deleted ones included (shown as "deleted") */
    private function query(Conversation $c)
    {
        return Message::withTrashed()->where('conversation_id', $c->id)->with(['user', 'replyTo.user'])->orderByDesc('id');
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
            $other = $u ? ['id' => $u->id, 'name' => self::name($u), 'username' => $u->username, 'avatar' => $u->avatarUrl()] : null;
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
            'last' => $last ? ChatPresenter::message($last) : null,
            'unread' => $unread,
            'at' => $c->last_message_at?->toIso8601String(),
        ];
    }

    private static function name(User $u): string
    {
        return $u->name ?: ($u->username ? '@'.$u->username : Phone::mask($u->phone));
    }
}
