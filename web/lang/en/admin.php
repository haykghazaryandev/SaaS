<?php

// Admin panel (/admin, Filament)
return [
    'nav' => ['places' => 'Places', 'people' => 'People', 'bookings' => 'Bookings', 'system' => 'System'],

    'dashboard' => [
        'today' => 'Bookings today',
        'today_amount' => ':amount ֏ confirmed',
        'pending' => 'Waiting for an answer',
        'oldest' => 'oldest sent :time',
        'none_waiting' => 'No requests are waiting',
        'users' => 'Registered users',
        'users_more' => '+:new this week · :tg with Telegram',
        'places' => 'Active places',
        'places_more' => ':all in total',
    ],

    'venue' => [
        'one' => 'Place', 'many' => 'Places',
        'main' => 'Main', 'texts' => 'Address and description', 'amenities' => 'Amenities',
        'rules' => 'Booking rules', 'rules_help' => 'Leave empty to use the default (shown in grey).',
        'name' => 'Name', 'slug' => 'Link name', 'slug_help' => 'bookball.am/places/<link name>; small letters, digits and -',
        'district' => 'District', 'phone' => 'Phone', 'lat' => 'Latitude', 'lng' => 'Longitude',
        'address' => 'Address', 'description' => 'Description',
        'fields_count' => 'Fields', 'staff_count' => 'Staff', 'pending_count' => 'Waiting',
        'open_site' => 'Open on the site',
        'status' => ['label' => 'Status', 'active' => 'Active', 'paused' => 'Paused', 'draft' => 'Draft'],
    ],

    'rule' => [
        'horizon_days' => 'Book ahead (days)',
        'min_notice_min' => 'Minimum notice (min)',
        'free_cancel_hours' => 'Free cancellation (hours before)',
        'request_expire_min' => 'Request expires after (min)',
        'max_pending_per_phone' => 'Open requests per phone',
        'step_min' => 'Start times every (min)',
        'auto_accept_regulars' => 'Accept regular players automatically',
    ],

    'field' => [
        'one' => 'Field', 'many' => 'Fields', 'size' => 'Size', 'surface' => 'Surface', 'turf' => 'Artificial turf', 'grass' => 'Natural grass',
        'indoor' => 'Indoor', 'durations' => 'Durations', 'default_duration' => 'Default duration', 'sort' => 'Order', 'active' => 'Active',
    ],

    'price' => [
        'one' => 'Price rule', 'many' => 'Prices', 'per_hour' => 'Price per hour', 'weekdays' => 'Days', 'from' => 'From', 'to' => 'To', 'time' => 'Time',
        'priority' => 'Priority', 'priority_help' => 'When rules overlap, the higher priority wins.',
    ],

    'hours' => ['one' => 'Opening hours', 'many' => 'Opening hours', 'weekday' => 'Day', 'opens' => 'Opens', 'closes' => 'Closes'],

    'staff' => [
        'one' => 'Staff member', 'many' => 'Owners and staff', 'role' => 'Role', 'role_owner' => 'Owner', 'role_staff' => 'Staff',
        'add' => 'Add owner or staff', 'change_role' => 'Change role', 'remove' => 'Remove access',
    ],

    'owner' => [
        'create' => 'Create owner account',
        'help' => 'Type the phone and name, choose the place and role. The person then logs in at /owner with this phone and a Telegram code.',
        'submit' => 'Create account',
        'created' => 'Access given to :phone',
        'next' => 'They can log in at /owner now.',
    ],

    'user' => [
        'one' => 'User', 'many' => 'Users', 'name' => 'Name', 'phone' => 'Phone', 'username' => 'Username', 'locale' => 'Language',
        'level' => 'Level', 'bio' => 'About', 'profile' => 'Profile', 'safety' => 'Bookings and safety',
        'registered' => 'Registered', 'guest' => 'Guest (booked without an account)', 'last_seen' => 'Last seen', 'created' => 'Created',
        'role' => 'Role', 'role_admin' => 'Admin', 'role_owner' => 'Owner / staff', 'role_player' => 'Player', 'role_guest' => 'Guest',
        'bookings' => 'Bookings', 'no_shows' => 'No-shows', 'places' => 'Places',
        'status' => 'Status', 'status_active' => 'Active', 'status_blocked' => 'Blocked', 'blocked_until' => 'Blocked until',
        'telegram_linked' => 'Telegram connected',
        'search' => 'Name, @username or phone',
        'only_owners' => 'Owners and staff', 'with_no_shows' => 'With no-shows',
        'block' => 'Block', 'block_until' => 'Block until', 'block_until_help' => 'Leave empty to block until you unblock.',
        'blocked_done' => 'User blocked', 'unblock' => 'Unblock', 'unblocked_done' => 'User unblocked',
        'erase' => 'Delete completely', 'erase_title' => 'Delete :name completely?',
        'erase_help' => 'Removes the account, friends, chats membership, games and alerts. Bookings stay for the places, without the account. The next login with this phone registers a new account. This cannot be undone.',
        'erased' => 'User deleted',
        'reset_no_shows' => 'Reset no-shows', 'reset_no_shows_help' => 'Sets the no-show count to 0 and removes the booking ban.', 'reset_done' => 'No-shows reset',
    ],

    'booking' => [
        'one' => 'Booking', 'many' => 'Bookings', 'code' => 'Code', 'when' => 'When', 'contact' => 'Contact', 'price' => 'Price',
        'status' => 'Status', 'source' => 'Source', 'created' => 'Created', 'team' => 'Team', 'day' => 'Day',
        'note_player' => 'Player note', 'note_owner' => 'Owner note', 'decline_reason' => 'Decline reason', 'cancel_reason' => 'Cancel reason',
        'history' => 'History', 'upcoming' => 'Upcoming', 'tab_all' => 'All', 'open' => 'Open',
        'cancel' => 'Cancel booking', 'cancel_help' => 'The booking is cancelled by Bookball (system). The player gets a Telegram message.',
        'cancelled' => 'Booking :code cancelled',
        'source_web_guest' => 'Web (guest)', 'source_player' => 'Player', 'source_owner' => 'Owner', 'source_recurring' => 'Weekly',
        'source_game' => 'Game', 'source_tournament' => 'Tournament',
    ],

    'log' => ['one' => 'Telegram message', 'many' => 'Notification log', 'text' => 'Text', 'when' => 'When'],

    'district' => [
        'ajapnyak' => 'Ajapnyak', 'arabkir' => 'Arabkir', 'avan' => 'Avan', 'davtashen' => 'Davtashen', 'erebuni' => 'Erebuni',
        'kanaker_zeytun' => 'Kanaker-Zeytun', 'kentron' => 'Kentron', 'malatia' => 'Malatia-Sebastia', 'nornork' => 'Nor Nork',
        'nork_marash' => 'Nork-Marash', 'nubarashen' => 'Nubarashen', 'shengavit' => 'Shengavit',
    ],

    'amenity' => [
        'parking' => 'Parking', 'showers' => 'Showers', 'lockers' => 'Changing rooms', 'cafe' => 'Café', 'lights' => 'Floodlights',
        'bibs' => 'Bibs', 'stands' => 'Stands', 'balls' => 'Balls', 'wifi' => 'Wi-Fi',
    ],

    'weekday' => [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday'],
    'weekday_short' => [1 => 'Mo', 2 => 'Tu', 3 => 'We', 4 => 'Th', 5 => 'Fr', 6 => 'Sa', 0 => 'Su'],
];
