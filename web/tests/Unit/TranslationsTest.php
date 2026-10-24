<?php

// The main translation (database/seeders/data/translations.json): every key has all three languages with the same
// placeholders (:name, :code…), and the keys work with Laravel's group.item lookup.
// English is the source; Armenian and Russian must follow it.

function seedTexts(): array
{
    return json_decode(file_get_contents(dirname(__DIR__, 2).'/database/seeders/data/translations.json'), true);
}

function placeholders(string $text): array
{
    preg_match_all('/:([a-z][a-z_]*)/i', $text, $m);
    $p = array_unique($m[1]);
    sort($p);

    return $p;
}

it('has every text in all three languages', function () {
    $missing = [];
    foreach (seedTexts() as $key => $t) {
        foreach (['en', 'hy', 'ru'] as $l) {
            if (trim($t[$l] ?? '') === '') {
                $missing[] = "{$key} ({$l})";
            }
        }
    }
    expect($missing)->toBe([], 'missing: '.implode(', ', array_slice($missing, 0, 20)));
});

it('keeps the same placeholders in every language', function () {
    $wrong = [];
    foreach (seedTexts() as $key => $t) {
        foreach (['hy', 'ru'] as $l) {
            if (placeholders($t['en']) !== placeholders($t[$l])) {
                $wrong[] = $key.' ('.implode(',', placeholders($t['en'])).' vs '.$l.': '.implode(',', placeholders($t[$l])).')';
            }
        }
    }
    expect($wrong)->toBe([], 'placeholders differ: '.implode('; ', array_slice($wrong, 0, 10)));
});

it('has well-formed keys, none a prefix of another, sorted', function () {
    $keys = array_keys(seedTexts());
    expect(array_filter($keys, fn ($k) => ! preg_match('/^[a-z0-9_]+(\.[a-z0-9_-]+)*$/i', $k)))->toBe([]);
    $sorted = $keys;
    sort($sorted, SORT_STRING);
    expect($keys)->toBe($sorted, 'run php artisan translations:export to sort the file');
    // "a.b" and "a.b.c" cannot both exist: Laravel nests group texts (a.b would be both a text and an array)
    $set = array_flip($keys);
    $conflicts = [];
    foreach ($keys as $k) {
        for ($p = strrpos($k, '.'); $p !== false; $p = strrpos($k, '.', $p - strlen($k) - 1)) {
            if (isset($set[substr($k, 0, $p)])) {
                $conflicts[] = substr($k, 0, $p).' < '.$k;
            }
            if ($p === 0) {
                break;
            }
        }
    }
    expect($conflicts)->toBe([]);
});
