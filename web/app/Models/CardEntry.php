<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a card's history: top-up (+), payment from the card (−), cashback (+), correction. */
class CardEntry extends Model
{
    protected $fillable = ['card_id', 'amount', 'kind', 'payment_id', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(VenueCard::class, 'card_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
