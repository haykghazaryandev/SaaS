<?php

namespace App\Enums;

enum VenueRole: string
{
    case Owner = 'owner';   // everything for the place
    case Staff = 'staff';   // schedule and bookings only
}
