<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person on the team of a booking (added by the booker), or an invite by phone to someone
 * without an account yet (user_id null until they log in with that phone). status: joined | invited | left
 */
class BookingPlayer extends Model
{
    protected $fillable = ['booking_id', 'user_id', 'phone', 'invited_by', 'status'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
