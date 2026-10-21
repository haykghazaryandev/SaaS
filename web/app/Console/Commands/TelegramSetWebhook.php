<?php

namespace App\Console\Commands;

use App\Domain\Notifications\TelegramBot;
use Illuminate\Console\Command;

/** Points the bot at our webhook: {APP_URL}/telegram/webhook/{TELEGRAM_WEBHOOK_SECRET}. Run once after deploy. */
class TelegramSetWebhook extends Command
{
    protected $signature = 'telegram:set-webhook {--url= : Base URL instead of APP_URL (must be https)}';

    protected $description = 'Register the Telegram bot webhook (uses APP_URL and TELEGRAM_WEBHOOK_SECRET)';

    public function handle(TelegramBot $bot): int
    {
        $secret = config('services.telegram.webhook_secret');
        if (! $bot->configured() || ! $secret) {
            $this->error('Set TELEGRAM_BOT_TOKEN and TELEGRAM_WEBHOOK_SECRET in .env first.');

            return self::FAILURE;
        }
        if (! preg_match('/^[A-Za-z0-9_-]{1,256}$/', $secret)) {
            $this->error('TELEGRAM_WEBHOOK_SECRET may only contain A-Z, a-z, 0-9, _ and -.');

            return self::FAILURE;
        }
        $base = rtrim($this->option('url') ?: config('app.url'), '/');
        if (! str_starts_with($base, 'https://')) {
            $this->warn("Telegram only calls https URLs; {$base} will be rejected.");
        }
        $url = $base.'/telegram/webhook/'.$secret;
        $res = $bot->setWebhook($url, $secret);
        if (! ($res['ok'] ?? false)) {
            $this->error('Telegram said: '.($res['description'] ?? 'error'));

            return self::FAILURE;
        }
        $this->info("Webhook set: {$base}/telegram/webhook/***");

        return self::SUCCESS;
    }
}
