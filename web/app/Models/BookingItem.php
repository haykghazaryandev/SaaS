<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A line on a booking's bill: "2 × Cola", added by the staff during the game. */
class BookingItem extends Model
{
    protected $fillable = ['booking_id', 'product_id', 'name', 'qty', 'price_each', 'created_by'];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'price_each' => 'integer'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
