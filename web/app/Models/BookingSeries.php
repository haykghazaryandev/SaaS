<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingSeries extends Model
{
    protected $table = 'booking_series';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }
}
