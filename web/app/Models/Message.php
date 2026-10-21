<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use SoftDeletes;

    public const UPDATED_AT = null;

    public const MAX_LENGTH = 2000;

    protected $fillable = ['conversation_id', 'user_id', 'type', 'body', 'meta', 'reply_to_id', 'edited_at'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'edited_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id')->withTrashed();   // a quote of a deleted message shows "deleted"
    }
}
