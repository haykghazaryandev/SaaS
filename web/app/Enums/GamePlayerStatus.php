<?php

namespace App\Enums;

enum GamePlayerStatus: string
{
    case Invited = 'invited';     // a friend invited them, no answer yet
    case Accepted = 'accepted';   // accepted an invite
    case Declined = 'declined';
    case Joined = 'joined';       // joined an open game by themselves (or the creator)
    case Left = 'left';
    case Removed = 'removed';     // removed by the creator

    /** Players who count as "in the game". */
    public const IN = ['accepted', 'joined'];
}
