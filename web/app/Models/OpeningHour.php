<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpeningHour extends Model
{
    public $timestamps = false;

    protected $fillable = ['venue_id', 'weekday', 'opens_at', 'closes_at'];

    /** "08:00" -> 8.0, "24:00" -> 24.0 */
    public static function toHours(string $time): float
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h + $m / 60;
    }
}
