# Bookball: football field booking, groups and games in Yerevan

*Full technical plan, version 2 (2026-10-19). The old short plan is in `plan-v1-old.md`.*
*Clickable prototypes:*
- *player app: `~/Desktop/sports-booking`*
- *owner app: `~/Desktop/sports-booking-owner`*

---

## 0. Decisions so far

| Topic | Decision |
|---|---|
| Sport | **Football only** |
| Fields | Every place has **two fields**: small **5×5** and big **11×11** |
| Apps | **Two web apps:** the **player app** and the **owner app**. No mobile app for now (React Native later, same API). |
| Backend | **Laravel**, PostgreSQL, Redis |
| Login | Phone number + **one-time code by Telegram**. No SMS, no passwords. |
| Notifications | **Telegram only** (bot messages) |
| Booking | The player sends a **request**, and the owner **accepts or declines**. The owner can also create and edit bookings. |
| Groups | Players create **groups** (their regular team or friends) and **invite** players |
| Games | A player or group creates a game, e.g. **5×5: "we are 5, need 5 more"**, and others join as single players or as a whole group |
| Tournaments | Amateur 5×5 / 11×11, **created only by us (admin)**, shown in the player app. Owners don't manage tournaments. Built **after** the MVP. |
| Payments | **Pay at the field** in the MVP. No online payments yet. |
| Languages | Armenian, Russian, English |
| 3D | None |

---

## 1. How it works (simple story)

1. **Aram signs up.** He enters his phone and gets a 4-digit code in Telegram, types it, and has an account. He taps "Connect Telegram" once so the bot can send him messages.
2. **Aram creates a group** "Arabkir Friday" and invites 4 friends with a link. They open the link, log in with their phone, and join.
3. **Aram books a field.** He picks Zangak, the small field 5×5, Friday 21:00–22:00. A **booking request** goes to the field.
4. The **owner sees the request** in the owner app and taps **Accept**. Aram gets a Telegram message: "✅ Confirmed".
5. **Aram creates a game** on that booking: "5×5, we are 5 (Arabkir Friday), need 5 more, intermediate."
   - Other players see it in **Open games** and join one by one,
   - **or** another group (e.g. "Nork Wolves") joins as the **opponent team**, all 5 at once.
6. Everyone in the game gets Telegram messages: who joined, reminders 3 h before, changes.
7. The **owner** sees all bookings on a day/week schedule. They can add phone-call bookings, edit times, cancel, mark "no-show".

---

## 2. Users and roles

| Role | App | Can do |
|---|---|---|
| **Guest** | Player app | see places, fields, free hours, open games, tournaments |
| **Player** | Player app | book (request), cancel, create and join games, create groups, invite, join groups |
| **Group admin** | Player app | edit group, invite or remove members, make others admin, join games as a group |
| **Field owner** | Owner app | everything for their place: schedule, accept/decline, create/edit/cancel bookings, prices, hours, staff |
| **Field staff** | Owner app | schedule + bookings only (no prices, no staff management) |
| **Super admin (us)** | Admin panel | places, owners, users, moderation, tournaments, support |

One person = one **user** (one phone). The same user can be a player and also an owner/staff of a place.

---

## 3. Architecture

```
            ┌───────────────────────┐   ┌───────────────────────┐   ┌──────────────────┐
            │  Player web app       │   │  Owner web app        │   │  Admin panel     │
            │  bookball.am             │   │  owner.bookball.am       │   │  admin.bookball.am  │
            │  Vue 3 + Inertia      │   │  Vue 3 + Inertia      │   │  Filament        │
            └──────────┬────────────┘   └──────────┬────────────┘   └────────┬─────────┘
                       │                           │                         │
                       └────────────┬──────────────┴─────────────────────────┘
                                    │  one Laravel application (monolith)
                       ┌────────────▼─────────────────────────────────────────┐
                       │  Laravel 12 (PHP 8.3)                                │
                       │  - Domain modules: Auth, Venues, Booking, Groups,    │
                       │    Games, Notifications, Tournaments (later)         │
                       │  - REST API /api/v1 (Sanctum) ready for mobile later │
                       │  - Queues (Horizon), Scheduler, Events               │
                       └───────┬─────────────┬───────────────┬────────────────┘
                               │             │               │
                        PostgreSQL 16     Redis 7     Telegram (Gateway API for codes,
                        (main data)   (cache, queue,   Bot API for messages + webhook)
                                       locks, rate
                                       limits)
```

