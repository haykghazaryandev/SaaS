<?php

namespace Database\Seeders;

use App\Domain\Chat\Chat;
use App\Domain\Games\Games;
use App\Models\Friendship;
use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Notification;

/**
 * Demo friends, games and chats for the seeded players (+374 93 000 100 … 159).
 * Player 100 ("you" in the demo) has friends, a forming game, an open game with a chat, invites and a direct chat.
 * Safe to run again: skipped when there are already games or friendships.
 */
class SocialSeeder extends Seeder
{
    public function run(): void
    {
        if (Game::exists() || Friendship::exists()) {
            $this->command?->info('Friends, games and chats: already there, skipped.');

            return;
        }
        Notification::fake();   // no Telegram / broadcasts while seeding
        mt_srand(2026);
        $players = User::where('phone', 'like', '+37493000%')->orderBy('phone')->get()->values();
        $me = $players[0];

        // friendships: everyone has 4–8 friends; player 100 has 10 friends and 2 requests waiting
        $pair = function (User $a, User $b, string $status = 'accepted') {
            if ($a->id === $b->id || Friendship::between($a->id, $b->id)) {
                return;
            }
            Friendship::create(['user_id' => $a->id, 'friend_id' => $b->id, 'status' => $status, 'accepted_at' => $status === 'accepted' ? now() : null]);
        };
        foreach ($players->slice(1, 10) as $p) {
            $pair($me, $p);
        }
        $pair($players[11], $me, 'pending');
        $pair($players[12], $me, 'pending');
        foreach ($players as $i => $p) {
            foreach (range(1, mt_rand(4, 8)) as $_) {
                $pair($p, $players[mt_rand(0, $players->count() - 1)]);
            }
        }

        $games = app(Games::class);
        $chat = app(Chat::class);
        $lines = ['Barev, all!', 'Who plays on Thursday?', 'I can after 20:00', 'Zangak or Kentron?', 'Zangak is fine for me', 'Bring a white and a dark shirt', 'Привет! Я в деле', 'Ես էլ եմ գալիս ⚽', 'Let\'s book 21:00 then', '👍'];

        // open 5×5 game of player 100 with a lively chat
        $friends = collect($me->friendIds());
        $g1 = $games->create($me, ['players_per_side' => 5, 'district' => 'arabkir', 'note' => 'Friendly game, this week evenings'], $friends->take(4)->all());
        foreach ($friends->take(3) as $id) {
            $games->respond($g1->fresh(), User::find($id), true);
        }
        foreach ($players->slice(20, 2) as $p) {
            $games->join($g1->fresh(), $p);
        }
        $c = $g1->fresh()->conversation;
        $members = $c->members()->get();
        foreach ($lines as $k => $text) {
            $chat->send($c, $members[$k % $members->count()], $text);
        }

        // forming 11×11 game of player 100 (waiting for friends)
        $games->create($me, ['players_per_side' => 11, 'note' => 'Big pitch on Sunday morning'], $friends->take(8)->all());

        // other people's games: open ones player 100 can join, and an invite for player 100
        foreach ([[30, 5, 'malatia'], [35, 7, 'nornork'], [40, 3, 'kentron'], [45, 10, 'shengavit']] as [$i, $size, $district]) {
            $host = $players[$i];
            $hf = collect($host->friendIds())->reject(fn ($id) => $id === $me->id)->values();
            $need = Game::minToOpen($size * 2);
            if ($hf->count() < $need) {
                continue;
            }
            $g = $games->create($host, ['players_per_side' => $size, 'district' => $district], $hf->take($need + 1)->all());
            foreach ($hf->take($need - 1) as $id) {
                $games->respond($g->fresh(), User::find($id), true);
            }
            $cv = $g->fresh()->conversation;
            if ($cv) {
                $chat->send($cv, $host, $lines[mt_rand(0, 9)]);
            }
        }
        $friendOfMe = User::find($friends[5]);
        $ff = collect($friendOfMe->friendIds())->reject(fn ($id) => $id === $me->id)->values();
        if ($ff->count() >= 2) {
            $inv = $games->create($friendOfMe, ['players_per_side' => 3], [$me->id, ...$ff->take(2)->all()]);
            $games->respond($inv, User::find($ff[0]), true);
        }

        // a few notices so the bell is not empty for the demo player
        Notification::swap(app(\Illuminate\Notifications\ChannelManager::class));
        $p11 = $players[11]; $p12 = $players[12];
        $me->notifyNow(new \App\Notifications\Notice('friend_request', ['name' => $p11->name], '/friends?tab=requests'), ['database']);
        $me->notifyNow(new \App\Notifications\Notice('friend_request', ['name' => $p12->name], '/friends?tab=requests'), ['database']);
        $me->notifyNow(new \App\Notifications\Notice('game_opened', ['format' => '5×5'], '/games/'.$g1->id), ['database']);

        // a direct chat
        $d = $chat->direct($me, User::find($friends[0]));
        $chat->send($d, User::find($friends[0]), 'Are you coming on Thursday?');
        $chat->send($d, $me, 'Yes, see you at 21:00!');
        $this->command?->info('Friends, games and chats: added.');
    }
}
