<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money the place took: a booking, training, shop sale, tournament fee or a card top-up; by card or cash. */
class Payment extends Model
{
    protected $fillable = ['venue_id', 'user_id', 'card_id', 'kind', 'method', 'amount', 'discount', 'ref_id', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'discount' => 'integer'];
    }

    public const KINDS = ['booking', 'training', 'shop', 'tournament', 'topup', 'other'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
