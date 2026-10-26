<?php

namespace App\Models;

use App\Domain\Club\Events\CardUpdated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A player's card at one place: the QR (code) only works there; balance, cashback level, history. */
class VenueCard extends Model
{
    protected $fillable = ['venue_id', 'user_id', 'code', 'balance', 'note'];

    protected static function booted(): void
    {
        // a new card: the player sees it at once (money changes go through card entries)
        static::saved(function (VenueCard $c) {
            if ($c->wasRecentlyCreated) {
                CardUpdated::dispatch($c->user_id);
            }
        });
    }

    protected function casts(): array
    {
        return ['balance' => 'integer'];
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
