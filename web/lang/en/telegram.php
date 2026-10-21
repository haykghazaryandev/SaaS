<?php

// Telegram bot messages (HTML). Placeholders: :venue, :code, :name, :reason, :when, :status, :bot, :url
return [
    'player' => [
        'requested' => '📨 Request sent to <b>:venue</b>. We will write here as soon as the place answers.',
        'accepted' => "✅ Confirmed! Your booking code is <b>:code</b>.\nShow it at the place. Payment at the field.",
        'declined' => '❌ <b>:venue</b> declined your request. Tip: on the place page, tap a booked time and we\'ll tell you when it frees up.',
        'changed' => '✏️ <b>:venue</b> changed your booking. New details:',
        'cancelled' => '🚫 Your booking at <b>:venue</b> was cancelled.',
        'expired' => '⌛ <b>:venue</b> did not answer in time, so your request expired. The time is free again, try another place or time.',
    ],
    'owner' => [
        'requested' => '🆕 New request at <b>:venue</b>',
        'cancelled' => '🚫 The player cancelled a booking at <b>:venue</b>',
        'accepted_by' => '✅ Accepted by :name',
        'declined_by' => '✖ Declined by :name',
        'status_now' => 'Status: :status',
    ],
    'button' => [
        'accept' => 'Accept',
        'decline' => 'Decline',
    ],
    'status' => [
        'pending' => 'Waiting',
        'accepted' => 'Accepted',
        'declined' => 'Declined',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
        'completed' => 'Played',
        'no_show' => 'No-show',
    ],
    'field' => [
        'small' => 'Small field 5×5',
        'big' => 'Big field 11×11',
    ],
    'reason' => 'Reason: :reason',
    'was' => 'Was: :when',
    'bot' => [
        'hello' => "👋 Hi! This is the Bookball bot.\n\nTo get booking updates here, open your profile on :url and tap «Connect Telegram».",
        'already' => '👋 Your Telegram is already connected to Bookball. Booking updates will come here.',
        'linked' => "✅ Done:name! Telegram is connected to your Bookball account.\nBooking confirmations and updates will come here.",
        'link_expired' => '⚠️ This link has expired or was already used. Tap «Connect Telegram» on the site again.',
        'help' => 'I only send booking updates. Book and manage your games on :url',
        'not_allowed' => 'You can not answer requests of this place.',
    ],
    // web: TelegramConnect.vue
    'connect' => [
        'title' => 'Telegram notifications',
        'sub' => 'Get booking confirmations and changes in Telegram.',
        'button' => 'Connect Telegram',
        'waiting' => 'Waiting… Press «Start» in the Bookball bot.',
        'open_again' => 'Open the bot again',
        'done' => 'Telegram is connected',
        'error' => 'Something went wrong. Try again.',
    ],
    'login' => [
        'btnBook' => '⚽ Book a field',
        'btnGames' => '👥 Find players',
        'ask' => "👋 To log in to Bookball, tap the button below. Telegram will share the phone number of your account, so we know it's really you.",
        'button' => "📱 Share my phone number",
        'done' => "✅ You're logged in:name! Go back to the browser or app.\n\nBooking updates will come here.",
        'linked' => "✅ Your phone is confirmed and Telegram is connected:name. Log in at :url with «Log in with Telegram».",
        'expired' => "⌛ This login link has expired. Go back to the website and tap «Log in with Telegram» again.",
        'not_own' => "Please use the button below: it shares the number of your own Telegram account.",
        'bad_phone' => "Bookball works with Armenian mobile numbers (+374) for now. Use «Log in with email» on the website instead.",
        'blocked' => "This account is blocked. Write to Bookball support.",
    ],
    'lang' => [
        'choose' => '🌐 Choose your language for Bookball (the bot and the website):',
        'set' => '✅ Language: English. The bot and the website now speak English.',
        'short' => 'Language saved',
        'button' => 'Language',
        'need_login' => 'Log in to Bookball with Telegram first.',
    ],
    'menu' => [
        'start' => 'Start · log in',
        'language' => 'Change language',
        'about' => 'Bookball: book a football field in Yerevan, find players for a game and get every booking update here.',
        'short' => 'Football fields in Yerevan: booking, games, updates.',
    ],
];
