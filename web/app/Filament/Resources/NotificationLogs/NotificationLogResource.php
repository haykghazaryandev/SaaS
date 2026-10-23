<?php

namespace App\Filament\Resources\NotificationLogs;

use App\Filament\Resources\NotificationLogs\Pages\ListNotificationLogs;
use App\Models\NotificationLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Read-only list of Telegram messages we sent (or tried to). */
class NotificationLogResource extends Resource
{
    protected static ?string $model = NotificationLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.system');
    }

    public static function getModelLabel(): string
    {
        return __('admin.log.one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.log.many');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('type'),
            TextEntry::make('status')->badge(),
            TextEntry::make('user.name')->label(__('admin.user.one'))->placeholder('—'),
            TextEntry::make('chat_id'),
            TextEntry::make('payload.text')->label(__('admin.log.text'))->html()->columnSpanFull()
                ->formatStateUsing(fn ($state) => nl2br(strip_tags((string) $state, '<b><i><a><code>'))),
            TextEntry::make('error')->placeholder('—')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('admin.log.when'))->dateTime('d.m H:i:s', 'Asia/Yerevan'),
                TextColumn::make('type')->badge()->color('gray')->searchable(),
                TextColumn::make('user.name')->label(__('admin.user.one'))->placeholder('—'),
                TextColumn::make('payload.text')->label(__('admin.log.text'))->limit(70)->formatStateUsing(fn ($state) => strip_tags((string) $state)),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'sent' => 'success', 'failed' => 'danger', default => 'gray'
                }),
                TextColumn::make('error')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['sent' => 'sent', 'logged' => 'logged', 'failed' => 'failed']),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListNotificationLogs::route('/')];
    }
}
