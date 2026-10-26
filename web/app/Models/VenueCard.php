<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A player's card at one place: the QR (code) only works there; balance, discount, history. */
class VenueCard extends Model
{
    protected $fillable = ['venue_id', 'user_id', 'code', 'balance', 'discount_pct', 'note'];

    protected static function booted(): void
    {
        // a new card or a new discount: the player sees it at once
        static::saved(function (VenueCard $c) {
            if ($c->wasRecentlyCreated || $c->wasChanged('discount_pct')) {
                \App\Domain\Club\Events\CardUpdated::dispatch($c->user_id);
            }
        });
    }

    protected function casts(): array
    {
        return ['balance' => 'integer', 'discount_pct' => 'integer'];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CardEntry::class, 'card_id')->latest('id');
    }

    /** What the QR holds: a link the owner's scanner opens (/owner/cards/{code}); it only works for that place's staff. */
    public function qrUrl(): string
    {
        return url('/c/'.$this->code);
    }
}
