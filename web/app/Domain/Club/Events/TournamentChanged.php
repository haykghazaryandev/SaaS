<?php

namespace App\Domain\Club\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Live: a tournament's bracket changed (the draw, a result, a match being played): its public page reloads.
 * Public channel tournament.{code}: the bracket is public anyway (the link is in the club's Instagram).
 */
class TournamentChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public string $code) {}

    public function broadcastOn(): array
    {
        return [new Channel('tournament.'.$this->code)];
    }

    public function broadcastAs(): string
    {
        return 'tournament.changed';
    }
}
