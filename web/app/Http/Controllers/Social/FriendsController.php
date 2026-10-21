<?php

namespace App\Http\Controllers\Social;

use App\Domain\Social\Friends;
use App\Domain\Social\SocialException;
use App\Enums\GamePlayerStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\PlayerCards;
use App\Models\Friendship;
use App\Models\GamePlayer;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Friends page: my friends, requests (received + sent), search, players I played with; and the friend actions. */
class FriendsController extends Controller
{
    public const TABS = ['friends', 'requests', 'find', 'played'];

    public function __construct(private Friends $friends, private PlayerCards $cards) {}

    public function index(Request $request)
    {
        $me = $request->user();
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'friends';
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 60);

        return Inertia::render('friends/Index', [
            'tab' => $tab,
            'q' => $q,
            'friends' => fn () => $this->cards->cards($me, User::whereIn('id', $me->friendIds())->orderBy('name')->get()),
            'received' => fn () => $this->cards->cards($me, User::whereIn('id', Friendship::where('friend_id', $me->id)->where('status', 'pending')->select('user_id'))->get()),
            'sent' => fn () => $this->cards->cards($me, User::whereIn('id', Friendship::where('user_id', $me->id)->where('status', 'pending')->select('friend_id'))->get()),
            'played' => fn () => $this->playedWith($me),
            'results' => fn () => $this->search($me, $q),
        ]);
    }

    /** Search by name, @username or phone (exact number). Blocked people (either way) and unfinished accounts are left out. */
    public function search(User $me, string $q): array
    {
        if (mb_strlen($q) < 2) {
            return [];
        }
        $blocked = $this->cards->blockedIds($me);
        $query = User::query()->whereNotNull('registered_at')->where('id', '!=', $me->id)->whereNotIn('id', $blocked)->where('status', '!=', 'blocked');
        $digits = preg_replace('/\D/', '', $q);
        $phone = strlen($digits) >= 8 ? Phone::normalize($q) : null;
        if ($phone) {
            $query->where('phone', $phone);
        } else {
            $term = ltrim($q, '@');
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';
            $query->where(fn ($w) => $w->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(username) like ?', [$like]));
        }

        return $this->cards->cards($me, $query->orderBy('name')->limit(30)->get());
    }

    /** People who were in the same games as me (not cancelled), who aren't my friends yet, most games first. */
    public function playedWith(User $me): array
    {
        $myGames = GamePlayer::where('user_id', $me->id)->whereIn('status', GamePlayerStatus::IN)
            ->whereHas('game', fn ($g) => $g->where('status', '!=', 'cancelled'))->select('game_id');
        $exclude = [$me->id, ...$me->friendIds(), ...$this->cards->blockedIds($me)];
        $rows = DB::table('game_players')->whereIn('game_id', $myGames)->whereIn('status', GamePlayerStatus::IN)->whereNotIn('user_id', $exclude)
            ->groupBy('user_id')->selectRaw('user_id, count(distinct game_id) as n, max(updated_at) as last')
            ->orderByDesc('n')->orderByDesc('last')->limit(60)->get();
        $users = User::whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');
        $n = $rows->pluck('n', 'user_id');
        $list = $rows->map(fn ($r) => $users[$r->user_id] ?? null)->filter()->values();

        return $this->cards->cards($me, $list, fn (User $u) => ['games' => (int) $n[$u->id]]);
    }

    public function request(Request $request, User $user)
    {
        try {
            $f = $this->friends->request($request->user(), $user);
        } catch (SocialException $e) {
            return $this->fail($request, $e);
        }

        return $this->done($request, $f->status === 'accepted' ? 'accepted' : 'requested');
    }

    public function accept(Request $request, User $user)
    {
        $me = $request->user();
        $f = Friendship::between($me->id, $user->id);
        try {
            if (! $f) {
                throw new SocialException('not_found');
            }
            $this->friends->accept($me, $f);
        } catch (SocialException $e) {
            return $this->fail($request, $e);
        }

        return $this->done($request, 'accepted');
    }

    /** Remove a friend, decline a request I got, or cancel one I sent. */
    public function remove(Request $request, User $user)
    {
        $me = $request->user();
        $rel = $this->friends->relation($me, $user);
        $this->friends->remove($me, $user);

        return $this->done($request, match ($rel) { 'received' => 'declined', 'sent' => 'cancelled', default => 'removed' });
    }

    private function done(Request $request, string $flash, array $params = [])
    {
        $text = __('friends.flash.'.$flash, $params);

        return $request->wantsJson() && ! $request->header('X-Inertia') ? response()->json(['ok' => true, 'message' => $text]) : back()->with('success', $text);
    }

    private function fail(Request $request, SocialException $e)
    {
        return $request->wantsJson() && ! $request->header('X-Inertia') ? response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 422) : back()->with('error', $e->getMessage());
    }
}
