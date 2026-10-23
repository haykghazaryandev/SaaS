<?php

namespace App\Http\Controllers\Social;

use App\Domain\Chat\Chat;
use App\Domain\Social\Friends;
use App\Enums\GamePlayerStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\PlayerCards;
use App\Models\User;
use App\Models\UserBlock;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** A player's public profile (/u/{username or id}) and my own profile (/me/profile). */
class ProfileController extends Controller
{
    public const POSITIONS = ['goalkeeper', 'defender', 'midfielder', 'forward'];

    public const LEVELS = ['beginner', 'mid', 'pro'];

    public function __construct(private Friends $friends, private PlayerCards $cards) {}

    /** Districts are the same as the places' districts (lang player.district.*). */
    public static function districts(): array
    {
        return array_keys(trans('player.district', [], 'en'));
    }

    public function show(Request $request, User $player)
    {
        $me = $request->user();
        $relation = $this->friends->relation($me, $player);
        $blockedByMe = $relation === 'blocked' && UserBlock::where('blocker_id', $me->id)->where('blocked_id', $player->id)->exists();
        $hidden = $relation === 'blocked';
        $card = $this->cards->card($player, $relation);

        return Inertia::render('profile/Show', [
            'player' => $hidden ? ['id' => $player->id, 'name' => $blockedByMe ? $card['name'] : '—', 'username' => $blockedByMe ? $player->username : null, 'url' => $card['url'], 'relation' => 'blocked'] : [
                ...$card,
                'bio' => $player->bio,
                'games' => $player->gamePlayers()->whereIn('status', GamePlayerStatus::IN)
                    ->whereHas('game', fn ($g) => $g->whereNotIn('status', ['forming', 'cancelled']))->count(),
                'friends' => count($player->friendIds()),
            ],
            'hidden' => $hidden,
            'blockedByMe' => $blockedByMe,
            'canMessage' => ! $hidden && app(Chat::class)->canMessage($me, $player),
        ]);
    }

    public function edit(Request $request)
    {
        $u = $request->user();

        return Inertia::render('profile/Edit', [
            'profile' => [
                'name' => $u->name, 'username' => $u->username, 'level' => $u->level, 'position' => $u->position,
                'district' => $u->district, 'bio' => $u->bio, 'locale' => app()->getLocale(),   // the language the page is shown in
                'phone' => Phone::format($u->phone), 'telegram' => $u->hasTelegram(), 'url' => PlayerCards::url($u),
            ],
            'options' => ['levels' => self::LEVELS, 'positions' => self::POSITIONS, 'districts' => self::districts(), 'locales' => config('app.locales')],
            'blocked' => fn () => $this->cards->cards($u, User::whereIn('id', UserBlock::where('blocker_id', $u->id)->select('blocked_id'))->orderBy('name')->get()),
        ]);
    }

    public function update(Request $request)
    {
        $u = $request->user();
        // usernames are stored in lower case: "DAVO" is taken when "davo" is
        if (is_string($request->input('username'))) {
            $request->merge(['username' => mb_strtolower(trim($request->input('username')))]);
        }
        $data = $request->validate([
            'name' => 'required|string|min:2|max:60',
            'username' => ['nullable', 'string', 'min:3', 'max:32', 'regex:/^[a-z0-9_.]+$/i', Rule::unique('users')->ignore($u->id)],
            'level' => ['nullable', Rule::in(self::LEVELS)],
            'position' => ['nullable', Rule::in(self::POSITIONS)],
            'district' => ['nullable', Rule::in(self::districts())],
            'bio' => 'nullable|string|max:300',
            'locale' => ['required', Rule::in(config('app.locales'))],
        ]);
        $data['username'] = isset($data['username']) ? strtolower($data['username']) : null;
        $data['name'] = trim($data['name']);
        $data['bio'] = isset($data['bio']) ? trim($data['bio']) ?: null : null;
        $u->forceFill([...$data, 'registered_at' => $u->registered_at ?? now()])->save();
        $request->session()->put('locale', $data['locale']);
        app()->setLocale($data['locale']);

        return back()->with('success', __('profile.saved'));
    }
}
