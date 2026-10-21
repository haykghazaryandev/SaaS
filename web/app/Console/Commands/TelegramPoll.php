<?php

namespace App\Console\Commands;

use App\Domain\Notifications\TelegramBot;
use App\Domain\Notifications\TelegramUpdateHandler;
use Illuminate\Console\Command;
use Throwable;

/**
 * Development with a real bot but without a public https address: ask Telegram for new updates in a loop
 * (getUpdates) instead of receiving them on the webhook. Removes the webhook first.
 * Production uses the webhook (php artisan telegram:set-webhook).
 */
class TelegramPoll extends Command
{
    protected $signature = 'telegram:poll';

    protected $description = 'Receive bot updates by polling (local development with a real bot token)';

    public function handle(TelegramBot $bot, TelegramUpdateHandler $handler): int
    {
        if (! $bot->configured()) {
            $this->error('Set TELEGRAM_BOT_TOKEN in .env first (from @BotFather).');

            return self::FAILURE;
        }
        $bot->call('deleteWebhook', ['drop_pending_updates' => false]);
        $this->info('Polling @'.config('services.telegram.bot_username').' … (Ctrl+C to stop)');
        $offset = 0;
        while (true) {
            try {
                $res = $bot->call('getUpdates', ['offset' => $offset, 'timeout' => 25, 'allowed_updates' => ['message', 'callback_query']]);
                foreach ($res['result'] ?? [] as $update) {
                    $offset = $update['update_id'] + 1;
                    $this->line('update '.$update['update_id']);
                    try {
                        $handler->handle($update);
                    } catch (Throwable $e) {
                        report($e);
                        $this->error($e->getMessage());
                    }
                }
            } catch (Throwable $e) {
                $this->error($e->getMessage());
                sleep(3);
            }
        }
    }
}
