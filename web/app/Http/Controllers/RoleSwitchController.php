<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * One switch for people with several roles: the admin panel, the place panel, or the player app (people who run a
 * place stay in their panel unless they pick this; the panel again: back to normal).
 */
class RoleSwitchController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate(['to' => 'required|in:admin,owner,player']);
        $user = $request->user();
        $session = $request->session();

        return match ($data['to']) {
            'admin' => $user->is_admin ? redirect('/admin') : abort(403),
            'player' => tap(redirect()->route('home'), fn () => $session->put('player_mode', true)),
            default => tap(redirect()->route('owner.start'), fn () => $session->forget('player_mode')),
        };
    }
}
