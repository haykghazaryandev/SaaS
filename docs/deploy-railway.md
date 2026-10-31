# Deploying Bookball to Railway from GitHub

- **Infrastructure** (Postgres, Redis, the four services, their GitHub source, variables,
  healthcheck, pre-deploy migration) is declared in `.railway/railway.ts` and applied once per
  environment with `railway config apply` from a developer machine.
- **Code** deploys through Railway's GitHub integration: every service watches the repo
  (`haykghazaryandev/SaaS`, root directory `web/`). A push builds `web/Dockerfile`, runs the
  migrations, waits for `/up`, then switches traffic.
- **Tests** run in GitHub Actions (`.github/workflows/tests.yml`). With "Wait for CI" enabled
  on the Railway services, a deploy starts only after the tests pass and is skipped if they fail.

| GitHub branch | Railway environment |
|---|---|
| `staging` | `staging` |
| `main` | `production` |

Pull requests only run the tests.

Note: Railway's older `railway.json` / `railway.toml` "config as code" is deprecated and
cannot be enabled on new services, which is why the project uses `.railway/railway.ts`.

## Services

All four build the same image from `web/`; `web/docker/entrypoint.sh` picks the process.

| Service | Process | Public domain | Healthcheck |
|---|---|---|---|
| `web` | FrankenPHP on `$PORT`, also proxies `/app/*` and `/apps/*` to reverb | yes: `APP_URL` and `REVERB_HOST` | `/up`, migrations as pre-deploy |
| `worker` | `php artisan queue:work` | no | none, restart ALWAYS |
| `scheduler` | `php artisan schedule:work` | no | none, restart ALWAYS |
| `reverb` | `php artisan reverb:start` on private port 8080 | no (reached via web) | none, restart ALWAYS |

Reverb is served under the web domain on purpose: the front end (`resources/js/lib/echo.ts`)
connects to the page's own host whenever `VITE_REVERB_HOST` differs from it, and one domain
means one TLS certificate and no CORS. The web container's Caddy (`web/docker/Caddyfile`)
forwards WebSocket traffic to `reverb.railway.internal:8080` over Railway's private network.

## 1. One-time setup per environment

Prerequisites on your machine: Railway CLI 5.42+ (`npm i -g @railway/cli`), Node 20+, and the
IaC SDK installed at the **repo root** (not in `web/`, so it never enters the app build):

```bash
npm install          # repo root: installs "railway" from package.json
railway login
```

1. Create the project in the Railway dashboard and **connect your GitHub account** to Railway
   (Account Settings > Integrations > GitHub, install the Railway GitHub App on the `SaaS`
   repo). Railway creates the `production` environment; add `staging` from the environment
   dropdown (empty is fine, IaC creates everything).
2. Link the repo root to the project and the **staging** environment, then apply:
   ```bash
   railway link            # choose workspace > project > environment "staging"
   railway config plan     # shows: + postgres, + redis, + web, + worker, + scheduler, + reverb
   railway config apply
   ```
   This creates the services already connected to the `staging` branch, root `web/`. Railway
   will start a first build immediately; it fails on the missing `APP_KEY` until step 4 is
   done, which is expected.
3. In the dashboard, **staging** environment: `web` > Settings > Networking > **Generate
   Domain**. Only `web` gets a domain. On each of the four services, Settings > Source >
   enable **Wait for CI**.
4. Fill the `preserve()` variables. Every variable in this table goes on **all four
   services** (web, worker, scheduler, reverb), except the rows marked **web only**. Railway references avoid retyping, e.g. on worker `APP_KEY=${{web.APP_KEY}}`.

   | Variable | Value |
   |---|---|
   | `APP_KEY` | output of `php artisan key:generate --show` (new per environment) |
   | `APP_URL` | `https://<web domain>` |
   | `OTP_DRIVER` | `gateway` for real Telegram login codes, `log` to read codes from the web service logs |
   | `TELEGRAM_GATEWAY_TOKEN`, `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_WEBHOOK_SECRET` | as in `web/.env.example`; may be empty with `OTP_DRIVER=log` |
   | `ADMIN_PHONE` | admin phone for `db:seed`, may be empty |
   | `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | any random values, identical on all services |
   | `REVERB_HOST` | `<web domain>` without scheme |
   | `VITE_REVERB_APP_KEY` (web only) | same as `REVERB_APP_KEY` |
   | `VITE_REVERB_HOST` (web only) | same as `REVERB_HOST` |
   | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` (web only) | Google OAuth client ("Web application"); its redirect URI is `https://<web domain>/login/google/callback`. Empty hides the Google button |

   Saving variables triggers a redeploy, so after the last one Railway rebuilds with everything set.
5. Repeat steps 2 to 4 for production: `railway environment production`, then plan, apply,
   domain, Wait for CI, variables. The services there follow the `main` branch.

Postgres needs `CREATE EXTENSION btree_gist` for the booking overlap constraint. Railway's
Postgres image ships contrib and the migration creates the extension itself.

No Railway token is needed in GitHub: Railway pulls the code itself, and the tests workflow
needs no secrets.

## 2. First deploy

