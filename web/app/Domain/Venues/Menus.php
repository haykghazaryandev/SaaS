<?php

namespace App\Domain\Venues;

use App\Models\Venue;

/**
 * Settings → Menus: the owner picks which menus their panel shows and which the staff panel shows, and for some menus
 * how much staff may do (Schedule: view / edit, Shop: sell / edit, Tournaments: run / create, Money: today / all).
 * venues.menus keeps only what was saved; anything missing is the default (owner: all; staff: all but Money, the lower
 * level). The owner may always do everything (their switches only tidy their menu); staff are stopped on the server.
 * ZoneSpot fee, Team and Settings are the owner's only and not listed here.
 */
class Menus
{
    /** In menu order. */
    public const KEYS = ['now', 'schedule', 'requests', 'players', 'cards', 'shop', 'tournaments', 'messages', 'money'];

    /** Staff levels: the lower one first (the default). */
    public const LEVELS = [
        'schedule' => ['view', 'edit'],
        'shop' => ['sell', 'edit'],
        'tournaments' => ['run', 'create'],
        'money' => ['today', 'all'],
    ];

    /** Off for staff until the owner turns it on. */
    private const STAFF_OFF = ['money'];

    /** The menus a place has at all (the admin's features). */
    public static function available(Venue $venue): array
    {
        $has = fn (string ...$f) => collect($f)->contains(fn ($x) => $venue->hasFeature($x));
        $on = [
            'now' => $has('wallet', 'shop'),
            'schedule' => true,
            'requests' => true,
            'players' => $has('wallet', 'rating'),
            'cards' => $has('wallet'),
            'shop' => $has('shop'),
            'tournaments' => $has('tournaments'),
            'messages' => true,
            'money' => $has('money'),
        ];

        return array_values(array_filter(self::KEYS, fn ($k) => $on[$k]));
    }

    /** @return array{owner: array<string,bool>, staff: array<string,bool>, level: array<string,string>} */
    public static function of(Venue $venue): array
    {
        $saved = $venue->menus ?? [];
        $out = ['owner' => [], 'staff' => [], 'level' => []];
        foreach (self::KEYS as $k) {
            $out['owner'][$k] = (bool) ($saved['owner'][$k] ?? true);
            $out['staff'][$k] = (bool) ($saved['staff'][$k] ?? ! in_array($k, self::STAFF_OFF, true));
        }
        foreach (self::LEVELS as $k => $levels) {
            $l = $saved['level'][$k] ?? null;
            $out['level'][$k] = in_array($l, $levels, true) ? $l : $levels[0];
        }

        return $out;
    }

    /** Staff see this menu (the place has it and the owner left it on). */
    public static function staffSees(Venue $venue, string $key): bool
    {
        return in_array($key, self::available($venue), true) && self::of($venue)['staff'][$key];
    }

    /** Staff may do this much here (null: just see the menu). */
    public static function staffMay(Venue $venue, string $key, ?string $level = null): bool
    {
        if (! self::staffSees($venue, $key)) {
            return false;
        }
        if ($level === null || ! isset(self::LEVELS[$key])) {
            return true;
        }
        $levels = self::LEVELS[$key];

        return array_search(self::of($venue)['level'][$key], $levels, true) >= array_search($level, $levels, true);
    }

    /** What one role may do here: the owner everything, staff what the owner allowed. */
    public static function may(Venue $venue, string $role, string $key, ?string $level = null): bool
    {
        return $role === 'owner' || self::staffMay($venue, $key, $level);
    }

    /** Where this role lands: the first menu of its panel (a club starts at the desk, "Now"). */
    public static function start(Venue $venue, string $role): string
    {
        $menus = self::of($venue)[$role === 'owner' ? 'owner' : 'staff'];
        foreach (self::available($venue) as $k) {
            if ($menus[$k]) {
                return 'owner.'.$k;
            }
        }

        return $role === 'owner' ? 'owner.settings' : 'owner.messages';
    }
}
