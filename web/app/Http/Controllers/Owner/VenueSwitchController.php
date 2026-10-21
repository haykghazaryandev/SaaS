<?php

namespace App\Http\Controllers\Owner;

use Illuminate\Http\Request;

/** Users who work at several places choose the current one (kept in the session). */
class VenueSwitchController extends OwnerController
{
    public function __invoke(Request $request)
    {
        $data = $request->validate(['venue_id' => 'required|integer']);
        abort_unless($request->user()->venues()->where('venues.id', $data['venue_id'])->exists(), 403);
        $request->session()->put('venue_id', (int) $data['venue_id']);

        return redirect()->route('owner.today');
    }
}