**Why one Laravel app with three frontends:**
- one database, one set of models and rules, one deploy
- the player and owner apps use **Inertia + Vue**: fast to build for a PHP developer, with no separate API needed for the web
- the admin uses **Filament**, which gives ready CRUD for places, users and tournaments in days
- all business logic lives in **Actions/Services**, not controllers, so the same logic serves the Inertia web and the `/api/v1` (for the future React Native app)

**Domains:**
- `bookball.am` (player)
- `owner.bookball.am` (owner)
- `admin.bookball.am` (admin)

Laravel `Route::domain()` separates them. Locally: `bookball.test`, `owner.bookball.test`, `admin.bookball.test` with Laravel Herd.

---

## 4. Tech stack

| Layer | Choice | Notes |
|---|---|---|
| Language / framework | PHP 8.3, **Laravel 12** | |
| Database | **PostgreSQL 16** | Needed for the no-double-booking constraint (§6.3) |
| Cache / queue / locks | **Redis 7** + **Laravel Horizon** | All Telegram sending runs in queues |
| Frontend | **Vue 3 + Inertia 2 + TypeScript + Vite** | |
| UI | **Tailwind CSS 4** | Use the prototype's design (dark, lime accent) as the design system |
| i18n | `laravel-vue-i18n` (shares Laravel `lang/*.json` with Vue), `spatie/laravel-translatable` for DB texts | hy, ru, en |
| Auth | Session auth for web (Inertia), **Sanctum** tokens for `/api/v1` | Login = phone + Telegram code |
| Admin | **Filament 3** | |
| Dates | `Carbon`, all times stored as **UTC `timestamptz`**, shown in **Asia/Yerevan** | |
| Tests | **Pest** (feature + unit), Laravel Dusk or Playwright later | |
| Code quality | Laravel Pint, Larastan (level 6+) | |
| Errors / logs | Sentry (free tier), Laravel logs | |
| Local dev | **Laravel Herd** (PHP, nginx) + **DBngin** or Docker for Postgres/Redis | Or Laravel Sail if you prefer Docker for everything |
| Hosting | One VPS (Hetzner CX22/CX32, ~€5–10/month) with **Laravel Forge** or Ploi; daily DB backups to S3-compatible storage | Enough for the first thousands of users |

---

## 5. Database plan

Conventions:
- `id` bigint primary keys
- `created_at` / `updated_at` on all tables
- soft deletes where noted
- money as **integer AMD** (no decimals)
- times as `timestamptz` (UTC)

### 5.1 Users and login

```
users
  id, phone (unique, E.164 e.g. +37491234567), name, locale (hy|ru|en),
  avatar_path null, level (beginner|mid|pro) null, preferred_position null,
  telegram_chat_id null (unique), telegram_username null, telegram_linked_at null,
  phone_verified_at, is_admin bool default false,
  no_show_count int default 0, blocked_until null, last_seen_at, timestamps, soft deletes

otp_codes                          -- one-time login codes sent by Telegram Gateway
  id, phone, code_hash, request_id (Telegram Gateway id), attempts int,
  expires_at, consumed_at null, ip, user_agent, created_at

telegram_link_tokens               -- "Connect Telegram" deep link: t.me/BookballBot?start=<token>
  id, user_id, token (unique), expires_at, used_at null

personal_access_tokens             -- Sanctum (for the mobile app later)
```

