# Bookball: technical plan (v3)

*Football field booking + find players + chat, for Yerevan. Updated 2026-10-20.*
*Older versions are kept in `plan-v1-old.md` and `plan-v2.md`.*

```
/Users/develop/bookball/
  docs/           this plan
  prototype/      clickable HTML prototypes (player/, owner/)
  web/            the real Laravel application (player web + owner web + admin)
  mobile/         React Native app (later, after the web logic is done)
```

---

## 0. Decisions

| Topic | Decision |
|---|---|
| Name | **Bookball** (bookball.am) |
| Sport | Football only |
| Fields | Every place has **two fields**: small **5×5** and big **11×11** |
| Order of work | **Web first** (all logic), **mobile second** |
| Web apps | **Player web** and **owner web**, plus an **admin panel** only for you |
| Mobile app | **React Native (Expo)**, one app for Play Market and App Store. Players and owners log in to the same app, and **owners see a different UI** (their schedule). Comes after the web. |
| Backend | **Laravel 12**, PostgreSQL, Redis, **Reverb** (WebSockets for chat) |
| Accounts | Players **register / log in for free** with **"Log in with Telegram"**: our bot asks them to share their phone, so Telegram confirms the number. **Email code** is the backup for people without Telegram, and their phone is not confirmed. The paid Telegram Gateway code is not used. **Owner accounts are created only by the admin (you)** in the admin panel. |
| Booking on web | Works **without an account**: name + phone + Telegram code. Behind the scenes we find or create the user by phone. |
| Booking in mobile | Must be logged in |
| Booking flow | The player sends a **request**, and the owner **accepts or declines**. The owner can also create and edit bookings. |
| Games | Create a game **without a field**: "N vs N" (3–11 per side). The creator invites friends. The game becomes **open** (visible to everyone) when **~30% of the players** have accepted (creator included). Others join. The players **chat**, agree on a day and then book a field. |
| Social | **Friends**, **private messages**, **game group chats**. No permanent groups (teams come later with tournaments). |
| Chat cleanup | A game chat becomes **read-only after 7 days without messages** (and no upcoming booking), and is **deleted after 30 more days**. "Play again" starts a new game with the same people. |
| Notifications | **Telegram bot** (codes and messages), **in-app** notifications (real time via Reverb), and **push** later in mobile |
| Payments | Pay at the field (no online payments in the MVP) |
| Tournaments | Later. Created only by the admin, shown in the player apps. |
| Languages | Armenian, Russian, English |

---

## 1. How it works: two stories

### 1.1 Booking a field
1. Aram opens bookball.am, picks a day, time and field size, then a place.
2. He taps **Send request**:
   - on the web without an account: he types his name and phone, then the code from Telegram
   - in the app: he is already logged in
3. The **owner** sees the request in the owner app, and also gets it in Telegram with **Accept / Decline** buttons. He accepts.
4. Aram gets a Telegram message (later also a push): "✅ Confirmed, code BB-4821".

### 1.2 Finding players: a game
1. Aram has only 2 friends who want to play, but wants to play **5×5** (10 players).
2. He creates a game:
   - **"5 vs 5, intermediate, Arabkir, this week evenings"**
   - he invites his friends Davit and Narek
3. 30% of 10 = **3 players** are needed to open. Aram counts as 1, so the game opens after 2 friends accept.
   - Davit and Narek accept, so the game is **open** and is shown to everyone in **Games**.
   - Before that only the invited friends see it, which stops people from creating games and waiting alone.
4. A **game chat** starts (real-time, WebSockets) with the 3 accepted players.
5. 7 more players find the game and join, and each one enters the chat. At **10/10** the game is **full**, and everybody gets a message.
6. In the chat they agree on "Thursday 21:00 at Zangak". The **creator (organiser)** taps **Book for this game**, which sends a normal booking request linked to the game. When the owner accepts, the chat shows "✅ Field booked: Zangak 5×5, Thu 21:00–22:00".
7. After the game, players can **add each other as friends** ("Players you played with"), and next time invite them directly. **Play again** creates a new game with the same people invited.
8. If nobody writes in the chat for **7 days** (and there is no upcoming booking), the chat becomes read-only, and it is deleted 30 days later.

---

## 2. Roles

| Role | Where | What |
|---|---|---|
| Guest | Web | Browse places, free times, open games. **Book a field** with name + phone + code. |
| Player | Web + mobile | Account (phone + code). Book, cancel, games (create / join), friends, chats, profile. |
| Owner | Owner web (+ the mobile app later, in owner mode) | Their place: schedule, requests, create / edit / cancel bookings, prices, hours, staff. |
| Staff | Same as owner | Schedule and bookings only. Created by the owner or the admin. |
| Admin (you) | Admin panel | Places and fields, **create owner accounts**, users, moderation (reports, chats), tournaments later |

