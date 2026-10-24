<?php

// Every text exists in all three languages, with the same placeholders (:name, :code…).
// English (lang/en) is the source; Armenian and Russian must follow it.

function flatLang(array $a, string $p = ''): array
{
    $o = [];
    foreach ($a as $k => $v) {
        is_array($v) ? $o += flatLang($v, "{$p}{$k}.") : $o["{$p}{$k}"] = (string) $v;
    }

    return $o;
}

function langTexts(string $locale): array
{
    $dir = dirname(__DIR__, 2).'/lang';
    $texts = json_decode(file_get_contents("{$dir}/{$locale}.json"), true);
    foreach (glob("{$dir}/{$locale}/*.php") as $file) {
        $texts += flatLang(include $file, basename($file, '.php').'.');
    }

    return $texts;
}

function placeholders(string $text): array
{
    preg_match_all('/:([a-z][a-z_]*)/i', $text, $m);
    $p = array_unique($m[1]);
    sort($p);

    return $p;
}

dataset('locales', ['hy', 'ru']);

it('has every English text', function (string $locale) {
    $missing = array_keys(array_diff_key(langTexts('en'), langTexts($locale)));
    expect($missing)->toBe([], "missing in {$locale}: ".implode(', ', array_slice($missing, 0, 20)));
})->with('locales');

it('has no texts that English does not have', function (string $locale) {
    $extra = array_keys(array_diff_key(langTexts($locale), langTexts('en')));
    expect($extra)->toBe([], "only in {$locale} (left over?): ".implode(', ', array_slice($extra, 0, 20)));
})->with('locales');

it('keeps the same placeholders', function (string $locale) {
    $en = langTexts('en');
    $other = langTexts($locale);
    $wrong = [];
    foreach ($en as $key => $text) {
        if (isset($other[$key]) && placeholders($text) !== placeholders($other[$key])) {
            $wrong[] = $key.' ('.implode(',', placeholders($text)).' vs '.implode(',', placeholders($other[$key])).')';
        }
    }
    expect($wrong)->toBe([], "placeholders differ in {$locale}: ".implode('; ', array_slice($wrong, 0, 10)));
})->with('locales');

it('has no empty texts', function (string $locale) {
    $empty = array_keys(array_filter(langTexts($locale), fn ($t) => trim($t) === ''));
    expect($empty)->toBe([]);
})->with(['en', 'hy', 'ru']);