### 5.2 Places, fields, prices

```
venues                             -- a "place"
  id, slug (unique), name, district, address (json: hy/ru/en), description (json),
  lat, lng, phone, rating_avg, rating_count,
  amenities (jsonb: ["parking","showers",...]),
  rules (jsonb: free_cancel_hours, min_notice_minutes, booking_horizon_days,
         request_expire_minutes, auto_accept_regulars bool),
  status (draft|active|paused), timestamps, soft deletes

fields                             -- exactly 2 per venue in the MVP (small + big), but the table allows more
  id, venue_id, size (small|big), format ("5×5"|"11×11"), name (json) null,
  surface (turf|grass), indoor bool, max_players (10|22),
  durations (jsonb: [60,90,120]), default_duration int, sort, active bool, timestamps

opening_hours
  id, venue_id, weekday (0–6), opens_at time, closes_at time   -- closes_at 24:00 allowed

price_rules                        -- price per hour by time band
  id, field_id, weekdays (int[]), from_time, to_time, price_per_hour int,
  valid_from date null, valid_to date null, priority int
  -- example: Mon–Fri 08:00–18:00 = 15 000; Mon–Fri 18:00–24:00 and Sat–Sun all day = 20 000

closures                           -- field closed (repairs, holidays, private event)
  id, field_id null (null = whole venue), starts_at, ends_at, reason, created_by

venue_users                        -- owners and staff of a place
  id, venue_id, user_id, role (owner|staff), invited_by, timestamps
  unique(venue_id, user_id)
```

### 5.3 Bookings (the heart of the system)

```
bookings
  id, code (unique, e.g. "BB-4821"),
  venue_id, field_id,
  starts_at timestamptz, ends_at timestamptz,
  time_range tstzrange GENERATED ALWAYS AS (tstzrange(starts_at, ends_at, '[)')) STORED,
  status (pending|accepted|declined|cancelled|expired|completed|no_show),
  source (player|owner|recurring|tournament),
  user_id null            -- the player who booked (null for walk-in / phone call)
  contact_name, contact_phone   -- for owner-made bookings without an account
  group_id null           -- "booked for group X"
  series_id null          -- recurring weekly booking
  price int               -- calculated at booking time, the owner can change it
  note_player null, note_owner null (only owners see this),
  decline_reason null, cancel_reason null, cancelled_by (player|owner|system) null,
  accepted_by null, accepted_at null, decided_at null,
  timestamps

  -- NO DOUBLE BOOKING, guaranteed by the database:
  CREATE EXTENSION btree_gist;
  ALTER TABLE bookings ADD CONSTRAINT bookings_no_overlap
    EXCLUDE USING gist (field_id WITH =, time_range WITH &&)
    WHERE (status IN ('pending','accepted'));

booking_series                     -- "every Friday 21:00 for 8 weeks" (owner creates for regular teams)
  id, field_id, weekday, start_time, duration_min, starts_on, ends_on null,
  contact_name, contact_phone, user_id null, group_id null, price int, created_by, timestamps

booking_events                     -- history shown in the owner app ("accepted by Armen 12:03")
  id, booking_id, user_id null, type (created|accepted|declined|changed|cancelled|no_show|...),
  changes (jsonb: old/new values), created_at
```

**Statuses:**
```
            ┌──────── accepted ──────┬──► completed (after ends_at, by scheduler)
pending ────┤                        ├──► cancelled (player or owner)
            ├──────── declined       └──► no_show (owner marks)
            └──────── expired (owner did not answer in time, by scheduler)
owner-made bookings start as "accepted"
```

### 5.4 Groups

