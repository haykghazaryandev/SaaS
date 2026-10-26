<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A player came to a training on a day (paid by card or cash). */
class TrainingVisit extends Model
{
    protected $fillable = ['training_id', 'user_id', 'date', 'payment_id', 'created_by'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function training(): BelongsTo
    {
        return $this->belongsTo(Training::class);
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
