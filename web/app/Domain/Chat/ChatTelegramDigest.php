<?php

namespace App\Domain\Chat;

use App\Domain\Notifications\TelegramBot;
use App\Models\ConversationParticipant;
use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * A new chat message, one minute later: the bot tells the members who have not read it yet
 * ("💬 Aram: Who brings the ball? (+3 more)", with a button that opens the chat in the Mini App).
 * Skipped for people who read it in that minute (they are looking at the chat), muted chats, and anyone
 * the bot already told about this chat in the last 5 minutes (a busy chat does not send 30 messages).
 */
class ChatTelegramDigest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const DELAY_SECONDS = 60;

    public const QUIET_MINUTES = 5;

    public function __construct(public int $messageId) {}

    public function handle(TelegramBot $bot): void
    {
        $m = Message::with('conversation.game', 'user')->find($this->messageId);
        if (! $m || $m->type !== 'text' || ! $m->conversation || $m->conversation->isArchived()) {
            return;
        }
        $c = $m->conversation;
        $members = ConversationParticipant::with('user')->where('conversation_id', $c->id)->whereNull('left_at')
            ->where('user_id', '!=', $m->user_id)->get();
        foreach ($members as $p) {
            $u = $p->user;
            if (! $u?->telegram_chat_id || ! $u->wantsTelegram('chats') || ($p->muted_until && $p->muted_until->isFuture())
                || ($p->last_read_message_id && $p->last_read_message_id >= $m->id)
                || ($p->notified_at && $p->notified_at->greaterThan(now()->subMinutes(self::QUIET_MINUTES)))) {
                continue;
            }
            $unread = Message::where('conversation_id', $c->id)->where('type', 'text')->where('user_id', '!=', $u->id)
                ->where('id', '>', (int) $p->last_read_message_id)->count();
            $locale = $u->preferredLocale();
            $params = ['name' => e($m->user?->name ?? '—'), 'text' => e(Str::limit($m->body, 140)), 'format' => $c->game?->format() ?? ''];
            $text = __($c->type === 'game' ? 'telegram.chat.game' : 'telegram.chat.direct', $params, $locale)
                .($unread > 1 ? "\n".__('telegram.chat.more', ['n' => $unread - 1], $locale) : '');
            $p->forceFill(['notified_at' => now()])->save();
            try {
                $bot->sendMessage($u->telegram_chat_id, $text, [[[__('telegram.button.open_chat', [], $locale), 'app:/messages/'.$c->id]]]);
            } catch (Throwable $e) {
                Log::warning('chat telegram digest failed', ['user' => $u->id, 'error' => $e->getMessage()]);   // blocked the bot etc.
            }
        }
    }
}