```
groups
  id, name, slug, avatar_path null, description null,
  home_venue_id null, usual_size (small|big) null, level null,
  is_public bool default false     -- public groups can be found and asked to join
  owner_id (user), members_count, timestamps, soft deletes

group_members
  id, group_id, user_id, role (admin|member), joined_at
  unique(group_id, user_id)

group_invites
  id, group_id, invited_by,
  type (link|phone|user),
  token (unique, for links like bookball.am/g/join/<token>),
  phone null, user_id null,
  max_uses null, uses int default 0, expires_at null,
  status (active|accepted|declined|revoked|expired), timestamps

group_join_requests                -- a player asks to join a public group
  id, group_id, user_id, message null, status (pending|accepted|declined), decided_by null, timestamps
```

### 5.5 Games (find players / find an opponent)

A game is always tied to a **booking** (the field must be booked first, at least as a request).

```
games
  id, booking_id (unique), host_user_id,
  format (5x5|11x11), players_per_side (5|11),
  mode (open_players|open_opponent|private)
     -- open_players  : anyone can join single places
     -- open_opponent : looking for a whole group to play against
     -- private       : only invited people (e.g. two known groups)
  level (beginner|mid|pro) null, note null,
  price_per_player int (calculated: booking price / total players, shown only, paid at the field),
  status (open|full|cancelled|played), visibility (public|link), timestamps

game_sides                         -- side A and side B
  id, game_id, side (A|B), group_id null, name null (e.g. "Bibs" / "No bibs"),
  captain_user_id null

game_players
  id, game_id, side (A|B), user_id null, guest_name null  -- a friend without an account
  status (joined|left|removed), joined_at
  unique(game_id, user_id)

game_opponent_requests             -- group B asks to be the opponent (mode = open_opponent)
  id, game_id, group_id, requested_by, message null,
  status (pending|accepted|declined|cancelled), decided_by null, timestamps
```

**Example: "we are 5, need 5 more" (5×5)**
1. Aram has a booking (pending or accepted) for Friday 21:00, small field.
2. He creates a game: side A = group "Arabkir Friday", whose 5 members are added automatically (they can leave).
3. Side B:
   - **"open players":** 5 single places; anyone joins until it is full.
   - **"open opponent":** a group admin from another group sends a request. Aram accepts, and their members fill side B.
4. When 10/10 the game becomes **full** and everybody gets a Telegram message.
5. If the booking is declined or cancelled, the game is cancelled automatically and everyone is notified.

### 5.6 Notifications

```
notification_log                   -- what we sent (for support and avoiding duplicates)
  id, user_id null, chat_id, type, payload (jsonb), locale,
  status (queued|sent|failed), error null, telegram_message_id null, sent_at, created_at

notification_settings              -- per user; owners have more options
  user_id, type, enabled bool
```

### 5.7 Tournaments (phase 3, admin only; tables here so the design is ready)

```
tournaments
  id, slug, name (json), venue_id, size (small|big), format (groups|knockout|league),
  status (draft|open|closed|in_progress|finished), starts_on, ends_on,
  registration_ends_at, max_teams, entry_fee int, prize_text (json), rules (json), timestamps

tournament_teams
  id, tournament_id, group_id null, name, captain_user_id, players_count,
  status (pending|accepted|declined|withdrawn), timestamps

tournament_groups      -- "Group A", "Group B" (round-robin groups, not player groups)
  id, tournament_id, name

tournament_matches
  id, tournament_id, stage (group|round_of_16|quarter|semi|final|league),
  tournament_group_id null, round int, slot int,
  team_a_id null, team_b_id null, starts_at null, field_id null,
  score_a null, score_b null, penalties_a null, penalties_b null,
  winner_team_id null, status (scheduled|played|walkover)
```

Tournament matches also create **bookings** (source = `tournament`) so the owner sees them in the schedule.

### 5.8 Other

```
favorites           -- user ↔ venue
reviews             -- user rates a venue after a completed booking (phase 2)
audit_log           -- admin actions
```

---

## 6. Backend plan (Laravel)

### 6.1 Folder structure (by domain)

