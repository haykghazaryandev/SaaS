<?php

// Friends page, player profiles, safety (block / report) and the notification bell. Used in Vue as $t('friends.tab.find').
return [
    'title' => 'Friends',
    'kick' => 'Friends · players',
    'h1' => 'Your football circle',
    'lead' => 'Add the people you play with, invite them to games and write to them in the chat.',

    'tab' => ['friends' => 'Friends', 'requests' => 'Requests', 'find' => 'Find players', 'played' => 'Played with you'],
    'received' => 'Want to be your friend',
    'sent' => 'Your requests',
    'search' => 'Name, @username or phone',
    'searchHint' => 'Type at least 2 characters.',
    'noResults' => 'Nobody found. Check the spelling or try the phone number.',
    'empty' => [
        'friendsTitle' => 'No friends yet',
        'friends' => 'No friends yet. Find the people you play with and add them.',
        'requests' => 'No requests right now.',
        'played' => 'When you play in a game, the other players appear here so you can add them.',
    ],
    'findBtn' => 'Find players',
    'together' => '{1} 1 game together|[2,*] :n games together',

    'add' => 'Add friend',
    'accept' => 'Accept',
    'decline' => 'Decline',
    'requested' => 'Requested',
    'cancel' => 'Cancel request',
    'isFriend' => 'Friends',
    'remove' => 'Remove from friends',
    'message' => 'Message',
    'block' => 'Block',
    'unblock' => 'Unblock',
    'report' => 'Report',
    'more' => 'More',
    'you' => 'You',

    'flash' => [
        'requested' => 'Friend request sent.',
        'accepted' => 'You are friends now.',
        'removed' => 'Removed from friends.',
        'declined' => 'Request declined.',
        'cancelled' => 'Request cancelled.',
        'blocked' => ':name is blocked.',
        'unblocked' => ':name is unblocked.',
        'reported' => 'Thank you. We will look at the report.',
    ],

    'profile' => [
        'games' => 'Games',
        'friends' => 'Friends',
        'district' => 'District',
        'level' => 'Level',
        'position' => 'Position',
        'about' => 'About',
        'noBio' => 'No description yet.',
        'edit' => 'Edit profile',
        'blockedByMe' => 'You blocked this player. They can\'t write to you, invite you or add you as a friend.',
        'unavailable' => 'This profile isn\'t available.',
        'blockConfirm' => 'Block :name?',
        'blockText' => 'They won\'t be able to write to you, invite you to games or send you friend requests. They are not told about it.',
        'removeConfirm' => 'Remove :name from friends?',
        'removeText' => 'You can add each other again later.',
    ],

    'reportSheet' => [
        'title' => 'Report',
        'sub' => 'Tell us what is wrong. Only the Bookball team sees the report.',
        'reason' => 'What happened?',
        'placeholder' => 'For example: rude messages, spam, fake profile…',
        'send' => 'Send report',
    ],

    'bell' => [
        'title' => 'Notifications',
        'empty' => 'No notifications yet.',
        'readAll' => 'Mark all as read',
        'open' => 'Notifications',
    ],
    // short label for the phone tab bar
    'nav' => ['messages' => 'Messages'],
    'cancelBtn' => 'Cancel',
    'findTitle' => 'Find players',
    'filter' => 'Search friends',
    'noMatch' => 'No friends match this search.',
    'messageTo' => 'Write to :name',
];
