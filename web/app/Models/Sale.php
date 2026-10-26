<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A shop sale: what, how many, to whom, paid how. */
class Sale extends Model
{
    protected $fillable = ['venue_id', 'product_id', 'product_name', 'user_id', 'qty', 'price_each', 'payment_id', 'created_by'];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'price_each' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
