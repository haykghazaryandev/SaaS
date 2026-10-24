<?php

namespace App\Console\Commands;

use App\Models\Translation;
use App\Support\Translations;
use Illuminate\Console\Command;

/**
 * The `translations` table → database/seeders/data/translations.json (sorted by key), so texts edited in the admin
 * panel can be committed and reach every environment through the seeder.
 */
class TranslationsExport extends Command
{
    protected $signature = 'translations:export {--path= : write somewhere else than the seed file}';

    protected $description = 'Write the translations table into database/seeders/data/translations.json';

    public function handle(): int
    {
        $rows = Translation::orderBy('key')->get(['key', 'en', 'hy', 'ru']);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->key] = ['en' => $r->en, 'hy' => (string) $r->hy, 'ru' => (string) $r->ru];
        }
        ksort($out, SORT_STRING);
        $path = $this->option('path') ?: base_path(Translations::SEED);
        file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        $this->info(count($out)." texts written to {$path}");

        return self::SUCCESS;
    }
}
