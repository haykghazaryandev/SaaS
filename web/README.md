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
- The texts live in the **`translations` table** (one row per key: `en`, `hy`, `ru`); there are no lang files. The admin edits them in **/admin → System → Translations**, and can add keys there. An empty language shows English.
  - **Seed:** `database/seeders/data/translations.json` is the main translation. `TranslationSeeder` (part of `db:seed`, so of every deploy) adds the keys that are not in the table yet and never changes a row. `php artisan translations:export` writes the table back into that file, so edits can be committed. While the table is empty (fresh install, tests, Docker build) the texts come from the file.
  - **Keys:** `area.screen.element` in snake_case (`player.home.title`, `owner.settings.price_day`); the first part is the group Laravel loads (`App\Support\DatabaseTranslationLoader`, `App\Support\Translations`, rows cached per group and emptied on every edit). A key without a dot (`brand`) is looked up flat. `a.b` and `a.b.c` cannot both exist (the unit test checks).
  - **Shared words** live once under `common.*` (`common.save`, `common.field`, `common.today`, the lists `common.districts.*`, `common.surfaces.*`, `common.weekdays.*`): a text that means exactly the same thing in all three languages on several screens is one key, not one per screen. A word that is translated differently by context (a booking "Cancelled" vs a game "Cancelled") keeps its own key.
  - **Renaming or removing a key:** change the code and the seed file, then add `"old.key": "new.key"` (or `null` to delete) to `database/seeders/data/translation_renames.json`. The seeder applies it to every environment's table on the next deploy, keeping the admin's edited texts under the new key.
  - In PHP: `__('player.home.title')`, `trans_choice('games.open.spotsLeft', $n, ['n' => $n])`
  - In Vue: `$t('player.home.title')` / `$tChoice()` in templates, `trans()` / `transChoice()` from `@/lib/i18n` in scripts (our own, no library). The browser fetches `GET /lang/{locale}.json?v=<langVersion>` (`LangController`) before the app mounts; `langVersion` is a shared Inertia prop that changes with every edit, so the JSON is cached for a year.
  - Framework texts (validation, pagination) and Filament's own still come from the vendor lang files.
  - Placeholders use `:name`
- Write natural Armenian and Russian.

**Frontend:**
- Use the design system in `resources/css/bookball.css` (see "Design system" below). Tailwind utilities are available for layout, with preflight off.
- Components are in `resources/js/components`: `Icon`, `Logo`, `Avatar`, `LangSwitch`, `Flash`, `CodeInput`.
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
  - Texts: the `messages.*` keys; system lines are translated on the client with `trans(message.body, message.meta)`.
- **Routes:**
  - `routes/social.php`: friends, profiles, the notification bell
  - `routes/games.php`
  - `routes/chat.php`
- **Errors:** `App\Domain\Social\SocialException($reason)`; the text is `__('social.error.<reason>')`.
- **Demo data** (`SocialSeeder`): player **+374 93 000 100** has friends, two of their own games (one open with a chat, one forming), an invite and a direct chat.

## Design system
Dark theme with a lime accent, in `resources/css/bookball.css`. The class reference is also at the top of that file. The rules come from `../docs/ux-review-2026-10-22.md` (G1–G8).

**Rules:**
- Colors only through tokens, never raw hex:
  - `--accent`: lime, only for the one main action
  - `--field-small` / `--field-big`: field sizes
  - `--st-pending` / `--st-confirmed` / `--st-manual` / `--st-off`: booking states, each with a `-soft` background variant
- Fonts:
  - `--display`: brand, page titles and big numbers only
  - `--mono`: booking codes and picker times only
- People are always shown with `<Avatar :id :name size="xs|sm|md|lg|xl" />`.
- A disabled button always has its reason in a `.why` line right above it.

**Classes:**
- **Type:**
  - `.kicker`: a sentence-case, muted 13px label above a title
  - `.page-title`
- **Buttons:**
  - `.btn`: lime, the main action
  - variants: `.btn.ghost`, `.btn.outline`, `.btn.danger`, `.btn.link`, `.btn.link.danger`
  - sizes: `.btn.sm`, `.btn.lg`, `.btn.block`, `.btn.icon`
  - `.btn.blue` is deprecated and renders as outline
- **Chips:**
  - `.chips > .chip[aria-pressed]` for filters and quick picks
  - `.pill(.lime|.green|.blue|.warn|.hot)` for small labels
- **Booking status:** `.status-pill.pending|.confirmed|.manual|.off`. Add `.outline` for the expired / outline look.
- **Field size:**
  - `.field-dot.small|.big`: a 9px dot
  - `.c-small` / `.c-big`: text colors
- **Forms:** `.field > label + .input`, with `.hint` and `.err`. Add `aria-invalid="true"` on an `.input` for a red border.
- **Segmented control:** `.seg > button[aria-pressed="true"]` for 2–3 options. Use `.seg.block` for full width.
- **Count badge:**
  - `.badge-dot`: lime count
  - `.badge-dot.warn`: amber count, for waiting
  - `.badge-dot.sm`: a plain 8px dot
  - `.badge-dot.ring`: a ring to separate it from the background
- **Cards:**
  - `.card`, `.card.pad` (padding) and `.card.hover` (lift on hover)
  - `.list-row` with `.lr-main`: a 64px tappable row
- **Empty state:** `.empty-state > .es-ico + h3 + p + .btn`. Use `.empty-state.plain` for no border.
- **⋯ menu:** `.menu-wrap > button.menu-btn[aria-expanded] + .menu[role=menu] > button|a`.
  - `.danger` marks a destructive item
  - `.menu.up` opens upwards; `.menu.left` aligns to the left
  - `hr` adds a separator
- **Inline confirm:** `.confirm(.danger) > p + .confirm-actions > .btn.sm…`
- **Sheet:** `.scrim > .sheet`, with `.sheet-head > h3 + .x`. It is a bottom sheet with a handle on phones.
- **Tabs:**
  - `.tabs > button[aria-selected]`
  - or `.tabs > a[aria-current="page"]`
- **Day strip:**
  - `.days > .day[aria-pressed]`, containing `b` and `small`. The edge fade is automatic.
  - `.day.any` is the "Any day" outline chip.
- **Other:** `.toast` (`.toast.error`), `.bar > i` (progress), `.divider`, `.skeleton`, `.sr-only`

**`PlayerLayout` props:**
- `hide-tabbar`: no bottom tab bar on phones. Used by the place page.
- `fullscreen`: at ≤ 900px there is no header, tab bar or footer. `<main>` is a flex column filling `100dvh`. Used by the chat page. Desktop keeps the header.
- **CSS variables** on `.papp`:
  - `--app-top`: the header height (`64px`, or `0px` in fullscreen on phones)
  - `--app-bottom`: the tab bar height (`0px` without it)

**Other layout behavior:**
- The language switch and the footer are shown to guests only. Logged-in users change the language in Profile.
- Header and tab bar badges come from the shared `counts` prop and from `GET /notifications`. The keys are:
  - `notifications`
  - `messages`: unread chats
  - `friendRequests`
  - `invites`: unanswered game invites
  - `pendingBookings`: booking requests still waiting for the place

  They are kept live by `composables/useSocial.ts`.
