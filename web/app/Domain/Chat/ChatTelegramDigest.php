<?php

namespace App\Domain\Chat;

use App\Domain\Notifications\PushNotice;
use App\Domain\Notifications\PushNotifications;
use App\Domain\Notifications\TelegramBot;
use App\Models\ConversationParticipant;
use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * A new chat message: the bot tells, right away, the members who don't have Bookball open
 * ("💬 Aram: Who brings the ball? (+3 more)", with a button that opens the chat in the Mini App);
 * the same text goes by Web Push to their devices with "Notifications on this device" on (one per chat on the phone).
 * People with the app open see it live (no Telegram); muted chats and switched-off chat notifications are skipped.
 * A busy chat: within 30 s of a Telegram message about it, new ones are collected into one follow-up 30 s later.
 */
class ChatTelegramDigest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** after a Telegram message about a chat, the next ones are collected for this long */
    public const GROUP_SECONDS = 30;

    /** $followUp: the collected messages of a busy chat (sent about the chat's newest message) */
    public function __construct(public int $messageId, public bool $followUp = false) {}

    public function handle(TelegramBot $bot): void
    {
        $m = Message::with('conversation.game', 'user')->find($this->messageId);
        if ($m && $this->followUp) {
            $m = Message::with('conversation.game', 'user')->where('conversation_id', $m->conversation_id)->where('type', 'text')->latest('id')->first();
        }
        if (! $m || $m->type !== 'text' || ! $m->conversation || $m->conversation->isArchived()) {
            return;
        }
        $c = $m->conversation;
        $collect = false;
        $members = ConversationParticipant::with('user')->where('conversation_id', $c->id)->whereNull('left_at')
            ->where('user_id', '!=', $m->user_id)->get();
        foreach ($members as $p) {
            $u = $p->user;
            if (! $u || ($p->muted_until && $p->muted_until->isFuture())
                || ($p->last_read_message_id && $p->last_read_message_id >= $m->id)
                || $u->isActiveNow()) {   // the app is open: they see it live
                continue;
            }
            $telegram = $u->telegram_chat_id && $u->wantsTelegram('chats');
            $push = PushNotifications::reaches($u, 'chats');   // a query: after the cheap checks
            if (! $telegram && ! $push) {
                continue;
            }
            if ($p->notified_at && $p->notified_at->greaterThan(now()->subSeconds(self::GROUP_SECONDS))) {
                $collect = true;   // told a moment ago: this one goes with the follow-up

                continue;
            }
            // a follow-up only for messages that came after the last Telegram message about this chat
            if ($this->followUp && $p->notified_at && ! Message::where('conversation_id', $c->id)->where('type', 'text')
                ->where('user_id', '!=', $u->id)->where('created_at', '>', $p->notified_at)->exists()) {
                continue;
            }
            $unread = Message::where('conversation_id', $c->id)->where('type', 'text')->where('user_id', '!=', $u->id)
                ->where('id', '>', (int) $p->last_read_message_id)->count();
            $locale = $u->preferredLocale();
            $params = ['name' => e($m->user?->name ?? '—'), 'text' => e(Str::limit($m->body, 140)), 'format' => $c->game?->format() ?? ''];
            $text = __($c->type === 'game' ? 'telegram.chat.game' : 'telegram.chat.direct', $params, $locale)
                .($unread > 1 ? "\n".__('telegram.chat.more', ['n' => $unread - 1], $locale) : '');
            $p->forceFill(['notified_at' => now()])->save();
            if ($telegram) {
                try {
                    $bot->sendMessage($u->telegram_chat_id, $text, [[[__('telegram.button.open_chat', [], $locale), 'app:/messages/'.$c->id]]]);
                } catch (Throwable $e) {
                    Log::warning('chat telegram digest failed', ['user' => $u->id, 'error' => $e->getMessage()]);   // blocked the bot etc.
                }
            }
            if ($push) {
                try {
                    $u->notifyNow(new PushNotice(PushNotifications::message($text, '/messages/'.$c->id, 'chat:'.$c->id, badge: PushNotifications::badgeFor($u))));
                } catch (Throwable $e) {
                    Log::warning('chat push failed', ['user' => $u->id, 'error' => $e->getMessage()]);
                }
            }
        }
        // one follow-up per chat for the collected messages
        if ($collect && Cache::add('chat-digest:'.$c->id, 1, self::GROUP_SECONDS + 5)) {
            self::dispatch($m->id, true)->delay(now()->addSeconds(self::GROUP_SECONDS));
        }
    }
}
