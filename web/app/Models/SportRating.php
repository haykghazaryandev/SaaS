<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A player's rating in one sport (ping-pong and football are separate). */
class SportRating extends Model
{
    protected $fillable = ['user_id', 'sport', 'rating', 'intl_id', 'venue_id'];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
