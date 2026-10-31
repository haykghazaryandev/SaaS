<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    protected $fillable = ['type', 'game_id', 'tournament_id', 'venue_id', 'direct_key', 'status', 'last_message_at', 'archived_at', 'delete_after'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'archived_at' => 'datetime', 'delete_after' => 'datetime'];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /** A chat between a player and a place (its staff answer). */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /** People currently in the chat. */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')->wherePivotNull('left_at')->withPivot('last_read_message_id', 'muted_until', 'joined_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** The newest (not deleted) message: the chat list preview. */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public static function directKey(int $a, int $b): string
    {
        return min($a, $b).':'.max($a, $b);
    }

    /** The chats of a place: players writing to it, and its tournaments' chats. */
    public static function ofPlace(Builder $q, int $venueId): Builder
    {
        return $q->where(fn ($w) => $w->where('venue_id', $venueId)
            ->orWhereIn('tournament_id', Tournament::query()->where('venue_id', $venueId)->select('id')));
    }
}
