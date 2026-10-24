<?php

namespace App\Support;

use Illuminate\Contracts\Translation\Loader;

/**
 * __(), trans() and trans_choice(): the app's texts come from the `translations` table (App\Support\Translations).
 * Laravel's own file loader stays underneath for the framework's and packages' texts (validation, pagination,
 * Filament…), which are not ours. Put in place by AppServiceProvider: "translation.loader" is a deferred binding,
 * so it is extended, not replaced.
 */
class DatabaseTranslationLoader implements Loader
{
    public function __construct(private Loader $files, private Translations $translations) {}

    public function load($locale, $group, $namespace = null): array
    {
        $lines = $this->files->load($locale, $group, $namespace);
        if ($namespace !== null && $namespace !== '*') {
            return $lines;   // a package's texts
        }
        foreach ($this->translations->texts($locale, $group) as $key => $text) {
            if ($group === '*') {
                $lines[$key] = $text;   // keys without a dot ("brand"): Laravel looks them up flat, like JSON texts
            } else {
                data_set($lines, $key, $text);
            }
        }

        return $lines;
    }

    public function addNamespace($namespace, $hint): void
    {
        $this->files->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path): void
    {
        $this->files->addJsonPath($path);
    }

    public function namespaces(): array
    {
        return $this->files->namespaces();
    }
}
