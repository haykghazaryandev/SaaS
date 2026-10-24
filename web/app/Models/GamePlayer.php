<?php

namespace App\Models;

use App\Enums\GamePlayerStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GamePlayer extends Model
{
    /** Optional position in this game (the same values as the profile position). */
    public const POSITIONS = ['goalkeeper', 'defender', 'midfielder', 'forward'];

    protected $fillable = ['game_id', 'user_id', 'status', 'invited_by', 'side', 'position', 'responded_at'];

    protected function casts(): array
    {
        return ['status' => GamePlayerStatus::class, 'responded_at' => 'datetime'];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isIn(): bool
    {
        return in_array($this->status->value, GamePlayerStatus::IN, true);
    }
}
