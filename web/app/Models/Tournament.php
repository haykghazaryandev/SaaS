<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A tournament of a place with a registration link (/t/{code}) and up to 3 price options. */
class Tournament extends Model
{
    protected $fillable = ['venue_id', 'parent_id', 'division', 'sport', 'title', 'code', 'starts_at', 'description', 'options', 'status', 'min_rating', 'max_rating', 'max_players', 'drawn_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'options' => 'array', 'drawn_at' => 'datetime', 'min_rating' => 'integer', 'max_rating' => 'integer', 'max_players' => 'integer'];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /** The tournament this one is a division of (null: a tournament of its own, or the parent). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Tournament::class, 'parent_id');
    }

    /** The divisions (by rating) under this tournament. */
    public function divisions(): HasMany
    {
        return $this->hasMany(Tournament::class, 'parent_id')->orderByRaw('min_rating asc nulls first')->orderBy('id');
    }

    public function isParent(): bool
    {
        return $this->relationLoaded('divisions') ? $this->divisions->isNotEmpty() : $this->divisions()->exists();
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
