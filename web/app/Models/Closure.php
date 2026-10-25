<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Closure extends Model
{
    protected $fillable = ['venue_id', 'field_id', 'starts_at', 'ends_at', 'reason', 'created_by'];

    protected static function booted(): void
    {
        static::saved(fn (Closure $c) => \App\Domain\Booking\Events\ScheduleChanged::forClosure($c));
        static::deleted(fn (Closure $c) => \App\Domain\Booking\Events\ScheduleChanged::forClosure($c));
    }

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
