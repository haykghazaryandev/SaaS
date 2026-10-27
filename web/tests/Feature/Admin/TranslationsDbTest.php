<?php

// Interface texts in the database: the seeder fills the table from the seed file once, the table is what PHP and the
// browser use, the seed file serves while the table is empty, and the export writes the table back.

use App\Models\Translation;
use App\Support\Translations;
use Database\Seeders\TranslationSeeder;

it('translates from the seed file while the table is empty', function () {
    expect(Translation::count())->toBe(0)
        ->and(__('player.size.small', [], 'hy'))->toBe('Փոքր')
        ->and(__('common.fields', [], 'ru'))->toBe('Поля')
        ->and(__('brand'))->toBe('ZoneSpot');
    expect($this->getJson('/lang/hy.json')->assertOk()->json()['player.size.small'])->toBe('Փոքր');
});

it('seeds every key once, grouped by the first part of the key', function () {
    $this->seed(TranslationSeeder::class);
    $seed = app(Translations::class)->seed();
    expect(Translation::count())->toBe(count($seed));
    $row = Translation::where('key', 'player.size.small')->firstOrFail();
    expect($row->group)->toBe('player')->and($row->en)->toBe('Small')->and($row->hy)->toBe('Փոքր');
    expect(Translation::where('key', 'common.fields')->value('group'))->toBe('common')
        ->and(Translation::where('key', 'brand')->value('group'))->toBe('*');

    $this->seed(TranslationSeeder::class);   // again: nothing doubled
    expect(Translation::count())->toBe(count($seed));
});

it('renames, merges and removes keys of an existing table as database/seeders/data/translation_renames.json says', function () {
    Translation::insert([
        ['group' => 'owner', 'key' => 'owner.st.pending', 'en' => 'Request (edited)', 'hy' => 'Հայտ', 'ru' => 'Заявка', 'created_at' => now(), 'updated_at' => now()],
        ['group' => 'owner', 'key' => 'owner.b.field', 'en' => 'Field', 'hy' => 'Դաշտ', 'ru' => 'Поле', 'created_at' => now(), 'updated_at' => now()],
        ['group' => 'common', 'key' => 'common.field', 'en' => 'Field', 'hy' => 'Դաշտ', 'ru' => 'Поле', 'created_at' => now(), 'updated_at' => now()],
        ['group' => 'player', 'key' => 'player.place.s1', 'en' => 'Pick a time', 'hy' => 'Ընտրեք ժամը', 'ru' => 'Выберите время', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $this->seed(TranslationSeeder::class);
    expect(Translation::where('key', 'owner.st.pending')->exists())->toBeFalse()
        ->and(Translation::where('key', 'owner.status.pending')->first())->toMatchArray(['group' => 'owner', 'en' => 'Request (edited)'])   // renamed, the edit kept
        ->and(Translation::where('key', 'owner.b.field')->exists())->toBeFalse()   // merged into common.field, which already existed
        ->and(Translation::where('key', 'common.field')->count())->toBe(1)
        ->and(Translation::where('key', 'player.place.s1')->exists())->toBeFalse()   // removed: no code uses it
        ->and(Translation::count())->toBe(count(app(Translations::class)->seed()));
});

it('uses the table in PHP: a group key, a shared key, and the rest untouched', function () {
    $this->seed(TranslationSeeder::class);
    Translation::where('key', 'player.size.small')->firstOrFail()->update(['hy' => 'Մինի']);
    Translation::where('key', 'brand')->firstOrFail()->update(['en' => 'ZoneSpot ⚽']);

    expect(__('player.size.small', [], 'hy'))->toBe('Մինի')
        ->and(__('player.size.big', [], 'hy'))->toBe('Մեծ')
        ->and(__('brand', [], 'en'))->toBe('ZoneSpot ⚽')
        ->and(__('player.size.small', [], 'ru'))->toBe('Малое')
        ->and(trans_choice('games.card.spots_left', 3, ['n' => 3], 'en'))->toBe('3 spots left');
});

it('shows English while a language is empty, in PHP and in the browser', function () {
    $this->seed(TranslationSeeder::class);
    Translation::where('key', 'player.size.small')->firstOrFail()->update(['hy' => '']);   // saved as null
    expect(Translation::where('key', 'player.size.small')->value('hy'))->toBeNull()
        ->and(__('player.size.small', [], 'hy'))->toBe('Small');
    expect($this->getJson('/lang/hy.json')->assertOk()->json()['player.size.small'])->toBe('Small');
});

it('keeps the admin edits when seeding again, and a new key made in the admin', function () {
    $this->seed(TranslationSeeder::class);
    Translation::where('key', 'player.size.small')->firstOrFail()->update(['hy' => 'Մինի']);
    Translation::create(['key' => 'player.size.tiny', 'en' => 'Tiny']);
    $this->seed(TranslationSeeder::class);
    expect(Translation::where('key', 'player.size.small')->value('hy'))->toBe('Մինի')
        ->and(Translation::where('key', 'player.size.tiny')->value('group'))->toBe('player')
        ->and(__('player.size.tiny', [], 'hy'))->toBe('Tiny');
});

it('serves every text flat to the browser, cached by a version that changes with an edit', function () {
    $this->seed(TranslationSeeder::class);
    $before = app(Translations::class)->version();
    $texts = $this->getJson('/lang/hy.json')->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')->json();
    expect($texts['player.size.small'])->toBe('Փոքր')->and($texts['common.fields'])->toBe('Դաշտեր')
        ->and(count($texts))->toBe(Translation::count());

    $this->travel(1)->second();
    Translation::where('key', 'player.size.small')->firstOrFail()->update(['hy' => 'Մինի']);
    expect($this->getJson('/lang/hy.json')->assertOk()->json()['player.size.small'])->toBe('Մինի')
        ->and(app(Translations::class)->version())->not->toBe($before);

    $this->getJson('/lang/de.json')->assertNotFound();
    $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->has('langVersion'));
});

it('exports the table into the seed file format', function () {
    $this->seed(TranslationSeeder::class);
    Translation::where('key', 'player.size.small')->firstOrFail()->update(['hy' => 'Մինի']);
    $path = sys_get_temp_dir().'/bookball-translations-'.uniqid().'.json';
    $this->artisan('translations:export', ['--path' => $path])->assertSuccessful();
    $out = json_decode(file_get_contents($path), true);
    unlink($path);
    expect($out['player.size.small'])->toBe(['en' => 'Small', 'hy' => 'Մինի', 'ru' => 'Малое'])
        ->and(array_keys($out))->toBe(array_keys(app(Translations::class)->seed()));
});