Push to `staging` (or use "Deploy Latest Commit" from the service's command palette). GitHub
Actions runs the tests; Railway holds the four deployments in WAITING until the workflow is
green, then builds `web/Dockerfile` for each service. For `web`, the pre-deploy migration
runs, `/up` is polled, then the deployment goes live. If the migration or the healthcheck
fails, Railway keeps the previous `web` deployment live and shows the failure in the
deployments list. Open `APP_URL`, log in, open a chat: the WebSocket must show as connected.

Nothing needs to be run by hand. Every time the `web` container starts,
`docker/entrypoint.sh` runs `php artisan app:deploy`, which is safe to repeat:

1. migrations
2. seeders: they only add what is missing (places by slug, people by phone, bookings and
   games only into empty tables). Production gets only the admin (`ADMIN_PHONE`).
   `SEED_ON_DEPLOY=false` skips seeding.
3. Telegram webhook and bot menu (hy/ru/en), when `TELEGRAM_BOT_TOKEN` is set and `APP_URL`
   is https

Migrations must succeed or the container stops. Seeding and Telegram only print a warning,
so a Telegram outage never blocks a deploy. `DEPLOY_ON_START=false` turns the whole step off.

Telegram ("Log in with Telegram" and booking messages) needs `TELEGRAM_BOT_TOKEN`,
`TELEGRAM_BOT_USERNAME` (`BookballAmBot`) and a random `TELEGRAM_WEBHOOK_SECRET`
(letters, digits, `_` and `-` only) on the services.

Telegram Mini App (Bookball inside Telegram): the bot's menu button and the buttons in its
messages open APP_URL inside Telegram, and the person is logged in by Telegram (signed initData,
`POST /tg/app/login`). Nothing to set up for that. Optional, for t.me links (team invites open
inside Telegram): in BotFather send `/newapp`, choose the bot, give the URL = APP_URL and a short
name (e.g. `app`), then set `TELEGRAM_APP_NAME=app` on the services.

A bot has one webhook. While staging owns it, `php artisan telegram:poll` on a laptop gets
nothing (Telegram refuses getUpdates while a webhook is set). To test the bot locally again,
use a separate test bot token in your `.env`, or remove the webhook
(`curl https://api.telegram.org/bot<TOKEN>/deleteWebhook`) and set it again afterwards.

Profile photos are stored on the container disk (`FILESYSTEM_DISK=local`), so a redeploy
loses them. Add a Railway volume on `storage/app/public` or move them to S3/R2 before
real users upload photos.

## 3. Changing infrastructure later

Edit `.railway/railway.ts`, then `railway config plan` / `railway config apply` against
each environment. Removing a service or variable needs `--confirm-destructive`.
Variable values stay in Railway; IaC never stores secrets. (Railway also offers the
`railwayapp/config` GitHub Action for plan-on-PR / apply-on-merge; not set up here because it
needs one token and one workflow job per environment.)

## 4. Day to day

- Push to `staging` or merge to `main`: tests, then Railway deploys the four services.
- Rollback: dashboard > service > Deployments > Rollback.
- Logs: dashboard, or `railway logs --service web` after `railway link`.
- One-off command: the `web` service shell (dashboard > web > the running deployment >
  Shell, or `railway ssh --service web`). Any command works as
  `/app/docker/entrypoint.sh php artisan ...`. (`railway run` runs on your laptop with the
  Railway variables, and the internal database hostname is not reachable from there.)
- Demo data is seeded on every start (see "First deploy"). To run it by hand anyway:
  `php artisan app:deploy` or `php artisan db:seed --force` in the `web` shell.
- Local dev stays as in `web/INSTALL.md`; the Docker image is for Railway only.

## Country restriction

`ALLOWED_COUNTRIES` on the web service (ISO codes, comma separated: `AM`) lets only visitors from those
countries in; everyone else gets a 403 page in hy / ru / en. Unset or empty = no restriction (production
today). Set on staging since 2026-10-31.

- Country of the visitor's address from DB-IP "IP to Country Lite" (CC BY 4.0, attribution on the 403 page),
  downloaded into the image by `web/Dockerfile` on every build; the build fails if the file does not answer.
  So the database is as fresh as the last deploy.
- The visitor's address: Railway sends `REMOTE_ADDR` = its internal proxy (`100.64.0.0/10`) and
  `X-Forwarded-For: <client>, <edge>`, replacing whatever the client sent (measured 2026-10-31).
  `App\Http\Middleware\TrustProxies` trusts exactly that hop, so `$request->ip()` is the visitor
  (before, it was Railway's edge for everyone, which also made per-IP rate limits shared by all users).
- Never blocked: `/up` and the Telegram webhook (Telegram calls from abroad), an address of unknown country.
  Static files in `public/` (built JS/CSS, icons) and the Reverb WebSocket (`/app/*`) are served by Caddy
  without PHP; private channels still need `/broadcasting/auth`, which is blocked.
- A VPN or proxy outside the allowed countries is blocked, like any foreign address.
- Turning it off: delete the variable (or set it empty) and redeploy.

## Known trade-offs

- Each push triggers four independent Railway builds of the same Dockerfile (one per
  service). Simple, but 4x build minutes. If that becomes a cost, build once in GitHub
  Actions, push to GHCR and switch the services to an `image()` source in `railway.ts`.
- Watch paths are not set, so a docs-only commit also redeploys. Add `web/**` as a watch
  path in each service's settings if that matters.

## Files

- `.github/workflows/tests.yml`: Pest tests on push and pull request; gates Railway via "Wait for CI".
- `.railway/railway.ts` + root `package.json`: infrastructure declaration and its SDK.
- `web/Dockerfile`: Composer deps, Node 22 Vite build, FrankenPHP (PHP 8.3) runtime.
- `web/docker/Caddyfile`: serves `public/`, proxies Reverb paths to the reverb service.
- `web/docker/entrypoint.sh`: picks web / worker / scheduler / reverb, `config:cache`, exports `$PORT`.
- `web/.dockerignore`: keeps `vendor/`, `node_modules/`, `.env` and tests out of the image.
