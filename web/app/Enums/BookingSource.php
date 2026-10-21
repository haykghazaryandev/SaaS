<?php

namespace App\Enums;

enum BookingSource: string
{
    case WebGuest = 'web_guest';    // web booking without an account (name + phone + code)
    case Player = 'player';         // logged-in player
    case Owner = 'owner';           // created by the place (phone call, walk-in)
    case Recurring = 'recurring';   // weekly series
    case Game = 'game';             // booked for a game
    case Tournament = 'tournament';
}
