<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** History of a rating: who changed it, from what to what, why. */
class RatingChange extends Model
{
    protected $fillable = ['user_id', 'sport', 'old', 'new', 'note', 'venue_id', 'created_by'];

    protected function casts(): array
    {
        return ['old' => 'integer', 'new' => 'integer'];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
