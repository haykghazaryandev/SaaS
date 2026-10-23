<?php

namespace App\Enums;

enum GameStatus: string
{
    case Forming = 'forming';     // only invited friends see it, waiting for ~30% of the players
    case Open = 'open';           // visible to everyone, players join, the chat is on
    case Full = 'full';           // all places taken
    case Booked = 'booked';       // a field is booked for the game (accepted by the place)
    case Played = 'played';       // the booked time has passed
    case Cancelled = 'cancelled';
    case Archived = 'archived';   // the chat went quiet for 7 days

    /** Games that still take players or can be changed. */
    public const ACTIVE = ['forming', 'open', 'full', 'booked'];

    /** Games whose chat is on. */
    public const CHATTING = ['open', 'full', 'booked', 'played'];
}
