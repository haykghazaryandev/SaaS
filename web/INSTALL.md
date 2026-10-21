# Bookball: installation and running (development)

This guide takes a new developer from a clean machine to a running app with demo data. The same steps were checked on a clean copy of the project, and all 151 tests passed.

For the code conventions and how the domain works, read [README.md](README.md). For the product plan, read `../docs/plan.md`.

---

## 1. What you need

| Tool | Version | Notes |
|---|---|---|
| PHP | **8.3+** | Extensions: `pdo_pgsql`, `pgsql`, `redis` (phpredis), `intl`, `bcmath`, `zip`, `gd` |
| Composer | 2.x | |
| Node.js | **20+** (22 recommended) | npm comes with it |
| PostgreSQL | **15 or 16** | Must allow `CREATE EXTENSION btree_gist`. A superuser, or the extension pre-created, is enough. |
| Redis | 6+ | Used for sessions, cache, queues and rate limits |

**MySQL and SQLite will not work.** Bookings use a PostgreSQL exclusion constraint so the same field can never be booked twice.

### macOS

```bash
brew install php composer node redis
pecl install redis                  # if `php -m | grep redis` shows nothing
brew services start redis
```
For PostgreSQL, either:
- install **Postgres.app** (https://postgresapp.com), start it and click *Initialize*. Your macOS user becomes a superuser and there is no password.
- or run `brew install postgresql@16 && brew services start postgresql@16`.

### Linux (Ubuntu / Debian)

```bash
sudo apt install php8.3-cli php8.3-pgsql php8.3-redis php8.3-intl php8.3-bcmath php8.3-zip php8.3-gd php8.3-xml php8.3-mbstring php8.3-curl \
                 composer nodejs npm postgresql redis-server
sudo -u postgres createuser --superuser $USER
```

### Any OS: PostgreSQL and Redis in Docker (instead of installing them)

```bash
docker run -d --name bookball-pg -p 5432:5432 -e POSTGRES_PASSWORD=postgres postgres:16
docker run -d --name bookball-redis -p 6379:6379 redis:7
```
Then use `DB_USERNAME=postgres` and `DB_PASSWORD=postgres` in `.env`.

---

## 2. First setup

```bash
git clone <repository-url> bookball && cd bookball/web     # or wherever the Laravel app is in the repo

# databases: one for the app, one for the tests
createdb bookball
createdb bookball_test
# (Docker: docker exec bookball-pg createdb -U postgres bookball && docker exec bookball-pg createdb -U postgres bookball_test)

cp .env.example .env
#   → set DB_USERNAME / DB_PASSWORD for your PostgreSQL
#     (Postgres.app: your macOS username, empty password; Docker: postgres / postgres)
#   → set ADMIN_PHONE to your own phone (+374XXXXXXXX) to be the admin

composer install
php artisan key:generate
npm install
npm run build
php artisan migrate --seed        # tables + demo data (8 places, users, bookings, friends, games, chats)
```

**To start again with fresh demo data:** `php artisan migrate:fresh --seed`. This deletes everything in the `bookball` database.

---

## 3. Run it

**Everything in one terminal:**

```bash
composer run dev
```
This starts five processes together:

| Process | What | Address |
|---|---|---|
| `server` | Laravel (`php artisan serve`) | http://localhost:8000 |
| `queue` | queue worker: Telegram messages, notifications, live events | |
| `reverb` | WebSocket server for live chat and live updates | ws://localhost:8080 |
| `logs` | `php artisan pail` (live log) | |
| `vite` | frontend dev server with hot reload | |

**Running parts by hand:**

```bash
php artisan serve                          # app
php artisan reverb:start --port=8080       # live chat
php artisan queue:work --tries=1 --sleep=0 # restart after changing PHP code: php artisan queue:restart
npm run dev                                # or: npm run build (then no Vite process is needed)
php artisan schedule:work                  # optional: expiring requests, closing quiet chats, cancelling stale games
```

Without Reverb and the queue worker the app still works, but nothing is live: chat falls back to checking every 5 seconds, and the bell checks every 30.

---

## 4. Log in (demo data)

**Login works with a phone number and a 4-digit code.** In development (`OTP_DRIVER=log`) the code is **shown on the login page** and written to `storage/logs/laravel.log`, so no Telegram is needed. Type only the 8 digits after +374.

| Who | Phone | Where |
|---|---|---|
| **Admin** (you) | `ADMIN_PHONE` from `.env` (default `99000000`) | http://localhost:8000/admin |
| **Owner** of Zangak Football Park | `91000001` (other places: `91000002` … `91000008`) | http://localhost:8000/login?to=owner, then `/owner` |
| **Player** Aram Petrosyan (friends, games, chats) | `93000100` | http://localhost:8000 |
| **Player** Davit Harutyunyan (Aram's friend, same game chat) | `93000101` | |
| More players | `93000102` … `93000159` | |
| A new player | any other Armenian mobile number | goes through the welcome page (registration) |

**Testing live chat:** log in as `93000100` in a normal browser window and as `93000101` in a private/incognito window. In both, open **Messages → 5×5 game**.

**"Too many codes" error:** the limits are 3 codes per phone per 10 minutes and 10 per IP per hour. To reset them:
```bash
php artisan tinker --execute="RateLimiter::clear('otp-phone:+37493000100'); RateLimiter::clear('otp-ip:127.0.0.1');"
```

---

## 5. Tests

```bash
./vendor/bin/pest                                 # all tests (database: bookball_test, see phpunit.xml)
./vendor/bin/pest tests/Feature/Booking           # one folder
./vendor/bin/pest --filter="double booking"       # by name
DB_DATABASE=bookball_test_2 ./vendor/bin/pest     # another test database (e.g. two people testing at once)
```
Notes:
- Tests need `npm run build` once, because pages render the Vite manifest.
- They need PostgreSQL and the `bookball_test` database. Tests never call Telegram (HTTP is faked).
- **GitLab CI** runs the same tests on every push and merge request (`.gitlab-ci.yml`, using Postgres 16 and Redis services). The file is in the repository root (the `bookball` folder), and the app is in `web/`.

**Code style:**
```bash
vendor/bin/pint            # PHP (Laravel style)
npm run lint               # ESLint (fixes what it can)
npm run format             # Prettier
npx vue-tsc --noEmit       # TypeScript check (a few known warnings: numbers passed to translations)
```

---

## 6. Project map

```
app/
  Domain/                  business rules (use these from controllers, admin and the future API)
    Auth/                  phone codes: PhoneCodes, CodeSender (log | Telegram Gateway)
    Venues/                Availability (free/busy times), PriceCalculator
    Booking/               RequestBooking, DecideBooking, CancelBooking, OwnerBooking, ExpireAndComplete, BookingChanged event
    Social/                Friends (requests, block, report)
    Games/                 Games (N vs N, 30% rule, join, book a field, play again)
    Chat/                  Chat (direct + game chats, archive), real-time events
    Notifications/         Telegram bot, link tokens, webhook, booking messages
    Admin/                 CreateOwnerAccount
  Models/  Enums/  Notifications/Notice.php (in-app + live + Telegram)
  Http/Controllers/        Player/  Owner/  Social/  Telegram/  AuthController
  Filament/                admin panel (/admin)
routes/                    web.php (player)  owner.php  games.php  social.php  chat.php  telegram.php  channels.php  console.php (scheduler)
resources/js/              Vue 3 + Inertia + TypeScript
  pages/                   auth/  player/  owner/  games/  friends/  profile/  messages/
  layouts/  components/  composables/  lib/echo.ts (Reverb client)
resources/css/bookball.css design system (ported from the HTML prototype)
lang/{en,hy,ru}.json + lang/{en,hy,ru}/*.php   translations (Armenian, Russian, English)
database/migrations, seeders (VenueSeeder, DemoSeeder, SocialSeeder)
tests/Feature/             Pest tests by area
tools/share-proxy.mjs      share the app through ngrok with live chat (see below)
```

---

## 7. Telegram (when you have the real bot)

1. In Telegram, open **@BotFather**, send `/newbot`, name it *Bookball* with the username **@BookballBot** (or similar). Put the token in `.env`:
   ```
   TELEGRAM_BOT_TOKEN=123456:ABC...
   TELEGRAM_BOT_USERNAME=BookballBot
   ```
2. Set `TELEGRAM_WEBHOOK_SECRET` to a random string, e.g. `openssl rand -hex 32`.
3. Telegram can only call **public https** addresses. Set `APP_URL` to one (production, or an ngrok address in development), then run:
   ```bash
   php artisan config:clear && php artisan telegram:set-webhook
   ```
4. For real login codes:
   - create an account at https://gateway.telegram.org and add a balance (about $0.01 per code)
   - set `TELEGRAM_GATEWAY_TOKEN=...` and `OTP_DRIVER=gateway`
5. Keep the queue worker running, because all messages are queued.

Without a bot token every Telegram message is only written to the log, and the admin panel's *Notification log* shows them.

---

## 8. Show it on a phone (ngrok)

```bash
node tools/share-proxy.mjs     # :8090 → app :8000, and /app + /apps (WebSockets) → Reverb :8080
ngrok http 8090                # gives an https address
```
- Open the ngrok address on the phone. The app connects live chat through the same address automatically.
- Anyone with the link can log in as demo users, because the code is shown on screen. Stop ngrok when you're done.

---

## 9. Common problems

| Problem | Fix |
|---|---|
| `Vite manifest not found` | `npm run build` (or keep `npm run dev` running) |
| `could not find driver (pgsql)` | Install or enable the PHP `pdo_pgsql` extension |
| `Class "Redis" not found` | Install the phpredis extension (`pecl install redis`), or set `REDIS_CLIENT=predis` and run `composer require predis/predis` |
| `permission denied to create extension "btree_gist"` | As a superuser: `psql -d bookball -c "create extension btree_gist"` (and the same for `bookball_test`) |
| Times are 4 hours off | The database connection must use UTC (it's set in `config/database.php`); don't change it |
| Chat isn't live | Reverb and the queue worker must be running. After changing PHP code, run `php artisan queue:restart`. |
| A page shows old texts after editing `lang/` | Rebuild the frontend (`npm run build`), or keep `npm run dev` running |
| `Too many codes` on login | See section 4 (reset the limits) |
| A change in `.env` has no effect | `php artisan config:clear` |

---

## 10. Working together (Git)

- `.env`, `vendor/`, `node_modules/` and `public/build/` are not committed (`.gitignore`). Each person keeps their own `.env`.
- After pulling changes:
  ```bash
  composer install && npm install && php artisan migrate && npm run build
  ```
- New database tables go in new migration files. Don't edit migrations that others have already run.
- New UI texts go in all three languages: `lang/en|hy|ru`.
- Run `./vendor/bin/pest` before pushing. GitLab runs it again on every push (see the pipeline in GitLab → Build → Pipelines).
