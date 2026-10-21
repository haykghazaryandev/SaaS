<?php

namespace App\Domain\Notifications;

/** A Telegram message: HTML text and optional inline buttons (rows of [text, callback_data]). */
final class TelegramMessage
{
    public function __construct(
        public readonly string $type,
        public readonly string $text,
        public readonly array $buttons = [],
    ) {}
}
