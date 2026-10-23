<?php

namespace App\Domain\Chat\Events;

use App\Domain\Chat\ChatPresenter;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** Live: a new (or edited / deleted) message in a chat. Channel: private-conversation.{id} */
class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Message $message, public string $action = 'new') {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->message->conversation_id)];
    }

    public function broadcastAs(): string
    {
        return 'message';
    }

    public function broadcastWith(): array
    {
        return ['action' => $this->action, 'message' => ChatPresenter::message($this->message->loadMissing(['user', 'replyTo.user']))];
    }
}