One phone number = one **user**. A user can be a player and also an owner/staff of a place. In the mobile app, an owner can switch between "Player" and "My place".

---

## 3. Architecture

```
  Player web (/)        Owner web (/owner)       Admin (/admin)        Mobile app (later)
  Vue 3 + Inertia       Vue 3 + Inertia          Filament 3            React Native (Expo)
        │                     │                       │                      │  REST /api/v1 (Sanctum tokens)
        └──────────────┬──────┴───────────────────────┴──────────────────────┘
                       ▼
         ┌──────────────────────────────────────────────────────────┐
         │ Laravel 12 (one app, PHP 8.3)                           │
         │ Domain modules: Auth · Venues · Booking · Social        │
         │ (friends, blocks) · Games · Chat · Notifications ·      │
         │ Admin · (Tournaments later)                             │
         │ Actions/Services = all business rules (shared by web,   │
         │ API and admin)                                          │
         └──────┬───────────────┬───────────────┬──────────────────┘
                │               │               │
          PostgreSQL       Redis (cache,     Laravel Reverb (WebSockets: chat messages, typing,
          (data)           queues/Horizon,   live schedule in the owner app, in-app notifications)
                           rate limits)
                                                   Telegram: Gateway API (login codes)
                                                             Bot API (messages + webhook)
                                                   Expo Push (mobile, later)
```

**URLs:**
- Development: `http://localhost:8000/` (player), `/owner`, `/admin`
- Production: `bookball.am`, `bookball.am/owner` (or `owner.bookball.am`, a config switch), `bookball.am/admin`

---

## 4. Stack

| Part | Choice |
|---|---|
| Backend | PHP 8.3, **Laravel 12**, Pest tests, Pint, Larastan |
| Database | **PostgreSQL** (local: Postgres.app 15, production: 16). Uses `btree_gist` for no double booking. |
| Cache / queues | **Redis** + **Horizon** |
| Real time | **Laravel Reverb** (WebSocket server) + **Laravel Echo** on the client (web and React Native both work with Echo/pusher-js) |
| Web frontend | **Vue 3 + Inertia 2 + TypeScript + Tailwind 4** (from the official Laravel Vue starter kit), with the prototype design |
| Admin | **Filament 3**, accessible only to `is_admin` users |
| i18n | `lang/{hy,ru,en}.json` shared with Vue via `laravel-vue-i18n`; DB texts in JSON columns |
| API | `/api/v1` JSON with **Sanctum** tokens for mobile (built alongside the web, used by mobile later) |
| Mobile (later) | **React Native + Expo** (EAS Build for Play Market and App Store), Expo Router, TanStack Query, Echo for chat, **Expo Notifications** for push |
| Hosting | One VPS (Hetzner) with Laravel Forge: nginx, PHP-FPM, Postgres, Redis, Reverb, Horizon, plus daily backups and Sentry |

**Local tools (already on your Mac or installed by me):**
- PHP 8.3 (Homebrew), Composer, Node 22, Laravel installer
- **Postgres.app**
- **Redis** (Homebrew)

Herd is not needed: we run `composer run dev`, which starts the server, Vite, queue, Reverb and logs together.

---

## 5. Database

Conventions:
- bigint ids; `timestamps` everywhere
- money is integer AMD
- times are `timestamptz` (UTC), shown in **Asia/Yerevan**

### 5.1 Users, login, admin

```
users
  id, phone (unique, +374…), name, username (unique, for finding friends) null,
  locale (hy|ru|en), avatar_path null, level (beginner|mid|pro) null, position null,
  district null, bio null,
  telegram_chat_id (unique) null, telegram_linked_at null,
  phone_verified_at, registered_at null   -- null = created by a guest web booking (no profile yet)
  is_admin bool, status (active|blocked), blocked_until null, no_show_count int,
  last_seen_at, timestamps, soft deletes

otp_codes               id, phone, code_hash, gateway_request_id, purpose (login|booking),
                        attempts, expires_at, consumed_at, ip, created_at
telegram_link_tokens    id, user_id, token unique, expires_at, used_at
personal_access_tokens  (Sanctum, mobile)
devices                 id, user_id, expo_push_token, platform, last_used_at   -- mobile later
```

### 5.2 Places and fields

