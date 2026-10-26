<?php

namespace App\Enums;

enum FieldSize: string
{
    case Small = 'small';   // 5×5, 10 players
    case Big = 'big';       // 11×11, 22 players
    case Table = 'table';   // ping-pong table, 1×1 or 2×2

    public function sport(): Sport
    {
        return $this === self::Table ? Sport::Pingpong : Sport::Football;
    }

    public function format(): string
    {
        return match ($this) {
            self::Small => '5×5',
            self::Big => '11×11',
            self::Table => '🏓',
        };
    }

    public function maxPlayers(): int
    {
        return match ($this) {
            self::Small => 10,
            self::Big => 22,
            self::Table => 4,
        };
    }
}
