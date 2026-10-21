<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramLogin extends Model
{
    protected $fillable = ['token', 'status', 'chat_id', 'user_id', 'expires_at', 'approved_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'approved_at' => 'datetime', 'chat_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
