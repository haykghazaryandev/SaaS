<?php

namespace App\Models;

use App\Models\Concerns\Translatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Venue extends HasFactoryModel
{
    use SoftDeletes, Translatable;

    /** Booking rules of a place; venues.rules overrides any of them. */
    public const DEFAULT_RULES = [
        'horizon_days' => 14,          // how far ahead players can book
        'min_notice_min' => 60,        // no requests closer than this to the start
        'step_min' => 30,              // start times every 30 minutes
        'free_cancel_hours' => 12,     // players cancel for free until this many hours before
        'request_expire_min' => 120,   // a request nobody answered expires after this
        'max_pending_per_phone' => 2,  // open requests per phone at the same time
        'auto_accept_regulars' => false,
        'late_grace_min' => 15,        // after the start: how long the place waits before "team not here?"
    ];

    protected $fillable = ['slug', 'name', 'district', 'address', 'description', 'lat', 'lng', 'phone', 'rating_avg', 'rating_count', 'amenities', 'rules', 'status'];

    protected function casts(): array
    {
        return ['address' => 'array', 'description' => 'array', 'amenities' => 'array', 'rules' => 'array', 'rating_avg' => 'float'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function fields(): HasMany
    {
        return $this->hasMany(Field::class)->orderBy('sort');
    }

    /** Price rules of all fields of the place (used by the admin panel). */
    public function priceRules(): HasManyThrough
    {
        return $this->hasManyThrough(PriceRule::class, Field::class);
    }

    public function openingHours(): HasMany
    {
        return $this->hasMany(OpeningHour::class);
    }

    public function closures(): HasMany
    {
        return $this->hasMany(Closure::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'venue_users')->withPivot('role')->withTimestamps();
    }

    public function rule(string $key): mixed
    {
        return ($this->rules ?? [])[$key] ?? self::DEFAULT_RULES[$key];
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
