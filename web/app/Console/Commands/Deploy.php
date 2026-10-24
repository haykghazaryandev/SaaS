<?php

namespace App\Console\Commands;

use App\Domain\Notifications\TelegramBot;
use Illuminate\Console\Command;
use Throwable;

/**
 * Everything a fresh deploy needs, safe to run on every start (docker/entrypoint.sh runs it):
 * 1. migrations
 * 2. seeders (they only add what is missing; production gets only the admin), SEED_ON_DEPLOY=false skips
 * 3. Telegram webhook + bot menu, when the bot token is set and APP_URL is https
 * Migrations must succeed; seeding and Telegram only warn, so a Telegram outage never blocks a deploy.
 */
class Deploy extends Command
{
    protected $signature = 'app:deploy';

    protected $description = 'Migrate, seed what is missing, register the Telegram bot (safe to repeat)';

    public function handle(TelegramBot $bot): int
    {
        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        if (config('app.seed_on_deploy')) {
            $this->soft('seeding', fn () => $this->call('db:seed', ['--force' => true]));
        }

        $https = str_starts_with((string) config('app.url'), 'https://');
        if (! $bot->configured() || ! $https) {
            // a local http APP_URL must not take the bot away from `telegram:poll`
            $this->line('Telegram: skipped ('.($https ? 'no TELEGRAM_BOT_TOKEN' : 'APP_URL is not https').').');

            return self::SUCCESS;
        }
        $this->soft('telegram webhook', fn () => $this->call('telegram:set-webhook'));
        $this->soft('telegram menu', fn () => $this->call('telegram:setup'));

        return self::SUCCESS;
    }

    /** Runs a step that may fail without failing the deploy. */
    private function soft(string $step, callable $run): void
    {
        try {
            if ($run() !== self::SUCCESS) {
                $this->warn("{$step}: failed, see above. The app starts anyway.");
            }
        } catch (Throwable $e) {
            $this->warn("{$step}: {$e->getMessage()}. The app starts anyway.");
        }
    }
}
