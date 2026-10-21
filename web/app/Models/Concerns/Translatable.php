<?php

namespace App\Models\Concerns;

/**
 * JSON columns that hold {"hy": "...", "ru": "...", "en": "..."}.
 * $model->tr('address') returns the text in the current language (falls back to English, then Armenian).
 */
trait Translatable
{
    public function tr(string $attribute, ?string $locale = null): ?string
    {
        $value = $this->{$attribute};
        if (! is_array($value)) {
            return $value;
        }
        $locale ??= app()->getLocale();

        return $value[$locale] ?? $value['en'] ?? $value['hy'] ?? null;
    }
}
