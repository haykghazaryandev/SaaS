<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A registration for a tournament: name, phone, level, the price option chosen; later the result. */
class TournamentEntry extends Model
{
    protected $fillable = ['tournament_id', 'user_id', 'first_name', 'last_name', 'phone', 'level', 'option', 'status', 'place', 'payment_id'];

    protected function casts(): array
    {
        return ['level' => 'integer', 'option' => 'integer', 'place' => 'integer'];
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
