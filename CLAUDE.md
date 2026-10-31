# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Bookball: football field booking, finding players and chat for Yerevan. The application is the Laravel app in `web/`
(player web at `/`, owner web at `/owner`, Filament admin at `/admin`). `docs/plan.md` is the product/technical plan,
`docs/deploy-railway.md` the deployment, `prototype/` the HTML prototypes the design came from. The repo root holds only
Railway IaC (`.railway/railway.ts`, root `package.json`) and the GitHub Actions workflow; everything below runs in `web/`.

Detailed conventions live in `web/README.md` (business rules, data, translations, design system) and setup/troubleshooting
in `web/INSTALL.md`. Read those before changing domain logic or UI.

## Commands (run in `web/`)

```bash
composer run dev                      # app :8000 + queue + Reverb :8080 + scheduler + logs + Vite
php artisan migrate --seed            # tables + demo data; migrate:fresh --seed to reset
npm run build                         # Vite build (tests render the manifest, so build before Pest after JS changes)

./vendor/bin/pest                     # all tests, database bookball_test (phpunit.xml), RefreshDatabase per test
./vendor/bin/pest tests/Feature/Booking
./vendor/bin/pest --filter="double booking"
DB_DATABASE=bookball_test_2 ./vendor/bin/pest   # second test database

vendor/bin/pint                       # PHP style
npm run lint                          # ESLint --fix
npm run format                        # Prettier
npx vue-tsc --noEmit                  # TS check (CI runs it and ESLint: both must be clean)
```

PostgreSQL is required (MySQL/SQLite will not work: bookings rely on an exclusion constraint + btree_gist). Redis is
used for cache/session/queue in dev.

**Demo logins** (after `migrate --seed`; on a developer's computer only, `App\Support\TestAccounts`: phone + a code shown on screen;
staging and production log in with Telegram. Without a bot token the login page also shows a "simulate" box):

| Who | Phone | Where |
|---|---|---|
| Admin | `ADMIN_PHONE` from `.env` (default `99000000`) | `/admin` |
| Staff of Zangak Football Park (the first admin owns it) | `91000001` (owners of other places `91000002` … `91000008`) | `/login?to=owner`, then `/owner` |
| Staff of Pingpong.land (ping-pong club: tables, Now, cards, shop; the first admin owns it) | `91000009` | `/owner` (lands on Now) |
| Player Aram Petrosyan (friends, games, chats) | `93000100` | `/` |
| Player Davit Harutyunyan (Aram's friend, same game chat) | `93000101` | |
| More players / a new player | `93000102` … `93000159` / any other Armenian mobile | new numbers go through the welcome page |

Live chat: `93000100` in a normal window and `93000101` in an incognito window, both in Messages → 5×5 game.

## Working rules

- Business logic goes in `app/Domain/*`, with a Pest feature test; controllers and Filament only call it.
- After changing Vue/TS, run `npm run build` before Pest: page tests render the Vite manifest.
- Never recreate `web/lang/*`; app texts go in the seed file (see Translations). Renamed or removed keys go in
  `translation_renames.json` in the same change.
- Configuration is Railway variables; do not add `.env.staging` / `.env.production` files or bake secrets into the image.
- Branch `staging` is also pushed to by a teammate whose deploys need approval in Railway. When merging their work,
  carry their new or changed texts into the seed file and their key changes into the renames file.
- Routes and translation keys share the `a.b.c` shape; check which one a string is before renaming either.

## Sports and club features

- Sports: `App\Enums\Sport` (football: fields 5×5 / 11×11; ping-pong: tables); the admin shows / hides them (`sports` table).
  The home page asks "What do you play?" first, then the sport's page (Book / Tournaments / Games tabs).
- A place's courts of one kind are one group: players book "a table", `Availability::freeInGroup` gives any free one.
- `venues.features`: what a place offers (booking, team, alerts, reviews, games + the club package: wallet, rating,
  trainings, shop, tournaments, money). New features start off (`Venue::DEFAULT_FEATURES`); check with `hasFeature()`.
- Club package: `Domain/Club/*`. Cards are per player per place (QR = `/c/{code}`); a top-up needs the QR scanned,
  money comes off a card only on a game's check (`Checkout`: time + items, card / cash / both). Owners land on Now.
- `ADMIN_PHONE` may list several numbers (commas). On local / staging each admin owns demo places
  (first: Zangak + Pingpong.land, second: Masis + Spin Club, then Davtashen + Smash Club …); a place has one owner, the
  others who ran it become staff. `php artisan club:demo-now` puts test games on the tables.
- Roles: the owner (everything) and staff, added by the owner on Owner → Team. Owner → Settings → Menus
  (`Domain/Venues/Menus`, `venues.menus`) sets which menus each panel shows and staff's levels (Schedule view / edit,
  Shop sell / edit, Tournaments run / create, Money today / all; by default staff get the daily work without Money).
  `venue.menu:<key>` closes a menu turned off for staff; `venue.owner` guards owner-only routes, `venue.owner:<key>,<level>`
  also lets in staff with that level. ZoneSpot fee, Team and Settings are always owner only.

## Architecture

