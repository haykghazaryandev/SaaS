<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ZoneSpot's fee for one month, paid by the place (Admin → ZoneSpot fee → Paid ✓). */
class FeePayment extends Model
{
    protected $fillable = ['venue_id', 'month', 'amount', 'paid_at', 'note', 'marked_by'];

    protected function casts(): array
    {
        return ['month' => 'date', 'amount' => 'integer', 'paid_at' => 'datetime'];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }
}
