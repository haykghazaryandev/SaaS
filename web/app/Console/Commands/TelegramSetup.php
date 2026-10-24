<?php

namespace App\Console\Commands;

use App\Domain\Notifications\TelegramBot;
use Illuminate\Console\Command;

/**
 * Sets the bot's menu commands and description in Armenian, Russian and English, and the menu button that
 * opens Bookball inside Telegram (Mini App)
 * (Telegram shows the right one by the user's app language). Run once after creating the bot or changing texts.
 */
class TelegramSetup extends Command
{
    protected $signature = 'telegram:setup';

    protected $description = 'Set the Telegram bot menu commands and description (hy/ru/en)';

    public function handle(TelegramBot $bot): int
    {
        if (! $bot->configured()) {
            $this->error('Set TELEGRAM_BOT_TOKEN in .env first.');

            return self::FAILURE;
        }
        foreach (['en' => null, 'hy' => 'hy', 'ru' => 'ru'] as $locale => $code) {
            $commands = [
                ['command' => 'start', 'description' => __('telegram.menu.start', [], $locale)],
            ];
            $bot->call('setMyCommands', array_filter(['commands' => $commands, 'language_code' => $code]));
            $bot->call('setMyDescription', array_filter(['description' => __('telegram.menu.about', [], $locale), 'language_code' => $code]));
            $bot->call('setMyShortDescription', array_filter(['short_description' => __('telegram.menu.short', [], $locale), 'language_code' => $code]));
            $this->line("✓ {$locale}");
        }

        // the menu button next to the message field opens Bookball inside Telegram (Mini App; https only).
        // Telegram has one label for all languages: the brand name.
        $url = rtrim((string) config('app.url'), '/');
        if (str_starts_with($url, 'https://')) {
            $bot->call('setChatMenuButton', ['menu_button' => ['type' => 'web_app', 'text' => '⚽ Bookball', 'web_app' => ['url' => $url.'/']]]);
            $this->line('✓ menu button opens '.$url);
        }

        return self::SUCCESS;
    }
}