```
app/
  Domain/
    Auth/         Actions: SendLoginCode, VerifyLoginCode, LinkTelegram
    Venues/       Models: Venue, Field, OpeningHour, PriceRule, Closure
                  Services: AvailabilityService, PriceCalculator
    Booking/      Models: Booking, BookingSeries, BookingEvent
                  Actions: RequestBooking, AcceptBooking, DeclineBooking, CancelBooking,
                           UpdateBooking, CreateOwnerBooking, CreateSeries, MarkNoShow
                  Enums: BookingStatus, BookingSource
                  Policies: BookingPolicy
    Groups/       Models: Group, GroupMember, GroupInvite, GroupJoinRequest
                  Actions: CreateGroup, InviteToGroup, AcceptInvite, LeaveGroup, ...
    Games/        Models: Game, GameSide, GamePlayer, GameOpponentRequest
                  Actions: CreateGame, JoinGame, LeaveGame, RequestToBeOpponent, AcceptOpponent
    Notifications/ TelegramChannel, TelegramBot (Bot API client), TelegramGateway (codes),
                  Notifications/*.php (one class per message type)
    Tournaments/  (phase 3)
  Http/
    Controllers/Player/*    (Inertia pages, bookball.am)
    Controllers/Owner/*     (Inertia pages, owner.bookball.am)
    Controllers/Api/V1/*    (JSON, later for the mobile app)
    Controllers/TelegramWebhookController.php
  Filament/                 (admin panel resources)
resources/js/
  player/  Pages, Components     (Vue)
  owner/   Pages, Components     (Vue)
  shared/  ui kit, i18n, date/price helpers
lang/ hy.json ru.json en.json
```

The rule: **controllers are thin**. All rules live in Actions, so web, API and Filament call the same code.

### 6.2 Availability and price

- `AvailabilityService::freeSlots(Field, Date, duration)` returns the free start times.
  1. Take the opening hours for the weekday.
  2. Remove closures.
  3. Remove bookings with status pending/accepted.
  4. Remove times before now + `min_notice_minutes`.
  5. Step every **30 minutes**.