```
venues          id, slug, name, district, address (json hy/ru/en), description (json), lat, lng, phone,
                amenities jsonb, rules jsonb (free_cancel_hours, min_notice_min, horizon_days,
                request_expire_min, auto_accept_regulars), status (draft|active|paused), timestamps
fields          id, venue_id, size (small|big), format, surface (turf|grass), indoor, max_players,
                durations jsonb, default_duration, active
opening_hours   id, venue_id, weekday, opens_at, closes_at
price_rules     id, field_id, weekdays int[], from_time, to_time, price_per_hour, priority
closures        id, field_id null, starts_at, ends_at, reason, created_by
venue_users     id, venue_id, user_id, role (owner|staff), created_by   -- owners are created by the admin
```

### 5.3 Bookings

```
bookings
  id, code unique ("BB-4821"), venue_id, field_id,
  starts_at, ends_at, time_range tstzrange GENERATED STORED,
  status (pending|accepted|declined|cancelled|expired|completed|no_show),
  source (web_guest|player|owner|recurring|game|tournament),
  user_id null, contact_name, contact_phone,
  game_id null                 -- booked for a game
  series_id null, price int, note_player, note_owner,
  decline_reason, cancel_reason, cancelled_by, decided_by, decided_at, timestamps

  EXCLUDE USING gist (field_id WITH =, time_range WITH &&) WHERE (status IN ('pending','accepted'))
  -- the database itself makes double booking impossible

booking_series   weekly bookings for regular teams (owner)
booking_events   history: created / accepted / changed (old→new jsonb) / cancelled / no_show …
```

### 5.4 Friends and safety

```
friendships     id, user_id, friend_id, status (pending|accepted), requested_by, accepted_at, timestamps
                unique(least(user_id,friend_id), greatest(user_id,friend_id))
user_blocks     id, blocker_id, blocked_id, created_at          -- blocked users can't message or invite you
reports         id, reporter_id, target_type (user|message|game), target_id, reason, status, handled_by
```

### 5.5 Games

```
games
  id, creator_id, players_per_side (3..11), total_players (= 2 × per side),
  min_to_open (= ceil(total × 0.3), creator included),
  level null, district null, venue_id null (preferred place, optional),
  when_from null, when_to null (preferred time window, optional), note null,
  status (forming|open|full|booked|played|cancelled|archived),
  booking_id null, opened_at, full_at, archived_at, timestamps

game_players
  id, game_id, user_id, status (invited|accepted|declined|joined|left|removed),
  invited_by null, side (A|B) null, responded_at, timestamps
  unique(game_id, user_id)
```

**Game status rules:**
```
forming  ── accepted players (creator + friends) ≥ min_to_open ──► open (public, chat starts)
open     ── players = total ──► full                (if someone leaves: back to open)
full/open── a booking for this game is accepted ──► booked
booked   ── booking ended ──► played
any      ── creator cancels / forming for 48 h without enough accepts ──► cancelled
open/full/booked/played ── chat quiet 7 days and no upcoming booking ──► archived (chat read-only; deleted after 30 days)
```
- `min_to_open` examples:

  | Game | Players | Needed to open |
  |---|---|---|
  | 3×3 | 6 | 2 |
  | 5×5 | 10 | 3 |
  | 7×7 | 14 | 5 |
  | 10×10 | 20 | 6 |
  | 11×11 | 22 | 7 |

- Only **friends** can be invited in the forming stage. Max open/forming games per creator: 3.
- Players join an open game directly. The creator can remove players; blocked users can't join.

### 5.6 Chat

```
conversations
  id, type (direct|game), game_id null (unique), status (active|archived),
  last_message_at, archived_at, delete_after null, timestamps

conversation_participants
  id, conversation_id, user_id, role (member|admin), joined_at, left_at null,
  last_read_message_id null, muted_until null
  unique(conversation_id, user_id)

messages
  id, conversation_id, user_id null (null = system message), type (text|system|booking),
  body text, meta jsonb (e.g. booking id), reply_to_id null, edited_at, deleted_at, created_at
  index (conversation_id, id)
```
- **Direct chat:** created on the first message between two users. Allowed if they are friends, or if they play in the same game. Blocked users can't write.
- **Game chat:** created when the game becomes **open**. Participants are the accepted/joined players, and leaving the game removes you from the chat.
- **System messages:** "Davit joined", "Game is full 10/10", "Field booked: Zangak 5×5 Thu 21:00", "Chat will close in 2 days (no activity)".

### 5.7 Notifications

```
notifications       (Laravel database notifications: the in-app bell list)
notification_log    id, user_id, channel (telegram|push), type, payload, status, error, sent_at
notification_prefs  user_id, type, telegram bool, push bool
```

