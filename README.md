# Bookball

Football field booking, finding players and chat for Yerevan: a player web app, an owner web app and an admin panel. A React Native mobile app comes later.

| Folder | What |
|---|---|
| [`web/`](web/) | **The application** (Laravel 12 + Vue 3 + Inertia + PostgreSQL + Redis + Reverb). Start with **[web/INSTALL.md](web/INSTALL.md)**, then [web/README.md](web/README.md) for code conventions. |
| [`docs/plan.md`](docs/plan.md) | Product and technical plan (decisions, database, rules, roadmap). Older versions: `plan-v2.md`, `plan-v1-old.md`. |
| [`prototype/`](prototype/) | Clickable HTML prototypes (player and owner) that the design came from. Open `prototype/player/index.html` in a browser. |

Quick start (details and troubleshooting in web/INSTALL.md):
```bash
cd web
cp .env.example .env            # set DB_USERNAME / DB_PASSWORD, ADMIN_PHONE
createdb bookball && createdb bookball_test
composer install && php artisan key:generate
npm install && npm run build
php artisan migrate --seed
composer run dev                # http://localhost:8000  (login code is shown on screen in development)
```
