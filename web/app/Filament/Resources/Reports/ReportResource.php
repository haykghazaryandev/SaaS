<?php

namespace App\Filament\Resources\Reports;

use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Users\UserResource;
use App\Models\Game;
use App\Models\Message;
use App\Models\Report;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin → Reports: what players reported (a player, a chat message, a game) and why, open ones first. The admin looks
 * (the reported player opens in Users, where they can be blocked) and marks it handled or dismissed.
 */
class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.people');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.report.many');
    }

    public static function getModelLabel(): string
    {
        return __('admin.report.one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.report.many');
    }

    /** Open reports on the menu. */
    public static function getNavigationBadge(): ?string
    {
        $n = Report::where('status', 'open')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** Who was reported: the player (a message: its author, a game: its organiser). */
    public static function targetUser(Report $r): ?User
    {
        return match ($r->target_type) {
            'user' => User::find($r->target_id),
            'message' => User::find(Message::whereKey($r->target_id)->value('user_id')),
            'game' => User::find(Game::whereKey($r->target_id)->value('creator_id')),
            default => null,
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('reporter'))
            ->defaultSort(fn (Builder $query) => $query->orderByRaw("case when status = 'open' then 0 else 1 end")->orderByDesc('created_at'))
            ->columns([
                TextColumn::make('created_at')->label(__('common.when'))->dateTime('d.m.Y H:i', 'Asia/Yerevan'),
                TextColumn::make('reporter.name')->label(__('admin.report.by'))->placeholder('—'),
                TextColumn::make('target_type')->label(__('admin.report.what'))->badge()->color('gray')
                    ->formatStateUsing(fn (string $state) => __('admin.report.type_'.$state)),
                TextColumn::make('target')->label(__('admin.report.who'))
                    ->getStateUsing(fn (Report $r) => self::targetUser($r)?->name ?? '—')
                    ->url(fn (Report $r) => ($u = self::targetUser($r)) ? UserResource::getUrl('view', ['record' => $u]) : null),
                TextColumn::make('quote')->label(__('admin.report.content'))->limit(60)->placeholder('—')
                    ->getStateUsing(fn (Report $r) => $r->target_type === 'message' ? Message::whereKey($r->target_id)->value('body') : null),
                TextColumn::make('reason')->label(__('admin.report.reason'))->wrap()->limit(160)->width('32%'),
                TextColumn::make('status')->label(__('common.status'))->badge()
                    ->formatStateUsing(fn (string $state) => __('admin.report.status_'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'open' => 'danger', 'handled' => 'success', default => 'gray'
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('common.status'))
                    ->options(collect(['open', 'handled', 'dismissed'])->mapWithKeys(fn ($s) => [$s => __('admin.report.status_'.$s)])->all()),
            ])
            ->recordActions([
                Action::make('handled')->label(__('admin.report.handled'))->icon('heroicon-o-check')->color('success')
                    ->visible(fn (Report $r) => $r->status === 'open')
                    ->action(fn (Report $r) => $r->update(['status' => 'handled', 'handled_by' => auth()->id(), 'handled_at' => now()])),
                Action::make('dismiss')->label(__('admin.report.dismiss'))->icon('heroicon-o-x-mark')->color('gray')
                    ->visible(fn (Report $r) => $r->status === 'open')
                    ->action(fn (Report $r) => $r->update(['status' => 'dismissed', 'handled_by' => auth()->id(), 'handled_at' => now()])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListReports::route('/')];
    }
}
