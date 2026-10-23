<?php

namespace App\Models;

use App\Enums\FieldSize;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Field extends HasFactoryModel
{
    protected $fillable = ['venue_id', 'size', 'format', 'surface', 'indoor', 'max_players', 'durations', 'default_duration', 'sort', 'active'];

    protected function casts(): array
    {
        return ['size' => FieldSize::class, 'indoor' => 'boolean', 'durations' => 'array', 'active' => 'boolean'];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function priceRules(): HasMany
    {
        return $this->hasMany(PriceRule::class)->orderByDesc('priority');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
