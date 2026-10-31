<?php

namespace App\Enums;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The sports of the platform. What a sport is (its kinds of court, game formats) lives here;
 * whether players see it lives in the `sports` table (admin → Sports).
 */
enum Sport: string
{
    case Football = 'football';
    case Pingpong = 'pingpong';

    /** The kinds of court of the sport (FieldSize values). */
    public function sizes(): array
    {
        return match ($this) {
            self::Football => [FieldSize::Small, FieldSize::Big],
            self::Pingpong => [FieldSize::Table],
        };
    }

    public function sizeValues(): array
    {
        return array_map(fn (FieldSize $s) => $s->value, $this->sizes());
    }

    /** Players per side a game of this sport can have. */
    public function sides(): array
    {
        return match ($this) {
            self::Football => range(3, 11),
            self::Pingpong => [1, 2],
        };
    }

    /** Players of this sport have a rating (ping-pong: points, the federation's list); football has none. */
    public function rated(): bool
    {
        return $this === self::Pingpong;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Football => '⚽',
            self::Pingpong => '🏓',
        };
    }

    public function label(): string
    {
        return __('common.sports.'.$this->value);
    }

    /** Sports shown to players, in the admin's order (all of them while the table is missing, e.g. during a deploy). */
    public static function visible(): array
    {
        try {
            $keys = Cache::remember('sports.visible', 300, fn () => DB::table('sports')->where('visible', true)->orderBy('sort')->pluck('key')->all());
        } catch (Throwable) {
            return self::cases();
        }

        return array_values(array_filter(array_map(fn ($k) => self::tryFrom($k), $keys)));
    }

    public static function forgetVisible(): void
    {
        Cache::forget('sports.visible');
    }
}
