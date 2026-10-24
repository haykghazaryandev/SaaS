<?php

namespace App\Support;

use App\Models\Translation;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Interface texts (hy, ru, en): the `translations` table is the source.
 *
 * database/seeders/data/translations.json holds the main translation: TranslationSeeder puts it into the table
 * (new keys only), `translations:export` writes the table back into it. While the table is empty or unreachable
 * (fresh install, Docker build, tests), the texts are read from that file, so no page ever shows raw keys.
 *
 *  - texts(locale, group)  "size.small" => "Փոքր" for one Laravel group (what the loader nests), JSON-style keys as is
 *  - forClient(locale)     everything flat, with English where a language is empty: what the browser loads
 *  - version()             changes with every edit: the browser caches /lang/<locale>.json by it
 */
class Translations
{
    public const SEED = 'database/seeders/data/translations.json';

    private static bool $dbDown = false;

    private static ?array $seed = null;

    /** The rows of one group, from the table (cached), or from the seed file while the table is empty / unreachable. */
    public function rows(string $group): array
    {
        if (! self::$dbDown) {
            try {
                if (Cache::rememberForever('translations.count', fn () => Translation::count()) > 0) {
                    return Cache::rememberForever("translations.{$group}", fn () => Translation::where('group', $group)->get(['key', 'en', 'hy', 'ru'])->keyBy('key')->toArray());
                }
            } catch (Throwable) {
                self::$dbDown = true;   // no database right now: the seed file, and stop asking in this process
            }
        }

        return array_filter($this->seed(), fn ($row, $key) => Translation::groupOf($key) === $group, ARRAY_FILTER_USE_BOTH);
    }

    /** Non-empty texts of a group in one language, keyed without the group prefix (Laravel nests them): "size.small" => "Փոքր". */
    public function texts(string $locale, string $group): array
    {
        $out = [];
        foreach ($this->rows($group) as $key => $row) {
            if (($row[$locale] ?? null) !== null && $row[$locale] !== '') {
                $out[$group === '*' ? $key : substr($key, strlen($group) + 1)] = $row[$locale];
            }
        }

        return $out;
    }

    /** Every text of a language, flat ("player.size.small"), English where the language is empty. */
    public function forClient(string $locale): array
    {
        $texts = [];
        foreach ($this->groups() as $group) {
            foreach ($this->rows($group) as $key => $row) {
                $texts[$key] = ($row[$locale] ?? '') !== '' && $row[$locale] !== null ? $row[$locale] : $row['en'];
            }
        }
        ksort($texts, SORT_STRING);

        return $texts;
    }

    /** All groups ("player", "owner", … and "*"). */
    public function groups(): array
    {
        $fromSeed = fn () => array_values(array_unique(array_map(Translation::groupOf(...), array_keys($this->seed()))));
        if (self::$dbDown) {
            return $fromSeed();
        }
        try {
            $groups = Cache::rememberForever('translations.groups', fn () => Translation::query()->distinct()->orderBy('group')->pluck('group')->all());
        } catch (Throwable) {
            self::$dbDown = true;

            return $fromSeed();
        }

        return $groups ?: $fromSeed();
    }

    public function version(): string
    {
        $db = self::$dbDown ? null : rescue(fn () => Cache::rememberForever('translations.version', fn () => Translation::max('updated_at').'/'.Translation::count()), null, false);

        return substr(md5(($db ?: 'seed').'|'.@filemtime(base_path(self::SEED))), 0, 10);
    }

    /** The seed file: key => [en, hy, ru]. The original texts, what "Restore the original" goes back to. */
    public function seed(): array
    {
        return self::$seed ??= is_file($f = base_path(self::SEED)) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    }

    /** After a row changed: forget the cached rows, groups and version, and what this process already translated. */
    public function flush(): void
    {
        foreach ([...$this->groups(), '*'] as $group) {
            Cache::forget("translations.{$group}");
        }
        foreach (['count', 'groups', 'version'] as $k) {
            Cache::forget("translations.{$k}");
        }
        if (app()->resolved('translator')) {
            app('translator')->setLoaded([]);   // the queue worker and the tests keep one translator for a long time
        }
    }
}
