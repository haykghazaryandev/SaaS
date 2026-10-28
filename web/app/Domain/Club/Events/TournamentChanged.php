<?php

namespace App\Domain\Club\Events;

use App\Models\Tournament;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Live: a tournament's bracket changed (the draw, a result, a match being played): its public page reloads.
 * Public channels tournament.{code} (its page) and tournaments (the lists): the bracket is public anyway.
 */
class TournamentChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public string $code) {}

    public function broadcastOn(): array
    {
        // the tournament's page, and the Tournaments tab (Live / Open / Finished)
        // a division: the page of its tournament (the list of divisions) too
        $parent = Tournament::where('code', $this->code)->whereNotNull('parent_id')->first()?->parent()->value('code');

        return array_values(array_filter([new Channel('tournament.'.$this->code), $parent ? new Channel('tournament.'.$parent) : null, new Channel('tournaments')]));
    }

    public function broadcastAs(): string
    {
        return 'tournament.changed';
    }
}