**Business rules live in `app/Domain/*`, never in controllers.** Controllers (`Http/Controllers/{Player,Owner,Social,Telegram}`),
Filament resources and the future mobile API all call the same actions/services:
- `Domain/Venues/Availability` (free/busy times, Yerevan local time as decimal hours) and `PriceCalculator`.
- `Domain/Booking/Actions/*` (`RequestBooking`, `DecideBooking`, `CancelBooking`, `OwnerBooking`, `ExpireAndComplete`).
  Failures throw `BookingException($reason)`; the user text is `__('booking.error.<reason>')`. Every change writes a
  `booking_events` row and fires `Domain/Booking/Events/BookingChanged`, which notifications, games and live updates listen to.
  Postgres SQLSTATE 23P01 (overlap) is mapped to `BookingException('taken')`.
- `Domain/Social/Friends`, `Domain/Games/Games` (N vs N games: forming → open → full → booked → played), `Domain/Chat/Chat`.
  `Domain/Social/SocialException($reason)` → `social.error.<reason>`.
- `Domain/Auth/*`: Telegram login flow, phone/email codes (`OTP_DRIVER=log|gateway`), Mini App login.
- `Domain/Access/*` + `Http/Middleware/AllowedCountriesOnly`: `ALLOWED_COUNTRIES` (e.g. `AM`) lets only those countries in
  (403 page otherwise; off when unset). `Http/Middleware/TrustProxies` makes `$request->ip()` the visitor behind Railway, not
  its edge. See docs/deploy-railway.md "Country restriction".
- `Domain/Notifications/*`: the Telegram bot (`TelegramBot`, `TelegramUpdateHandler` for webhook updates,
  `BookingTexts`). `App\Notifications\Notice($type, $params, $link)` is the in-app notification (database + broadcast,
  important types also to Telegram); texts are `notice.<type>`.

**Data conventions:** DB stores UTC (`timestamptz`); display/accept times in `config('app.display_timezone')` via
`App\Support\LocalTime`. Phones always normalised with `App\Support\Phone::normalize()` to `+374XXXXXXXX`
(`format()`/`mask()` for display). Lazy loading is disabled in dev: always eager-load.

**Frontend:** Inertia 2 + Vue 3 (TS). Pages in `resources/js/pages/{auth,friends,games,messages,owner,player,profile}`,
layouts in `resources/js/layouts` (`PlayerLayout`, `AuthLayout`, `OwnerLayout`), shared helpers in
`composables/useShared.ts`. Styling is the design system in `resources/css/bookball.css` (Tailwind with preflight off;
rules and class list in README "Design system"). Routes are split per area in `routes/{web,owner,social,games,chat,alerts,telegram}.php`;
Ziggy `route()` names are used in Vue, so a route name and a translation key can look alike (`owner.today` is a route).

**Real time:** Laravel Reverb. Channels `private-conversation.{id}` (event `.message`), `private-user.{id}`
(`.chat.updated`), Laravel notifications on `private-App.Models.User.{id}`.

## Translations (hy, ru, en) — database backed

There are no `lang/` files for the app's own texts (do not add them back; the Dockerfile has no `COPY lang`).
- Source of truth: the `translations` table (`App\Models\Translation`), edited in `/admin → System → Translations`.
  `database/seeders/data/translations.json` is the seed; `TranslationSeeder` (part of `db:seed`, so of every deploy) adds
  missing keys and never overwrites a row. While the table is empty (tests, fresh install, Docker build) texts come from
  the seed file. `php artisan translations:export` writes the table back into the file.
- Loader: `App\Support\DatabaseTranslationLoader` wraps Laravel's file loader (framework/Filament texts stay in vendor);
  `App\Support\Translations` caches rows per group and flushes on every edit. `LangController` serves
  `GET /lang/{locale}.json?v=<langVersion>` to the browser; `resources/js/lib/i18n.ts` provides `trans()`/`transChoice()`
  and `$t()`/`$tChoice()` (our own, no library).
- Keys: `area.screen.element`, snake_case, first segment = Laravel group. Shared words live once under `common.*`
  (`common.save`, `common.field`, lists `common.districts.*`, `common.surfaces.*`, `common.weekdays.*`); a word translated
  differently by context keeps its own key. `a.b` and `a.b.c` cannot both exist. Some code loads a whole group as an array
  (`trans('common.districts')`), and many keys are built dynamically (`__('telegram.status.'.$status)`,
  `` $t(`player.size.${size}`) ``), so grep for the prefix before renaming.
- Renaming or removing a key: change code + seed file, then add `"old.key": "new.key"` (or `null`) to
  `database/seeders/data/translation_renames.json`; the seeder migrates existing tables, keeping admin edits.
- `tests/Unit/TranslationsTest.php` enforces: all three languages present, identical `:placeholders`, well-formed sorted
  keys, no prefix conflicts. Write natural Armenian and Russian.

## Deployment

Railway builds `web/Dockerfile` (FrankenPHP) from GitHub: branch `staging` → staging environment, `main` → production,
services `SaaS` (web), `worker`, `scheduler`, `reverb` share the image (`web/docker/entrypoint.sh` picks the process).
On web start `php artisan app:deploy` runs migrations, seeders (only adds what is missing) and registers the Telegram
webhook. Configuration is Railway variables, not `.env` files. GitHub Actions (`.github/workflows/tests.yml`) must pass
before Railway deploys ("Wait for CI"). See `docs/deploy-railway.md`.
