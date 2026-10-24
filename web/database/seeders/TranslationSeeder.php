<?php

namespace Database\Seeders;

use App\Models\Translation;
use App\Support\Translations;
use Illuminate\Database\Seeder;

/**
 * database/seeders/data/translations.json → the `translations` table. Only keys that are not in the table yet
 * are added, so what the admin edited is kept. Runs on every deploy (app:deploy → db:seed), in production too.
 * The other way round: `php artisan translations:export`.
 *
 * database/seeders/data/translation_renames.json lists keys the code stopped using: "old.key": "new.key" renames the
 * row (its edited texts travel with it; when the new key already exists the old row is dropped), "old.key": null
 * deletes it. Add a line there whenever a key is renamed or removed in the code, so every environment follows.
 */
class TranslationSeeder extends Seeder
{
    public const RENAMES = 'database/seeders/data/translation_renames.json';

    public function run(): void
    {
        $renamed = $this->applyRenames();
        $have = Translation::pluck('key')->flip();
        $now = now();
        $rows = [];
        foreach (app(Translations::class)->seed() as $key => $t) {
            if (isset($have[$key]) || ($t['en'] ?? '') === '') {
                continue;
            }
            $rows[] = [
                'group' => Translation::groupOf($key), 'key' => $key,
                'en' => $t['en'], 'hy' => ($t['hy'] ?? '') === '' ? null : $t['hy'], 'ru' => ($t['ru'] ?? '') === '' ? null : $t['ru'],
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            Translation::insert($chunk);
        }
        if ($rows || $renamed) {
            app(Translations::class)->flush();
        }
        $this->command?->info('Translations: '.count($rows).' added, '.count($have).' kept, '.$renamed.' renamed or removed.');
    }

    /** Old keys still in the table → their new key (or gone). Returns how many rows changed. */
    private function applyRenames(): int
    {
        $renames = is_file($f = base_path(self::RENAMES)) ? (json_decode(file_get_contents($f), true) ?: []) : [];
        if (! $renames) {
            return 0;
        }
        $n = 0;
        foreach (Translation::whereIn('key', array_keys($renames))->get() as $row) {
            $new = $renames[$row->key];
            if ($new === null || Translation::where('key', $new)->exists()) {
                $row->delete();   // removed from the code, or merged into a key that is already there
            } else {
                $row->update(['key' => $new]);   // the model hook sets the group
            }
            $n++;
        }

        return $n;
    }
}
