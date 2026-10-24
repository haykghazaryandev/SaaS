<?php

namespace App\Filament\Resources\Translations;

use App\Filament\Resources\Translations\Pages\CreateTranslation;
use App\Filament\Resources\Translations\Pages\EditTranslation;
use App\Filament\Resources\Translations\Pages\ListTranslations;
use App\Models\Translation;
use App\Support\Translations;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The interface texts (hy, ru, en): the `translations` table, which is the source for the web, the Mini App and the
 * Telegram messages. New keys can be added here or in the seed file (database/seeders/data/translations.json);
 * "Restore the original" puts the seed file's text back; `translations:export` writes the table into the seed file.
 * The placeholders (:name, :n …) must stay the same in every language.
 */
class TranslationResource extends Resource
{
    protected static ?string $model = Translation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static ?string $recordTitleAttribute = 'key';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.system');
    }

    public static function getModelLabel(): string
    {
        return __('common.text');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.translation.many');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['key', 'en', 'hy', 'ru'];
    }

    public static function form(Schema $schema): Schema
    {
        // Armenian and Russian must keep the English :placeholders (the code fills them by name)
        $samePlaceholders = fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
            $en = (string) $get('en');
            if ($value !== null && $value !== '' && self::placeholders($value) !== self::placeholders($en)) {
                $fail(__('admin.translation.placeholders', ['have' => implode(', ', self::placeholders($en)) ?: '—']));
            }
        };
        $original = fn (string $locale) => fn (?Translation $record) => $record && ($text = self::original($locale, $record->key)) !== null && $text !== ''
            ? __('admin.translation.original', ['text' => $text]) : null;

        return $schema->components([
            Section::make()->schema([
                TextInput::make('key')->label(__('admin.translation.key'))->helperText(__('admin.translation.key_help'))
                    ->required()->maxLength(191)->regex('/^[a-z0-9_]+(\.[a-z0-9_-]+)*$/i')->unique(ignoreRecord: true)
                    ->disabledOn('edit')->dehydratedWhenHidden()->dehydrated(fn (string $operation) => $operation === 'create'),
                Textarea::make('en')->label('English')->required()->rows(2)->maxLength(2000)->helperText($original('en')),
                Textarea::make('hy')->label('Հայերեն')->rows(2)->maxLength(2000)->rules([$samePlaceholders])->helperText($original('hy')),
                Textarea::make('ru')->label('Русский')->rows(2)->maxLength(2000)->rules([$samePlaceholders])->helperText($original('ru')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->description(__('admin.translation.export_hint'))
            ->columns([
                TextColumn::make('key')->label(__('admin.translation.key'))->searchable()->sortable()->fontFamily('mono')->size('xs')->wrap(),
                TextColumn::make('en')->label('EN')->searchable()->limit(60)->wrap(),
                TextColumn::make('hy')->label('HY')->searchable()->limit(60)->wrap()->placeholder('—'),
                TextColumn::make('ru')->label('RU')->searchable()->limit(60)->wrap()->placeholder('—'),
                IconColumn::make('edited')->label(__('admin.translation.edited'))->boolean()
                    ->state(fn (Translation $record) => self::isEdited($record))->trueIcon(Heroicon::OutlinedPencilSquare)->falseIcon('')->tooltip(__('admin.translation.edited_help')),
            ])
            ->filters([
                SelectFilter::make('group')->label(__('admin.translation.group'))
                    ->options(fn () => collect(app(Translations::class)->groups())->mapWithKeys(fn ($g) => [$g => $g === '*' ? __('admin.translation.shared') : $g])->all()),
                TernaryFilter::make('missing')->label(__('admin.translation.missing'))
                    ->queries(
                        true: fn (Builder $q) => $q->where(fn ($q) => $q->whereNull('hy')->orWhereNull('ru')),
                        false: fn (Builder $q) => $q->whereNotNull('hy')->whereNotNull('ru'),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('restore')->label(__('admin.translation.restore'))->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                    ->visible(fn (Translation $record) => self::isEdited($record) && self::original('en', $record->key) !== null)
                    ->requiresConfirmation()
                    ->action(function (Translation $record) {
                        $record->update(['en' => self::original('en', $record->key), 'hy' => self::original('hy', $record->key), 'ru' => self::original('ru', $record->key)]);
                        Notification::make()->success()->title(__('admin.translation.restored'))->send();
                    }),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTranslations::route('/'),
            'create' => CreateTranslation::route('/create'),
            'edit' => EditTranslation::route('/{record}/edit'),
        ];
    }

    /** The seed file's text for a key (null: the key is not in the seed file). */
    public static function original(string $locale, string $key): ?string
    {
        return app(Translations::class)->seed()[$key][$locale] ?? null;
    }

    /** Differs from the seed file (a key that is only in the table counts as edited). */
    public static function isEdited(Translation $record): bool
    {
        foreach (['en', 'hy', 'ru'] as $l) {
            if (($record->{$l} ?? '') !== (self::original($l, $record->key) ?? '')) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] sorted :placeholders of a text */
    public static function placeholders(string $text): array
    {
        preg_match_all('/:([a-z][a-z_]*)/i', $text, $m);
        $p = array_values(array_unique($m[1]));
        sort($p);

        return $p;
    }
}
