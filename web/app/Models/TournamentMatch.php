<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A match of a tournament's bracket: two entries (a / b; null = not known yet or a bye), the winner and the score. */
class TournamentMatch extends Model
{
    protected $fillable = ['tournament_id', 'round', 'slot', 'a_id', 'b_id', 'winner_id', 'score', 'live'];

    protected function casts(): array
    {
        return ['round' => 'integer', 'slot' => 'integer', 'live' => 'boolean'];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function a(): BelongsTo
    {
        return $this->belongsTo(TournamentEntry::class, 'a_id');
    }

    public function b(): BelongsTo
    {
        return $this->belongsTo(TournamentEntry::class, 'b_id');
    }

    public function loserId(): ?int
    {
        return $this->winner_id ? ($this->winner_id === $this->a_id ? $this->b_id : $this->a_id) : null;
    }
}
