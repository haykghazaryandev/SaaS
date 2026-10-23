<?php

namespace App\Http\Controllers\Telegram;

use App\Domain\Notifications\TelegramBot;
use App\Domain\Notifications\TelegramLinker;
use App\Domain\Notifications\TelegramUpdateHandler;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Throwable;

class TelegramController extends Controller
{
    /** "Connect Telegram": a fresh link t.me/<bot>?start=<token> (valid 30 min). JSON for fetch(), a redirect otherwise. */
    public function link(Request $request, TelegramLinker $linker, TelegramBot $bot)
    {
        $url = $bot->startLink($linker->createToken($request->user())->token);
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['url' => $url, 'linked' => $request->user()->hasTelegram()]);
        }

        return Inertia::location($url);
    }

    /** Polled by TelegramConnect.vue while waiting for the user to press Start in the bot. */
    public function status(Request $request)
    {
        return response()->json(['linked' => $request->user()->hasTelegram()]);
    }

    /** Bot API webhook: POST /telegram/webhook/{secret} with the X-Telegram-Bot-Api-Secret-Token header. */
    public function webhook(Request $request, string $secret, TelegramUpdateHandler $handler)
    {
        $expected = (string) config('services.telegram.webhook_secret');
        if ($expected === '' || ! hash_equals($expected, $secret)
            || ! hash_equals($expected, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(404);
        }
        try {
            $handler->handle($request->json()->all());
        } catch (Throwable $e) {
            // answer 200 anyway, otherwise Telegram re-sends the same update again and again
            report($e);
            Log::warning('Telegram update failed', ['update_id' => $request->json('update_id')]);
        }

        return response()->json(['ok' => true]);
    }
}
