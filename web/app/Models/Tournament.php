<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A tournament of a place with a registration link (/t/{code}) and up to 3 price options. */
class Tournament extends Model
{
    protected $fillable = ['venue_id', 'sport', 'title', 'code', 'starts_at', 'description', 'options', 'status', 'min_rating', 'max_rating', 'max_players', 'drawn_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'options' => 'array', 'drawn_at' => 'datetime', 'min_rating' => 'integer', 'max_rating' => 'integer', 'max_players' => 'integer'];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TournamentEntry::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class)->orderBy('round')->orderBy('slot');
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }

    public function link(): string
    {
        return url('/t/'.$this->code);
    }
}
