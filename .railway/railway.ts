// Railway Infrastructure as Code for Bookball.
// Declares the whole environment: Postgres, Redis and four services built from web/Dockerfile.
//
// Apply once per environment from a machine logged into Railway (see docs/deploy-railway.md):
//   npm install                       # repo root: installs the "railway" SDK this file imports
//   railway link                      # pick the project + environment (staging or production)
//   railway config plan               # preview
//   railway config apply              # create / update services, variables, healthcheck
// Code comes from GitHub: every service watches the repo (branch per environment, root web/),
// so a push to `staging` deploys the staging environment and a push to `main` production.
//
// preserve() = secret or environment-specific value set once in the Railway dashboard.
// IaC keeps it but never contains it.
import { defineRailway, github, postgres, preserve, project, redis, service } from "railway/iac";

export default defineRailway((ctx) => {
  const isProd = ctx.isEnvironment("production");

  // One GitHub repo, one branch per environment. The Laravel app and its Dockerfile live in web/.
  const repo = github("haykghazaryandev/SaaS", {
    branch: isProd ? "main" : "staging",
    rootDirectory: "web",
  });

  const db = postgres("postgres");
  const cache = redis("redis");

  // Variables shared by every Laravel process.
  const laravelEnv = {
    APP_NAME: "Bookball",
    APP_ENV: isProd ? "production" : "staging",
    APP_DEBUG: "false",
    APP_LOCALE: "hy",
    APP_FALLBACK_LOCALE: "en",
    APP_DISPLAY_TIMEZONE: "Asia/Yerevan",
    LOG_CHANNEL: "stderr",
    LOG_LEVEL: "info", // keep "info": OTP_DRIVER=log writes login codes at info level

    // Laravel reads full connection URLs (config/database.php 'url' keys).
    DB_CONNECTION: "pgsql",
    DB_URL: db.env.DATABASE_URL,
    REDIS_CLIENT: "phpredis",
    REDIS_URL: cache.env.REDIS_URL,
    SESSION_DRIVER: "redis",
    SESSION_SECURE_COOKIE: "true",
    CACHE_STORE: "redis",
    QUEUE_CONNECTION: "redis",
    FILESYSTEM_DISK: "local",
    MAIL_MAILER: "log",

    // Set in the dashboard once per environment, kept by IaC.
    APP_KEY: preserve(), // php artisan key:generate --show
    APP_URL: preserve(), // https://<web public domain>

    // Login codes: "log" (codes in the service logs, fine for staging) or "gateway" (Telegram Gateway).
    OTP_DRIVER: preserve(),
    TELEGRAM_GATEWAY_TOKEN: preserve(),
    TELEGRAM_BOT_TOKEN: preserve(),
    TELEGRAM_BOT_USERNAME: preserve(),
    TELEGRAM_WEBHOOK_SECRET: preserve(),
    ADMIN_PHONE: preserve(), // used by db:seed only

    // Real time (chat, live schedule): Reverb. The web service proxies /app and /apps to the
    // reverb service (docker/Caddyfile), so REVERB_HOST is the WEB domain, not a reverb domain.
    BROADCAST_CONNECTION: "reverb",
    REVERB_APP_ID: preserve(),
    REVERB_APP_KEY: preserve(),
    REVERB_APP_SECRET: preserve(),
    REVERB_HOST: preserve(), // <web public domain>, no scheme
    REVERB_PORT: "443",
    REVERB_SCHEME: "https",
  };

  // Vite bakes these into the JS bundle during the Docker build (ARG in web/Dockerfile).
  const viteEnv = {
    VITE_APP_NAME: "Bookball",
    VITE_REVERB_APP_KEY: preserve(), // same value as REVERB_APP_KEY
    VITE_REVERB_HOST: preserve(), // same value as REVERB_HOST (the web domain)
    VITE_REVERB_PORT: "443",
    VITE_REVERB_SCHEME: "https",
  };

  // Long-running processes must come back even after a clean exit (e.g. queue:restart).
  const alwaysRestart = { restartPolicyType: "ALWAYS" as const };

  // HTTP app. Dockerfile CMD = "web" (FrankenPHP on $PORT). Migrations run before traffic switches.
  // In the dashboard enable Settings > Source > "Wait for CI" on each service so Railway deploys
  // only after the GitHub Actions tests pass.
  const web = service("web", {
    source: repo,
    preDeploy: "php artisan migrate --force",
    healthcheck: "/up",
    healthcheckTimeout: 300,
    env: {
      ...laravelEnv,
      ...viteEnv,
      // Where Caddy forwards WebSocket traffic: the reverb service on the private network.
      REVERB_UPSTREAM: "reverb.railway.internal:8080",
      // "Continue with Google" (empty = button hidden). Google Console redirect URI: https://<web domain>/login/google/callback
      GOOGLE_CLIENT_ID: preserve(),
      GOOGLE_CLIENT_SECRET: preserve(),
    },
  });

  // Same image, different process. No healthcheck, no migrations.
  const worker = service("worker", {
    source: repo,
    start: "/app/docker/entrypoint.sh worker",
    env: laravelEnv,
    deploy: alwaysRestart,
  });

  const scheduler = service("scheduler", {
    source: repo,
    start: "/app/docker/entrypoint.sh scheduler",
    env: laravelEnv,
    deploy: alwaysRestart,
  });

  // WebSocket server, private-network only (reached through the web service's Caddy proxy).
  const reverb = service("reverb", {
    source: repo,
    start: "/app/docker/entrypoint.sh reverb",
    env: { ...laravelEnv, PORT: "8080" }, // no public domain, so Railway does not assign PORT
    deploy: alwaysRestart,
  });

  return project("bookball", {
    resources: [db, cache, web, worker, scheduler, reverb],
  });
});
