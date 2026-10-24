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
   services** (web, worker, scheduler, reverb), except the two `VITE_*` rows which go on
   **web only**. Railway references avoid retyping, e.g. on worker `APP_KEY=${{web.APP_KEY}}`.

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
- One-off command: `railway run --service web php artisan db:seed`, or the service shell
  in the dashboard. Any command works as `/app/docker/entrypoint.sh php artisan ...`.
- Local dev stays as in `web/INSTALL.md`; the Docker image is for Railway only.

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
