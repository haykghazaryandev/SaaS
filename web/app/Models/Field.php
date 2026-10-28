<?php

namespace App\Models;

use App\Enums\FieldSize;
use App\Enums\Sport;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Field extends HasFactoryModel
{
    protected $fillable = ['venue_id', 'name', 'size', 'format', 'surface', 'indoor', 'max_players', 'durations', 'default_duration', 'sort', 'active'];

    protected function casts(): array
    {
        return ['size' => FieldSize::class, 'indoor' => 'boolean', 'durations' => 'array', 'active' => 'boolean'];
    }

    public function sport(): Sport
    {
        return $this->size->sport();
    }

    /** Courts of one kind at a place are one group: players book "a table", the place gets any free one. */
    public function groupKey(): string
    {
        return $this->venue_id.':'.$this->size->value;
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function priceRules(): HasMany
    {
        return $this->hasMany(PriceRule::class)->orderByDesc('priority');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * The name to show: a default name ("Table 2", "Սեղան 2", "Стол 2", "Small field 1" …, made in the language of whoever
     * added the court) in the reader's language; a name the owner typed as it is.
     */
    public function displayName(): ?string
    {
        if (! $this->name) {
            return null;
        }
        foreach (['table', 'small', 'big'] as $kind) {
            foreach (['en', 'hy', 'ru'] as $locale) {
                $parts = array_map(fn ($x) => preg_quote($x, '/'), explode(':n', __('owner.courts.name_'.$kind, [], $locale)));
                $pattern = '/^'.implode('(\d+)', $parts).'$/u';
                if (preg_match($pattern, $this->name, $m)) {
                    return __('owner.courts.name_'.$kind, ['n' => $m[1]]);
                }
            }
        }

        return $this->name;
    }
}
