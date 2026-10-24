<?php

use App\Domain\Chat\Chat;
use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

// Laravel notifications (the in-app bell) broadcast here
Broadcast::channel('App.Models.User.{id}', fn ($user, $id) => (int) $user->id === (int) $id);

// a user's own live updates (chat list, unread counters)
Broadcast::channel('user.{id}', fn ($user, $id) => (int) $user->id === (int) $id);

// a chat: only its current members
Broadcast::channel('conversation.{id}', function ($user, $id) {
    $c = Conversation::find($id);

    return $c && app(Chat::class)->isMember($c, $user) ? ['id' => $user->id, 'name' => $user->name] : false;
});

// everyone with Bookball open: "online" in chats is who is in this channel (it empties the moment the app closes)
Broadcast::channel('online', fn ($user) => ['id' => $user->id]);
