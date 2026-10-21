# Bookball web

Laravel 12 + Inertia 2 + Vue 3 (TypeScript) + Tailwind 3. It contains three parts:
- the **player web** at `/`
- the **owner web** at `/owner`
- the **admin panel** at `/admin` (Filament)

The full product plan is in `../docs/plan.md`. The clickable HTML prototypes are in `../prototype/`.

## Run locally

**Installation, running, demo logins, tests and troubleshooting are in [INSTALL.md](INSTALL.md).**

```bash
composer run dev     # app :8000 + queue + Reverb :8080 + logs + Vite
./vendor/bin/pest    # tests
```

## Conventions

**Business rules:**
- Rules live in **actions/services** under `app/Domain/*`, never in controllers. The web, the future mobile API (`/api/v1`) and the admin all call the same code.
  - `Domain/Venues/Availability` handles free and busy times. It works in Yerevan local time with decimal hours (21.5 = 21:30). `Domain/Venues/PriceCalculator` handles prices.
  - `Domain/Booking/Actions/*`:
    - `RequestBooking`: player request, status pending
    - `DecideBooking`: accept / decline
    - `CancelBooking`
    - `OwnerBooking`: create, weekly, update, no-show
  - Errors are thrown as `Domain/Booking/BookingException($reason)`; the text is `__('booking.error.<reason>')`.
  - Every change writes a `booking_events` row and fires `Domain/Booking/Events/BookingChanged`, which notifications and live updates listen to.

**Data:**
- **Times:** the database stores UTC (`timestamptz`, connection timezone UTC). Show and accept times in `config('app.display_timezone')` (Asia/Yerevan). See `App\Support\LocalTime`.
- **Phones:** always normalised with `App\Support\Phone::normalize()` to `+374XXXXXXXX`. Show them with `Phone::format()`, or `Phone::mask()` for people who shouldn't see the full number.
- **No double booking:** a Postgres exclusion constraint guarantees it (`bookings_no_overlap`). The app maps SQLSTATE 23P01 to `BookingException('taken')`.

**Translations (hy, ru, en):**
- Shared texts are in `lang/{en,hy,ru}.json`. Each area has its own PHP files: `lang/{en,hy,ru}/player.php`, `owner.php`, `admin.php`, `telegram.php`.
  - In PHP: `__('player.home.title')`
  - In Vue: `$t('player.home.title')` or `trans()` from `laravel-vue-i18n`
  - Placeholders use `:name`
- Write natural Armenian and Russian.

**Frontend:**
- Use the design system in `resources/css/bookball.css`, ported from the prototype: dark theme with a lime accent. Classes include `.wrap .card .btn .btn.ghost .btn.sm .chip .pill .input .field .kicker .sheet .scrim .tabs .days .day`. Tailwind utilities are available for layout, with preflight off.
- Components are in `resources/js/components`: `Icon`, `Logo`, `LangSwitch`, `Flash`, `CodeInput`.
- Layouts are in `resources/js/layouts`: `PlayerLayout`, `AuthLayout`, and an owner layout.
- Helpers are in `composables/useShared.ts`: `useShared()`, `hhmm()`, `amd()`.

**Lazy loading** is disabled in development: always eager-load (`with`, `loadMissing`).

## Social, games and chat (added 2026-10-21)
- **Friends** (`App\Domain\Social\Friends`): request / accept / remove / block / unblock / report / relation(), plus `User::friendIds()` and `isFriendOf()`.
- **Games** (`App\Domain\Games\Games`): N vs N without a field.
  - Lifecycle: create (invite friends) → respond to the invite → **open** at `Game::minToOpen()` (≈30%) → join / leave / remove → **full** → book (a booking request linked to the game) → **booked** → **played**. A game can also be cancelled.
  - "Play again" creates a new game with the same people.
  - A game follows its booking through the `BookingChanged` listener.
- **Chat** (`App\Domain\Chat\Chat`):
  - Direct chats are allowed between friends or players of the same game; game chats open with the game.
  - Other functions: send, edit or delete (within 15 min), system messages, unread counts, markRead, archiveQuiet (7 days), deleteExpired (30 days).
  - System messages store a translation key in `body` (`chat.sys.*`) with its parameters in `meta`; the reader translates them.
- **Real time** (Laravel Reverb, `php artisan reverb:start`):
  - `private-conversation.{id}`: event `.message` with `{action, message}`
  - `private-user.{id}`: event `.chat.updated`
  - Laravel notifications on `private-App.Models.User.{id}`
- **In-app notifications:** `App\Notifications\Notice($type, $params, $link)` is saved to the database and broadcast. Important types also go to Telegram. Texts are `notice.<type>`.
- **Messages pages** (`routes/chat.php`, `Player\ChatController`, `pages/messages/*`):
  - `GET /messages` (`messages.index`): chat list, unread badges, closed chats apart; reloads on `.chat.updated`.
  - `GET /messages/{conversation}` (`messages.show`): members only (403). JSON actions: `GET …/history?before=|after=` (40 per page), `POST …` send, `POST …/read {id}`, `PATCH|DELETE /messages/message/{message}` (author, 15 min).
  - `POST /messages/direct/{user}` (`messages.direct`): opens the direct chat or flashes the error.
  - Texts: `lang/*/messages.php`; system lines are translated on the client with `trans(message.body, message.meta)`.
- **Routes:**
  - `routes/social.php`: friends, profiles, the notification bell
  - `routes/games.php`
  - `routes/chat.php`
- **Errors:** `App\Domain\Social\SocialException($reason)`; the text is `__('social.error.<reason>')`.
- **Demo data** (`SocialSeeder`): player **+374 93 000 100** has friends, two of their own games (one open with a chat, one forming), an invite and a direct chat.
