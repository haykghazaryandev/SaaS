<?php

namespace App\Models;

use App\Enums\GamePlayerStatus;
use App\Enums\GameStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Game extends HasFactoryModel
{
    /** Share of players (creator included) needed before a game is shown to everyone. */
    public const OPEN_SHARE = 0.3;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => GameStatus::class,
            'when_from' => 'datetime', 'when_to' => 'datetime',
            'opened_at' => 'datetime', 'full_at' => 'datetime', 'cancelled_at' => 'datetime', 'archived_at' => 'datetime',
        ];
    }

    public static function minToOpen(int $total): int
    {
        return max(2, (int) ceil($total * self::OPEN_SHARE));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }

    public function inPlayers(): HasMany
    {
        return $this->hasMany(GamePlayer::class)->whereIn('status', GamePlayerStatus::IN);
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }

    public function inCount(): int
    {
        return $this->players()->whereIn('status', GamePlayerStatus::IN)->count();
    }

    public function format(): string
    {
        return $this->players_per_side.'×'.$this->players_per_side;
    }

    public function scopeVisible($query)
    {
        return $query->whereIn('status', ['open', 'full', 'booked']);
    }
}
