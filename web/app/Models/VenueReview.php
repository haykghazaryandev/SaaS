<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "How was the field?" after a played game: 1–5 stars and an optional comment. */
class VenueReview extends Model
{
    protected $fillable = ['venue_id', 'booking_id', 'user_id', 'stars', 'comment'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
