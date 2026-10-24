<?php

namespace App\Models;

use App\Support\Translations;
use Illuminate\Database\Eloquent\Model;

/**
 * One interface text in the three languages. `key` is what the code uses: __('player.size.small'), $t('common.fields').
 * `group` is derived from the key (the first part, or "*" for a key without a dot) and tells the loader which keys
 * belong to a Laravel translation group. Edited in /admin → Translations; a change empties the translation caches.
 */
class Translation extends Model
{
    protected $fillable = ['key', 'en', 'hy', 'ru'];

    protected static function booted(): void
    {
        static::saving(function (Translation $t) {
            $t->key = trim($t->key);
            $t->group = self::groupOf($t->key);
            foreach (['hy', 'ru'] as $l) {
                $t->{$l} = trim((string) $t->{$l}) === '' ? null : $t->{$l};
            }
        });
        static::saved(fn () => app(Translations::class)->flush());
        static::deleted(fn () => app(Translations::class)->flush());
    }

    public static function groupOf(string $key): string
    {
        return str_contains($key, '.') ? explode('.', $key, 2)[0] : '*';
    }
}
