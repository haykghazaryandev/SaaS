<?php

namespace App\Domain\Chat\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** Live "seen" ticks: a member read the chat up to a message. Channel: private-conversation.{id} */
class MessagesRead implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(public int $conversationId, public int $userId, public int $messageId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'read';
    }

    public function broadcastWith(): array
    {
        return ['user_id' => $this->userId, 'message_id' => $this->messageId];
    }
}