### 5.8 Later
`tournaments`, `tournament_teams`, `tournament_matches` (as in plan v2 §5.7), `reviews`, `favorites`.

---

## 6. Business rules

| Rule | Value (configurable) |
|---|---|
| Booking horizon | 14 days |
| Minimum notice | 60 min |
| Start times | every 30 min; durations per field (5×5: 60/90/120; 11×11: 90/120) |
| Open requests per phone | max 2 at the same time |
| Owner answer | reminder after 15 min; expires after 120 min or 30 min before the start |
| Free cancellation | until 12 h before |
| No-show | 2 in 60 days → can't book for 30 days |
| Game opens at | ceil(30% × total players), creator included |
| Forming game timeout | 48 h, then cancelled |
| Open/forming games per creator | 3 |
| Chat archive | 7 days without messages and no upcoming booking → read-only; deleted 30 days later |
| Messages | max 20 per minute per user; text up to 2,000 characters; links allowed; no files in the MVP |
| Friend requests | max 30 per day |

---

## 7. Real time (Reverb)

**Channels (private, authorised in `routes/channels.php`):**

| Channel | Who | Events |
|---|---|---|
| `conversation.{id}` | its participants | `MessageSent`, `MessageEdited`, `MessageDeleted`, `Typing` (whisper), `ParticipantJoined/Left`, `ConversationArchived` |
| `user.{id}` | the user | `NotificationCreated` (bell), `FriendRequest`, `GameInvite`, unread counters |
| `venue.{id}.schedule` | owner/staff of the venue | `BookingCreated/Updated` (the schedule updates live) |
| `presence-conversation.{id}` (optional) | participants | who is online in the chat |

- Messages are saved first (DB), then broadcast (`ShouldBroadcast` on the queue).
- The client keeps the last message id; after a reconnect it loads the missing messages over HTTP (`GET /conversations/{id}/messages?after=`).
- **Read receipts:** `last_read_message_id` per participant gives the unread counts.

---

## 8. Notifications

**Login (free, decided 2026-10-22):** "Log in with Telegram" through our bot, where the user shares their contact and Telegram confirms the phone. Email code as the backup. The Telegram Gateway (~$0.01/code) stays optional and switched off.

**Telegram bot (@BookballBot):** the user connects it once (deep link `t.me/BookballBot?start=<token>`).

| To | Event | Telegram | In-app | Push (mobile, later) |
|---|---|---|---|---|
| Player | booking sent / accepted / declined / changed / cancelled / reminder 3 h | ✅ | ✅ | ✅ |
| Owner | new request (with **Accept / Decline** buttons), player cancelled, daily summary 09:00 | ✅ | ✅ | ✅ |
| Player | friend request / accepted | — | ✅ | ✅ |
| Player | game invite, game opened, game full, game booked, game cancelled | ✅ | ✅ | ✅ |
| Player | new chat message | only if offline > 10 min, at most 1 per chat per 30 min | ✅ (unread badge) | ✅ (grouped) |

All sending is queued (Horizon), and limited per chat (Telegram: 1 msg/s per chat, 30/s total).

---

## 9. Pages

### 9.1 Player web (`/`)
| Page | Content |
|---|---|
| Home | Find a field (day, time, size, district) → places with free times; open games; shortcuts |
| Place | the two fields, date, free times, duration, price → **Send request** (guest: name + phone + code) |
| Login / Register | phone → Telegram code → (new) name, username → "Connect Telegram" |
| My bookings | upcoming / past, cancel |
| Games | open games (filters: size, level, district, day); my games (forming / open / full / booked) |
| Create game | N vs N, level, district/place (optional), time window (optional), note, **invite friends** |
| Game page | players list (accepted / invited), join / leave, **chat**, "Book for this game" |
| Friends | my friends, requests, search by name / username / phone, "players you played with" |
| Messages | list of chats (direct + game) with unread counts → chat screen (real time) |
| Notifications | bell list |
| Profile | name, username, avatar, level, position, district, language, Telegram status, blocked users |

### 9.2 Owner web (`/owner`), only for users in `venue_users`
| Page | Content |
|---|---|
| Login | phone + code (the account must exist; created by the admin) |
| Today | KPIs, requests waiting (Accept / Decline), next games |
| Schedule | day (2 columns: 5×5, 11×11) / week / list; live updates (Reverb); click to create / edit / cancel / no-show |
| Booking form | field, date, time, duration, contact (find player by phone or free text), price (auto, editable), note, repeat weekly |
| Closures | close a field for a time |
| Settings | hours, prices per field and time band, rules, Telegram connect, staff |

