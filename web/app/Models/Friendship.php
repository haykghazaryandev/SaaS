<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Friendship extends Model
{
    protected $fillable = ['user_id', 'friend_id', 'status', 'accepted_at'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function friend(): BelongsTo
    {
        return $this->belongsTo(User::class, 'friend_id');
    }

    /** The row for a pair of users, in either direction. */
    public static function between(int $a, int $b): ?self
    {
        return static::where(fn ($q) => $q->where('user_id', $a)->where('friend_id', $b))
            ->orWhere(fn ($q) => $q->where('user_id', $b)->where('friend_id', $a))->first();
    }
}
