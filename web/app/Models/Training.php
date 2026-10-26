<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A regular training of a place: days, time, coach, price per visit. */
class Training extends Model
{
    protected $fillable = ['venue_id', 'title', 'coach', 'weekdays', 'starts_at', 'minutes', 'price', 'capacity', 'note', 'active'];

    protected function casts(): array
    {
        return ['weekdays' => 'array', 'active' => 'boolean', 'price' => 'integer', 'minutes' => 'integer', 'capacity' => 'integer'];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(TrainingVisit::class);
    }
}
