<?php

namespace App\Domain\Chat\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** Live: a user's chat list changed (new message somewhere, joined / left a chat). Channel: private-user.{id} */
class ChatUpdated implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(public int $userId, public int $conversationId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'chat.updated';
    }
}
