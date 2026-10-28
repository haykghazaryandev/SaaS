<?php

namespace App\Models;

use App\Enums\Sport;
use App\Models\Concerns\Translatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Venue extends HasFactoryModel
{
    use SoftDeletes, Translatable;

    /** Booking rules of a place; venues.rules overrides any of them. */
    public const DEFAULT_RULES = [
        'horizon_days' => 14,          // how far ahead players can book
        'min_notice_min' => 60,        // no requests closer than this to the start
        'step_min' => 30,              // start times every 30 minutes
        'free_cancel_hours' => 12,     // players cancel for free until this many hours before
        'request_expire_min' => 120,   // a request nobody answered expires after this
        'max_pending_per_phone' => 2,  // open requests per phone at the same time
        'auto_accept_regulars' => false,
        'late_grace_min' => 15,        // after the start: how long the place waits before "team not here?"
        // club cards: % of what a card pays that goes back onto it, by what the card has paid here in all
        'cashback_tiers' => [['from' => 50000, 'pct' => 3], ['from' => 150000, 'pct' => 5], ['from' => 300000, 'pct' => 7]],
    ];

    /**
     * What a place can offer players; the admin switches each one per place (venues.features, null = all of them).
     * A feature made for one client is added here and stays off for the other places until switched on.
     */
    public const FEATURES = [
        'booking',   // players book times
        'team',      // booking team: invite friends, invite link
        'alerts',    // "notify me if it frees up"
        'reviews',   // "How was the place?" after playing
        'games',     // players create and join games here
        // the club package (ping-pong first): off until the admin switches it on for a place
        'wallet',      // player cards with a QR: balance, cashback by level
        'rating',      // the staff set players' ratings in the place's sport
        'trainings',   // players book a table for a training, at the place's training price
        'shop',        // products with photos, sales at the desk
        'tournaments', // registration link, entries, fees, results
        'money',       // the money report: sales by kind, cash, top-ups
    ];

    protected $fillable = ['slug', 'name', 'district', 'address', 'description', 'lat', 'lng', 'phone', 'rating_avg', 'rating_count', 'amenities', 'rules', 'features', 'status', 'listed', 'commission_pct'];

    protected function casts(): array
    {
        return ['address' => 'array', 'description' => 'array', 'amenities' => 'array', 'rules' => 'array', 'features' => 'array', 'listed' => 'boolean', 'rating_avg' => 'float', 'commission_pct' => 'float'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function fields(): HasMany
    {
        return $this->hasMany(Field::class)->orderBy('sort');
    }

    /** Price rules of all fields of the place (used by the admin panel). */
    public function priceRules(): HasManyThrough
    {
        return $this->hasManyThrough(PriceRule::class, Field::class);
    }

    public function openingHours(): HasMany
    {
        return $this->hasMany(OpeningHour::class);
    }

    public function closures(): HasMany
    {
        return $this->hasMany(Closure::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'venue_users')->withPivot('role')->withTimestamps();
    }

    public function rule(string $key): mixed
    {
        return ($this->rules ?? [])[$key] ?? self::DEFAULT_RULES[$key];
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /** Active and shown on the home page (an unlisted place still opens by its link). */
    public function scopeListed($query)
    {
        return $query->active()->where('listed', true);
    }

    /** Price per hour of a training on a kind of court (rules.training_prices), or null: no trainings there. */
    /**
     * Prices by length for a kind of court (rules.length_prices), shortest first: [['min' => 15, 'day' => 1200,
     * 'evening' => null, 'weekend' => null], …]; empty evening / weekend = the day price. Empty list: priced per hour.
     */
    public function lengthPrices(string $size): array
    {
        return self::cleanLengths((array) (($this->rules['length_prices'] ?? [])[$size] ?? []));
    }

    public static function cleanLengths(array $rows): array
    {
        $price = fn ($v) => $v === null || $v === '' ? null : max(0, min(1_000_000, (int) $v));

        return collect($rows)->filter(fn ($r) => is_array($r) && (int) ($r['min'] ?? 0) >= 5 && ($r['day'] ?? null) !== null && $r['day'] !== '')
            ->map(fn ($r) => ['min' => min(720, (int) $r['min']), 'day' => $price($r['day']), 'evening' => $price($r['evening'] ?? null), 'weekend' => $price($r['weekend'] ?? null)])
            ->sortBy('min')->unique('min')->take(10)->values()->all();
    }

    public function trainingPrice(string $size): ?int
    {
        if (! $this->hasFeature('trainings')) {
            return null;
        }
        $p = ($this->rules['training_prices'] ?? [])[$size] ?? null;

        return $p === null || $p === '' ? null : (int) $p;
    }

    /** What a place has while the admin never set its features (new features start off). */
    public const DEFAULT_FEATURES = ['booking', 'team', 'alerts', 'reviews', 'games'];

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features ?? self::DEFAULT_FEATURES, true);
    }

    /** @return string[] the features the place has on */
    public function featureList(): array
    {
        return array_values(array_filter(self::FEATURES, fn ($f) => $this->hasFeature($f)));
    }

    /** @return Sport[] the sports of the place's active courts */
    public function sports(): array
    {
        $sports = [];
        foreach ($this->loadMissing('fields')->fields as $f) {
            if ($f->active) {
                $sports[$f->size->sport()->value] = $f->size->sport();
            }
        }

        return array_values($sports);
    }
}
