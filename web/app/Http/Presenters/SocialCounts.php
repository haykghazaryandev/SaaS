<?php

namespace App\Http\Presenters;

use App\Domain\Chat\Chat;
use App\Models\Friendship;
use App\Models\User;

/** Badge numbers for the header: unread notifications, unread chat messages, friend requests waiting. */
class SocialCounts
{
    public function __construct(private Chat $chat) {}

    public function for(User $u): array
    {
        return [
            'notifications' => $u->unreadNotifications()->count(),
            'messages' => array_sum($this->chat->unread($u)),
            'friendRequests' => Friendship::where('friend_id', $u->id)->where('status', 'pending')->count(),
        ];
    }
}
