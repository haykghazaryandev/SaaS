<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A tournament of a place with a registration link (/t/{code}) and up to 3 price options. */
class Tournament extends Model
{
    protected $fillable = ['venue_id', 'sport', 'title', 'code', 'starts_at', 'description', 'options', 'status'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'options' => 'array'];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TournamentEntry::class);
    }

    public function link(): string
    {
        return url('/t/'.$this->code);
    }
}