### 9.3 Admin (`/admin`, Filament, only you)
- Places + fields + prices + hours
- **Create owner accounts:**
  1. type the phone and name
  2. choose the venue and role
  3. the owner logs in at `/owner` with that phone and a Telegram code
- Users: search, block, reset no-shows
- Bookings
- Games
- **Reports and chat moderation** (read a reported conversation, delete a message, block a user)
- Notification log
- Tournaments later

### 9.4 Mobile (later, React Native + Expo)
- **Player mode:** tabs Home / Games / Messages / Bookings / Profile
- **Owner mode:** tabs Today / Schedule / Settings, shown only if the user is in `venue_users`, with a switch in the profile
- **Login is required** for everything that changes data
- Push notifications via Expo; the same `/api/v1` and Reverb channels as the web

---

## 10. Backend structure

```
web/app/
  Domain/
    Auth/          SendLoginCode, VerifyLoginCode, RegisterProfile, LinkTelegram
    Venues/        Venue, Field, PriceRule, OpeningHour, Closure · AvailabilityService · PriceCalculator
    Booking/       Booking, BookingSeries, BookingEvent · RequestBooking, AcceptBooking, DeclineBooking,
                   CancelBooking, UpdateBooking, CreateOwnerBooking, CreateSeries, MarkNoShow, ExpirePending
    Social/        Friendship, UserBlock, Report · SendFriendRequest, AcceptFriend, RemoveFriend, BlockUser
    Games/         Game, GamePlayer · CreateGame, InviteFriends, RespondInvite, JoinGame, LeaveGame,
                   RemovePlayer, CancelGame, BookForGame, PlayAgain · GameStateMachine
    Chat/          Conversation, Participant, Message · SendMessage, MarkRead, ArchiveQuietChats
    Notifications/ TelegramGateway, TelegramBot, TelegramChannel, (Expo push later)
    Admin/         CreateOwnerAccount
  Http/Controllers/{Player,Owner,Api/V1}/…  ·  TelegramWebhookController
  Filament/Resources/…
resources/js/  player/ · owner/ · shared/ (UI kit from the prototype, i18n, echo, dates)
```
**Rule:** controllers stay thin; all rules live in Actions, which are tested with Pest.

---

## 11. Development order (web first)

| Step | What | Result |
|---|---|---|
| **1. Setup** (today) | Laravel 12 + Vue/Inertia starter kit, Postgres, Redis, Pest, base layout with the Bookball design, three languages | app runs on localhost |
| **2. Data** | migrations §5.1–5.3, seeders from the prototype (8 places, 16 fields, prices, demo bookings), btree_gist exclusion | data in DB |
| **3. Auth** | phone + code login (fake sender in dev, code in log), register profile, logout; owner access check; admin flag | login works |
| **4. Booking** | AvailabilityService, PriceCalculator, player Home / Place / Request (guest + logged in) / My bookings | players book |
| **5. Owner web** | Today, Schedule (day/week/list), accept / decline / create / edit / cancel, prices, hours | owners work |
| **6. Admin** | Filament: venues, fields, **create owner accounts**, users, bookings | you manage |
| **7. Telegram** | Gateway codes, bot connect, notifications, owner inline buttons, webhook | real messages |
| **8. Social** | friends, requests, search, blocks, reports | friends |
| **9. Games** | create, invite friends, 30% open rule, join / leave, full, cancel, timeouts, "book for game", play again | games |
| **10. Chat** | Reverb, direct + game chats, unread, typing, system messages, archive job | live chat |
| **11. Polish** | notifications bell, rate limits, translations, tests, error pages, PWA | MVP ready |
| **12. API** | `/api/v1` for everything above (Sanctum) | ready for mobile |
| **13. Mobile** | React Native app (player + owner mode), push | stores |
| **14. Deploy + pilot** | VPS, backups, monitoring; 2–3 real places | live |

---

## 12. Open questions (not blocking the start)
1. Can players see **who is in an open game** before joining (names, levels)? Plan: yes, names + level.
2. Should the creator **approve** each joiner, or is joining instant? Plan: instant, and the creator can remove someone.
3. Who can tap **"Book for this game"**? **Decided (2026-10-22): only the creator (organiser).** Only one active booking per game.
4. Does the price split matter in the app, or is it only "pay at the field"? Plan: show the price per player, nothing more.
5. Owner invite: when you create an owner in the admin, should the bot send them the link, or do you send it yourself? Plan: the admin page shows a link to copy, and the bot also sends it if they connected.
