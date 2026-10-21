<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceRule extends Model
{
    protected $fillable = ['field_id', 'weekdays', 'from_time', 'to_time', 'price_per_hour', 'priority'];

    protected function casts(): array
    {
        return ['weekdays' => 'array'];
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(Field::class);
    }
}
