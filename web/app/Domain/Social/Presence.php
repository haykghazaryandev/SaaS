<?php

namespace App\Domain\Social;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Who has Bookball open right now: every open app joins the "online" presence channel (Reverb), and leaves it the
 * moment the app (or the Mini App) is closed. The list is read from Reverb's HTTP API and kept 5 seconds.
 * When Reverb can't be asked (tests, local without Reverb), null: callers fall back to "seen in the last 2 minutes".
 */
class Presence
{
    public const CHANNEL = 'presence-online';

    /** @return int[]|null */
    public function onlineIds(): ?array
    {
        if (config('broadcasting.default') !== 'reverb') {
            return null;
        }

        return Cache::remember('presence:online', 5, function () {
            try {
                $res = Broadcast::connection('reverb')->getPusher()->get('/channels/'.self::CHANNEL.'/users');
                $users = is_object($res) ? ($res->users ?? []) : ($res['users'] ?? []);

                return array_map(fn ($u) => (int) (is_object($u) ? $u->id : $u['id']), $users);
            } catch (Throwable) {
                return null;   // Reverb unreachable for a moment: no cache for the failure
            }
        });   // a failure returns null, which Cache::remember does not keep: the next call asks again
    }
}
