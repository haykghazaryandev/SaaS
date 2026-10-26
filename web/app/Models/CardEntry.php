<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a card's history: top-up (+), payment from the card (−), cashback (+), correction. */
class CardEntry extends Model
{
    protected $fillable = ['card_id', 'amount', 'kind', 'payment_id', 'note', 'created_by'];

    protected static function booted(): void
    {
        // the balance changed: the player's "My cards" and the owner pages update at once
        static::created(function (CardEntry $e) {
            $card = VenueCard::find($e->card_id, ['id', 'venue_id', 'user_id']);
            if ($card) {
                \App\Domain\Club\Events\CardUpdated::dispatch($card->user_id);
                \App\Domain\Club\Events\ClubChanged::dispatch($card->venue_id);
            }
        });
    }

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
