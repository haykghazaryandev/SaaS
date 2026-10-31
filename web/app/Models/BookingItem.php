<?php

namespace App\Models;

use App\Domain\Club\Events\ClubChanged;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A line on a booking's bill: "2 × Cola", added by the staff during the game. */
class BookingItem extends Model
{
    protected $fillable = ['booking_id', 'product_id', 'name', 'qty', 'price_each', 'created_by', 'paid_at'];

    protected static function booted(): void
    {
        // the check changed: the desk on other phones shows it at once
        $live = fn (BookingItem $i) => ClubChanged::dispatch((int) Booking::whereKey($i->booking_id)->value('venue_id'));
        static::created($live);
        static::updated($live);
        static::deleted($live);
    }

    protected function casts(): array
    {
        return ['qty' => 'integer', 'price_each' => 'integer', 'paid_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
