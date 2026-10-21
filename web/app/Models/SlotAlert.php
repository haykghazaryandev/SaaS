<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlotAlert extends Model
{
    protected $fillable = ['user_id', 'venue_id', 'field_id', 'starts_at', 'minutes', 'notified_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'notified_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(Field::class);
    }

    /** Still waiting: not notified yet and the time is in the future. */
    public function scopeWaiting($query)
    {
        return $query->whereNull('notified_at')->where('starts_at', '>', now());
    }
}
