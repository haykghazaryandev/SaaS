<?php

namespace App\Domain\Club\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Live: something of a place's club changed (an item on a game's check, a card top-up, a payment, a tournament
 * registration, a product): the owner pages of that place reload (OwnerLayout). Channel: private-venue.{id}.
 */
class ClubChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int $venueId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('venue.'.$this->venueId)];
    }

    public function broadcastAs(): string
    {
        return 'club.changed';
    }
}