- `PriceCalculator::for(Field, startsAt, endsAt)` sums each 30-minute piece by the matching `price_rule` (like the prototype's day and prime-time prices).
- Cache free slots per field and day in Redis for 60 s. Clear the cache on any booking change of that field and day (event listener).

### 6.3 No double booking

Two levels:
1. **Database:** the Postgres `EXCLUDE` constraint (§5.3) makes overlapping pending/accepted bookings on one field **impossible**, even with two clicks at the same millisecond.
2. **App:** `RequestBooking` runs in a transaction, checks the rules, and inserts. If Postgres throws the exclusion violation (SQLSTATE `23P01`), we return the friendly message "This time was just taken, choose another".

### 6.4 Business rules (configurable per venue in `venues.rules`)

| Rule | Default |
|---|---|
| Booking horizon | 14 days ahead |
| Minimum notice | 60 min before start |
| Steps | start times every 30 min; durations from `fields.durations` |
| Pending requests per player | max **2 at the same time** (anti-spam) |
| Owner answer time | reminder to the owner after 15 min. If there is no answer, the request **expires** after 120 min, or 30 min before the start, whichever comes first. The player gets a message. |
| Free cancellation (player) | until **12 h** before. Later is still allowed but marked "late cancel" and visible to the owner. |
| No-show | owner marks it; 2 no-shows in 60 days → the player can't send requests for 30 days (admin can lift it) |
| Auto-accept | optional: players with 3+ completed bookings at this venue are accepted automatically |

### 6.5 Scheduler jobs (`routes/console.php`)

| Job | When | What |
|---|---|---|
| `ExpirePendingBookings` | every minute | pending → expired, notify the player |
| `RemindOwnerPending` | every 5 min | Telegram reminder to the owner about requests older than 15 min |
| `SendGameReminders` | every 10 min | "Game in 3 hours" to all players of a game, and to the booker |
| `CompleteBookings` | every 15 min | accepted and ended → completed |
| `OwnerDailySummary` | 09:00 Yerevan | today's games, pending requests |
| `ExpireInvites`, `CleanupOtp` | daily | |

### 6.6 Main routes

**Player app (`bookball.am`, Inertia):**
```
GET  /                         home: search (date, time, size, district) + results
GET  /places/{slug}            place: two fields, date, free times, booking form
POST /bookings                 send request
GET  /me/bookings              my bookings     POST /me/bookings/{id}/cancel
GET  /games                    open games      GET /games/{id}
POST /games                    create (from my booking)
POST /games/{id}/join | /leave | /opponent-requests
GET  /groups                   my groups       POST /groups
GET  /groups/{slug}            group page (members, games)
POST /groups/{id}/invites      create link / invite by phone
GET  /g/join/{token}           join by link
GET  /login  POST /login/code  POST /login/verify   POST /logout
GET  /me/profile               name, language, Telegram connect
GET  /tournaments ...          (phase 3)
```

**Owner app (`owner.bookball.am`, Inertia):**
```
GET  /                         today: KPIs + requests waiting
GET  /schedule?date=&view=day|week|list
POST /bookings                 create (walk-in / phone)
PATCH /bookings/{id}           edit (field, time, price, contact, notes)
POST /bookings/{id}/accept | /decline | /cancel | /no-show
POST /series                   weekly booking
GET  /settings  PATCH /settings/prices /settings/hours /settings/rules
GET  /staff     POST /staff (invite by phone)
POST /closures
```

**Telegram webhook:** `POST /telegram/webhook/{secret}`

**API for the mobile app later:** `/api/v1/...` with the same Actions (Sanctum tokens).

### 6.7 Security

- Rate limits:
  - login code: 3 per phone per 10 min and 10 per IP per hour
  - verify: 5 tries per code
  - booking requests: 10 per user per hour
- Policies check that an owner/staff acts only on **their venue's** fields (`venue_users`).
- Phone numbers of players are visible to the owner **only for bookings at their venue**.
- The Telegram webhook URL has a secret path and checks the `X-Telegram-Bot-Api-Secret-Token` header.
- Admin panel only for `is_admin` users, with optional IP allow-list.

---

## 7. Login and notifications (Telegram)

### 7.1 Login with a code (Telegram Gateway API)

1. The user enters a phone → `SendLoginCode`.
2. We call Telegram Gateway `sendVerificationMessage` (phone, code length 4). Telegram delivers the code in the "Verification Codes" chat in the user's Telegram app. **Cost about $0.01 per code**, paid in advance on the Gateway account.
3. The user types the code → `VerifyLoginCode` checks it via `checkVerificationStatus` (or our stored hash) → creates or finds the user → logs in (session, 60-day "remember me").
4. A user without Telegram: Gateway returns an error → "This number has no Telegram. Install Telegram to use Bookball." (MVP rule.)

### 7.2 Messages (Telegram Bot API, free)

The Gateway can send **only codes**. For booking messages we need our own **bot**, e.g. `@BookballBot`, and a bot can write to a user only after they press **Start** once:
1. After the first login we show **"Connect Telegram to get booking updates"** → a button opens `https://t.me/BookballBot?start=<one-time token>`.
2. The user presses Start. Telegram calls our webhook with `/start <token>`, and we save `users.telegram_chat_id`.
3. Until connected, we keep showing a banner, and **booking requests require it**, because the owner's answer arrives in Telegram.

**Owners** connect the same bot (owner app → Settings → "Connect Telegram"). Their messages have **inline buttons** "✅ Accept / ❌ Decline", which call our webhook (`callback_query`), so an owner can answer **right from Telegram** without opening the app.

### 7.3 Message list

| To | When | Message (3 languages, user's `locale`) |
|---|---|---|
| Player | request sent | 📨 Request sent: Zangak · 5×5 · Fri 21:00–22:00 |
| Player | accepted | ✅ Confirmed + booking code + map link |
| Player | declined / expired | ❌ + reason + "Find another time" link |
| Player | owner changed time/field/price | ✏️ new details |
| Player | owner cancelled | 🚫 + reason |
| Player | 3 h before | ⏰ Reminder (+ game players) |
| Owner | new request | 📥 Name, phone, field, time, price + **[Accept] [Decline]** buttons |
| Owner | player cancelled | 🔁 time is free again |
| Owner | 09:00 | daily summary |
| Group | invite / joined / left | 👥 |
| Game | someone joined / game full / opponent request / game cancelled | ⚽ |

**Technical:**
- Laravel Notification classes with a custom `TelegramChannel`
- always **queued** (Horizon), with retries and backoff
- Telegram limits: about 30 messages/second overall, 1/second per chat, handled by a Redis rate limiter in the job
- every message is saved in `notification_log`

---

## 8. Player web app (pages)

Mobile-first web (most players will open it on a phone), with "Add to home screen" support (PWA manifest) so it feels like an app before the real mobile app exists.

| Page | Content |
|---|---|
| **Home** | search: day, time, field size (5×5 / 11×11), district → places with free times; open games; groups shortcut |
| **Place** | photos, address/map, the two fields, date strip, free start times list, duration, price → **Send request** |
| **Login** | phone → Telegram code → name (first time) → "Connect Telegram" |
| **My bookings** | upcoming / past, status pills, cancel, "Create a game from this booking" |
| **Open games** | filters: day, size, level; join a single place or request as opponent group |
| **Game page** | sides A/B, players, places left, price per player, share link, chat link (Telegram group optional) |
| **Groups** | my groups, create group |
| **Group page** | members, roles, invite (link / phone), upcoming games and bookings of the group, leave |
| **Profile** | name, language, level, position, Telegram status |
| **Tournaments** | phase 3 |

## 9. Owner web app (pages)

Desktop and tablet first (front desk), but works on a phone.

| Page | Content |
|---|---|
| **Login** | phone + Telegram code; the user must be in `venue_users` |
| **Today** | KPIs (bookings, occupancy, expected revenue), **requests waiting** with Accept/Decline, next games |
| **Schedule** | day view (2 columns: 5×5, 11×11), week view, list view; colours by status; click empty slot → new booking; click booking → details / edit / cancel / no-show / call |
| **New / edit booking** | field, date, time, duration, contact (search existing player by phone or type a name), price (auto, editable), note, repeat weekly |
| **Closures** | close a field for repairs or an event |
| **Settings** | place info (read-only, changed by admin), opening hours, **prices** per field and time band, rules, Telegram connect and notification toggles |
| **Staff** | invite staff by phone, remove |
| **Reports** (phase 2) | revenue and occupancy by week/month, top customers, no-shows |

## 10. Admin panel (Filament, for us)

- **Venues & fields:** create a place, its two fields, prices and hours, attach the owner (by phone)
- **Users:** search by phone, block/unblock, reset no-shows, see bookings
- **Bookings:** search and support actions
- **Groups & games:** moderation (rename offensive names, delete spam)
- **Tournaments** (phase 3): create, accept teams, draw, enter scores
- **Notification log, Telegram status, settings**

---

## 11. Frontend details

- **One Vite build with two entry points:** `resources/js/player/app.ts` and `resources/js/owner/app.ts`, plus `shared/`.
- **Shared UI kit:**
  - copy the prototype design: colours, fonts (Unbounded, Manrope, Noto Sans Armenian), chips, pills, sheets, day strip, field drawings (SVG)
  - build it once as Vue components: `Btn`, `Chip`, `Pill`, `Sheet`, `DayStrip`, `TimeSlots`, `FieldCard`, `BookingCard`, `StatusPill`, `PhoneInput`, `CodeInput`
- **Data:** Inertia props for page data; small `fetch`/axios calls for live parts (free slots when the date changes, accept/decline without reload).
- **Live updates in the owner app:** poll `/schedule/changes?since=` every 20 s at first. Later, Laravel **Reverb** (WebSockets) for instant updates.
- **Dates:** `dayjs` with timezone `Asia/Yerevan`; Armenian/Russian names from `Intl.DateTimeFormat`.
- **Forms:** Inertia `useForm`, with server validation messages translated.

---

## 12. Testing plan

- **Unit:** PriceCalculator (day/prime/weekend, crossing 18:00), AvailabilityService (hours, closures, min notice, 24:00 close)
- **Feature:**
  - booking request → accept → messages queued
  - double booking blocked (two parallel requests → one fails with 23P01)
  - expire job
  - cancel rules
  - owner can't touch another venue (policy)
  - game join until full; opponent request accept
  - group invite link
- **Fake Telegram:** `Http::fake()` for Gateway and Bot API, with assertions on sent messages.
- **Seeders:** the 8 demo places and fields from the prototype, demo users, groups and bookings, so the apps look alive on day one.

---

## 13. Development order (starting tomorrow)

**Week 1: foundation**
1. New Laravel 12 project `bookball`, Postgres + Redis via Herd/DBngin, Pint, Larastan, Pest.
2. Domains routing (player / owner / admin), Inertia + Vue + Tailwind set up with two entry points.
3. Migrations for §5.1–5.3 (users, otp, venues, fields, hours, prices, closures, venue_users, bookings + exclusion constraint, booking_events).
4. Seeders from the prototype data.
5. Login with phone + code. Day 1 uses a **fake code sender** (code in the log). Telegram Gateway comes in week 2.

**Week 2: booking**
6. AvailabilityService, PriceCalculator, and tests.
7. Player pages: home search, place page, send request, my bookings, cancel.
8. Owner pages: Today, Schedule (day/week/list), accept/decline, create/edit booking.
9. Telegram bot: webhook, connect flow, notifications for the booking events, owner inline buttons.

**Week 3: groups and games**
10. Groups: create, invite link/phone, join, roles.
11. Games: create from booking, join, sides, opponent requests, full/cancel logic, notifications.
12. Scheduler jobs (expire, reminders, complete, daily summary).

**Week 4: polish and pilot**
13. Filament admin (venues, users, bookings).
14. Weekly series, closures, no-show rules, rate limits.
15. Translations hy/ru/en complete, PWA manifest, error pages.
16. Deploy to the VPS (Forge), Sentry, backups. **Pilot with 2–3 real places.**

**After the MVP:**
- Tournaments (phase 3)
- Reviews
- Reports
- Online payments (Idram / bank) for prime time
- **React Native (Expo) app** on `/api/v1` with push notifications

---

## 14. What to prepare before day 1

- [ ] Register **bookball.am** (name decided 2026-10-20; bookball.com is taken)
- [ ] Create the Telegram bot with @BotFather (name, username, avatar), and keep the token secret
- [ ] Create a Telegram Gateway account (gateway.telegram.org) and add a small balance (e.g. $10 = about 1,000 codes)
- [ ] Install locally: Laravel Herd, DBngin (Postgres 16, Redis), Node 20+
- [ ] Decide the default rules: cancel 12 h? request expiry 2 h? (§6.4)

## 15. Open questions

- Should a player be able to book **without** connecting the bot? (Plan: no, because they would miss the owner's answer.)
- Does the owner pay us from day one, or free for the pilot? (Plan: free pilot for 2–3 months.)
- Can a single player (not a group) post "need opponent team"? (Plan: yes; the host can be alone with friends added as guests.)
- Show the **phone of other players** inside a game? (Plan: no; only names, and the host sees phones of their game's players.)
