<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Something the place sells (balls, rackets, drinks): photo, price, discount. */
class Product extends Model
{
    protected $fillable = ['venue_id', 'name', 'description', 'price', 'discount_pct', 'in_stock', 'active', 'photo_token', 'photo', 'sort'];

    protected static function booted(): void
    {
        static::saved(fn (Product $m) => \App\Domain\Club\Events\ClubChanged::dispatch($m->venue_id));
    }

    protected function casts(): array
    {
        return ['price' => 'integer', 'discount_pct' => 'integer', 'in_stock' => 'boolean', 'active' => 'boolean'];
    }

    protected $hidden = ['photo'];

    /** Price after the product's own discount. */
    public function finalPrice(): int
    {
        return (int) round($this->price * (100 - $this->discount_pct) / 100);
    }

    public function photoUrl(): ?string
    {
        return $this->photo_token ? url("/shop-photo/{$this->id}-{$this->photo_token}.webp") : null;
    }
}
