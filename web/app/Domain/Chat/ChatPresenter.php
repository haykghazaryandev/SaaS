<?php

namespace App\Domain\Chat;

use App\Models\Message;

/** The JSON shape of chat data, shared by the web (Inertia), broadcasts and later the mobile API. */
class ChatPresenter
{
    public static function message(Message $m): array
    {
        return [
            'id' => $m->id,
            'conversation_id' => $m->conversation_id,
            'type' => $m->type,
            // system messages are translated for the reader on the client: key + params
            'body' => $m->trashed() ? null : $m->body,
            'meta' => $m->meta,
            'deleted' => $m->trashed(),
            'edited' => $m->edited_at !== null,
            'reply_to_id' => $m->reply_to_id,
            // the quoted message (only when the replyTo relation is loaded, e.g. with('replyTo.user'))
            'reply' => $m->reply_to_id && $m->relationLoaded('replyTo') && $m->replyTo ? [
                'id' => $m->replyTo->id,
                'type' => $m->replyTo->type,
                'body' => $m->replyTo->trashed() ? null : mb_substr($m->replyTo->body, 0, 160),
                'meta' => $m->replyTo->meta,
                'deleted' => $m->replyTo->trashed(),
                'user' => $m->replyTo->relationLoaded('user') && $m->replyTo->user ? ['id' => $m->replyTo->user->id, 'name' => $m->replyTo->user->name, 'username' => $m->replyTo->user->username] : null,
            ] : null,
            'user' => $m->user ? ['id' => $m->user->id, 'name' => $m->user->name, 'username' => $m->user->username, 'avatar' => $m->user->avatarUrl()] : null,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
