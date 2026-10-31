<?php

namespace App\Http\Presenters;

use App\Domain\Chat\Chat;
use App\Enums\BookingStatus;
use App\Enums\GamePlayerStatus;
use App\Models\Booking;
use App\Models\Friendship;
use App\Models\GamePlayer;
use App\Models\TournamentEntry;
use App\Models\User;

/**
 * Badge numbers for the header and the phone tab bar:
 * - notifications: unread notices (bell)
 * - messages: unread chat messages ("Chats")
 * - friendRequests: friend requests waiting for me
 * - invites: game invites I have not answered (game still forming or open)
 * - chats: chats with unread messages (the app icon counts a chat once)
 * - pendingBookings: my booking requests still waiting for the place (not ended yet)
 */
class SocialCounts
{
    public function __construct(private Chat $chat) {}

    public function for(User $u): array
    {
        return [
            'notifications' => $u->unreadNotifications()->count(),
            'messages' => $this->chat->unreadLoud($u),   // muted chats: no badge, no sound
            // chats with unread messages (one each, however many): the app icon's number counts these
            'chats' => count($this->chat->unreadShown($u)),
            'friendRequests' => Friendship::where('friend_id', $u->id)->where('status', 'pending')->count(),
            'invites' => GamePlayer::where('user_id', $u->id)->where('status', GamePlayerStatus::Invited)
                ->whereHas('game', fn ($q) => $q->whereIn('status', ['forming', 'open']))->count(),
            'pendingBookings' => Booking::where('user_id', $u->id)->where('status', BookingStatus::Pending)->where('ends_at', '>', now())->count(),
            // tournaments I registered for (any): "My tournaments" in the menus
            'tournaments' => TournamentEntry::where('user_id', $u->id)->count(),
        ];
    }
}
