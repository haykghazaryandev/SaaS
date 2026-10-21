<?php

namespace App\Enums;

enum FieldSize: string
{
    case Small = 'small';   // 5×5, 10 players
    case Big = 'big';       // 11×11, 22 players

    public function format(): string
    {
        return $this === self::Small ? '5×5' : '11×11';
    }

    public function maxPlayers(): int
    {
        return $this === self::Small ? 10 : 22;
    }
}
