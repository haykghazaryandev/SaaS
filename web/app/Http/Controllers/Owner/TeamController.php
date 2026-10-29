<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Venues\Team;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Owner → Team: the owner and the staff; add staff by name and phone, take them off. */
class TeamController extends OwnerController
{
    public function index(Request $request)
    {
        $venue = $this->venue($request);

        return Inertia::render('owner/Team', [
            'people' => $venue->staff()->orderByPivot('role')->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'phone' => $u->phone ? Phone::format($u->phone) : null, 'avatar' => $u->avatarUrl(),
                'role' => $u->pivot->role, 'me' => $u->id === $request->user()->id, 'online' => $u->isActiveNow(),
                'telegram' => $u->hasTelegram(),
            ])->values(),
        ]);
    }

    public function store(Request $request, Team $team)
    {
        $data = $request->validate(['phone' => 'required|string|max:30', 'name' => 'required|string|max:80']);

        $this->attempt(fn () => $team->add($this->venue($request), $data['phone'], $data['name'], $request->user()));

        return back()->with('success', __('owner.team.saved'));
    }

    public function destroy(Request $request, User $user, Team $team)
    {
        $this->attempt(fn () => $team->remove($this->venue($request), $user, $request->user()));

        return back()->with('success', __('owner.team.removed'));
    }
}
