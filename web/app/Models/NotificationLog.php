<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One Telegram (later: push) message we tried to send. status: sent | logged (no bot token, development) | failed */
class NotificationLog extends Model
{
    protected $table = 'notification_log';

    protected $fillable = ['user_id', 'channel', 'chat_id', 'type', 'payload', 'status', 'error', 'sent_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'sent_at' => 'datetime', 'chat_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
