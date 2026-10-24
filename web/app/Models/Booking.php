<?php

namespace App\Models;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends HasFactoryModel
{
    protected $guarded = ['id', 'time_range'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'decided_at' => 'datetime',
            'arrived_at' => 'datetime',
            'late_at' => 'datetime',
            'reminded_day_at' => 'datetime',
            'reminded_hour_at' => 'datetime',
            'not_here_asked_at' => 'datetime',
            'rating_asked_at' => 'datetime',
            'status' => BookingStatus::class,
            'source' => BookingSource::class,
        ];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(Field::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The team the booker added (joined members and pending invites). */
    public function players(): HasMany
    {
        return $this->hasMany(BookingPlayer::class);
    }

    /** People who get the booking's reminders: the booker and the joined team. */
    public function attendees(): \Illuminate\Support\Collection
    {
        $this->loadMissing(['user', 'players.user']);

        return collect([$this->user])->merge($this->players->where('status', 'joined')->pluck('user'))->filter()->unique('id')->values();
    }

    public function events(): HasMany
    {
        return $this->hasMany(BookingEvent::class)->latest('id');
    }

    public function durationMinutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }

    public function scopeHolding($query)
    {
        return $query->whereIn('status', BookingStatus::HOLDING);
    }

    /** Bookings of the field that overlap [from, to). */
    public function scopeOverlapping($query, int $fieldId, $from, $to)
    {
        return $query->where('field_id', $fieldId)->where('starts_at', '<', $to)->where('ends_at', '>', $from);
    }

    public static function newCode(): string
    {
        do {
            $code = 'BB-'.random_int(1000, 99999);
        } while (static::where('code', $code)->exists());

        return $code;
    }
}
