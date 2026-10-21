<?php

namespace App\Http\Controllers\Social;

use App\Domain\Social\Friends;
use App\Domain\Social\SocialException;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Block / unblock a player and report a player, a chat message or a game. */
class SafetyController extends Controller
{
    private const TABLES = ['user' => 'users', 'message' => 'messages', 'game' => 'games'];

    public function __construct(private Friends $friends) {}

    public function block(Request $request, User $user)
    {
        try {
            $this->friends->block($request->user(), $user);
        } catch (SocialException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('friends.flash.blocked', ['name' => $user->name ?: '—']));
    }

    public function unblock(Request $request, User $user)
    {
        $this->friends->unblock($request->user(), $user);

        return back()->with('success', __('friends.flash.unblocked', ['name' => $user->name ?: '—']));
    }

    /** POST /reports {target_type: user|message|game, target_id, reason}. Answers JSON to fetch() calls, a flash otherwise. */
    public function report(Request $request)
    {
        $data = $request->validate([
            'target_type' => ['required', Rule::in(array_keys(self::TABLES))],
            'target_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $request->validate(['target_id' => [Rule::exists(self::TABLES[$data['target_type']], 'id')]]);
        $this->friends->report($request->user(), $data['target_type'], (int) $data['target_id'], trim($data['reason']));
        $text = __('friends.flash.reported');

        return $request->wantsJson() && ! $request->header('X-Inertia') ? response()->json(['ok' => true, 'message' => $text]) : back()->with('success', $text);
    }
}
