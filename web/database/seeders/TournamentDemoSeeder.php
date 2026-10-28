<?php

namespace Database\Seeders;

use App\Domain\Club\Tournaments;
use App\Models\SportRating;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Models\Venue;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Demo tournaments at Pingpong.land (local / staging only) around the first admin (ADMIN_PHONE), who owns the club
 * there, so one account sees every case as the owner and as a player:
 * - Autumn Cup: on now, the admin won the semi-final and is in the Grand Final, the other semi-final live
 * - Friday Night: on now, 16 players, the admin's first match and two more live, some results
 * - Weekend Open: the draw is made, in 2 days, the admin accepted with the fee not paid
 * - December Open: registration open, the admin accepted (the fee is taken after registration closes)
 * - Club Championship: registration open, a rating range and 16 places, the admin waiting for the answer
 * - October Cup: finished 3 days ago, the admin runner-up
 * - Winter Cup: 3 divisions by rating — Amateur on now (a match live), Middle drawn, Pro registration open (the admin accepted)
 * Made once; made again when older than 2 days (so the dates stay right on staging). Nobody is notified while seeding.
 */
class TournamentDemoSeeder extends Seeder
{
    public function run(): void
    {
        $club = Venue::where('slug', 'pingpong-land')->first();
        $admin = ($phone = AdminSeeder::phones()[0] ?? null) ? User::where('phone', $phone)->first() : null;
        if (! $club || ! $admin) {
            return;
        }
        $players = User::where('phone', 'like', '+3749300%')->orderBy('phone')->limit(20)->get();
        if ($players->count() < 15) {
            return;
        }
        // the admin is the best seed (1650): the top of the bracket
        SportRating::updateOrCreate(['user_id' => $admin->id, 'sport' => 'pingpong'], ['rating' => 1650, 'venue_id' => $club->id]);
        foreach ($players->values() as $i => $u) {
            SportRating::firstOrCreate(['user_id' => $u->id, 'sport' => 'pingpong'], ['rating' => 1600 - 15 * $i, 'venue_id' => $club->id]);
        }

        $real = Notification::getFacadeRoot();
        Notification::fake();   // no messages / Telegram for the demo
        try {
            $this->autumnCup($club, $admin, $players);
            $this->fridayNight($club, $admin, $players);
            $this->weekendOpen($club, $admin, $players);
            $this->decemberOpen($club, $admin, $players);
            $this->championship($club, $admin, $players);
            $this->octoberCup($club, $admin, $players);
            $this->winterCup($club, $admin, $players);
        } finally {
            Notification::swap($real);
        }
        $this->command?->info('Demo tournaments: ready for '.$admin->phone.'.');
    }

    // ---------- the cases ----------

    private function autumnCup(Venue $club, User $admin, Collection $players): void
    {
        $t = $this->make($club, 'demoauto', 'Autumn Cup', now()->subHour(), [['label' => 'Standard', 'price' => 3000]]);
        if (! $t) {
            return;
        }
        $this->fill($t, $admin, $players->take(7), accepted: true);
        $this->tt()->draw($t);
        $this->payAll($t, $admin);   // fees after registration closed; it starts when everybody paid
        $this->playRound($t, 1);
        $semis = TournamentMatch::where('tournament_id', $t->id)->where('round', 2)->orderBy('slot')->get();
        $this->tt()->result($semis[0], $semis[0]->a_id, '3:2');           // the admin (seed 1) is in the Final
        $semis[1]->update(['score' => '1:1']);
        $this->tt()->setLive($semis[1]->fresh(), true);                     // the other semi-final: live
        $t->update(['starts_at' => now()->subHours(2)]);
    }

    private function fridayNight(Venue $club, User $admin, Collection $players): void
    {
        $t = $this->make($club, 'demofrid', 'Friday Night', now()->subMinutes(40), [['label' => 'Free', 'price' => 0]]);
        if (! $t) {
            return;
        }
        $this->fill($t, $admin, $players->take(15), accepted: true);
        $this->tt()->draw($t);
        foreach (TournamentMatch::where('tournament_id', $t->id)->where('round', 1)->orderBy('slot')->get() as $m) {
            if (in_array($m->slot, [0, 3, 6], true)) {
                $m->update(['score' => $m->slot === 0 ? '2:1' : '1:1']);
                $this->tt()->setLive($m->fresh(), true);                    // the admin's match (slot 0) and two more: live
            } elseif ($m->a_id && $m->b_id) {
                $this->tt()->result($m, $m->a_id, '3:1');
            }
        }
        $t->update(['starts_at' => now()->subMinutes(40)]);
    }

    private function weekendOpen(Venue $club, User $admin, Collection $players): void
    {
        $t = $this->make($club, 'demowknd', 'Weekend Open', $this->at(2, 11), [['label' => 'Standard', 'price' => 5000]]);
        if (! $t) {
            return;
        }
        $this->fill($t, $admin, $players->take(7), accepted: true);        // the admin: accepted, the fee not paid
        $t->update(['starts_at' => $this->at(2, 11)]);
        $this->tt()->draw($t->fresh());
    }

    private function decemberOpen(Venue $club, User $admin, Collection $players): void
    {
        $t = $this->make($club, 'demodece', 'December Open', $this->at(9, 11),
            [['label' => 'Amateur', 'price' => 0], ['label' => 'Standard', 'price' => 5000], ['label' => 'Pro', 'price' => 10000]], ['max_players' => 16]);
        if (! $t) {
            return;
        }
        $this->fill($t, $admin, $players->take(9), accepted: true, option: 1);   // fees after registration closes
        $t->update(['starts_at' => $this->at(9, 11)]);
    }

    private function championship(Venue $club, User $admin, Collection $players): void
    {
        $t = $this->make($club, 'demochmp', 'Club Championship', $this->at(14, 12), [['label' => 'Standard', 'price' => 4000]],
            ['min_rating' => 1100, 'max_rating' => 1700, 'max_players' => 16]);
        if (! $t) {
            return;
        }
        $this->fill($t, null, $players->take(10), accepted: true);
        $this->register($t, $admin);                                        // the admin: waiting for the answer
        $t->update(['starts_at' => $this->at(14, 12)]);
    }

    private function octoberCup(Venue $club, User $admin, Collection $players): void
    {
        $t = $this->make($club, 'demooctc', 'October Cup', $this->at(-3, 11), [['label' => 'Standard', 'price' => 3000]]);
        if (! $t) {
            return;
        }
        $this->fill($t, $admin, $players->slice(3, 7), accepted: true);
        $this->tt()->draw($t);
        $this->payAll($t, $admin);
        $this->playRound($t, 1);
        $this->playRound($t, 2);
        $final = TournamentMatch::where('tournament_id', $t->id)->where('round', 3)->first();
        $this->tt()->result($final, $final->b_id, '3:2');                   // the admin (side a) loses the Final: 2nd
        $t->update(['starts_at' => $this->at(-3, 11)]);
    }

    private function winterCup(Venue $club, User $admin, Collection $players): void
    {
        $old = Tournament::where('code', 'demowint')->first();
        if ($old && $old->created_at->greaterThan(now()->subDays(2))) {
            return;
        }
        $old?->delete();
        $cup = $this->tt()->saveWithDivisions(new Tournament(['venue_id' => $club->id]), ['title' => 'Winter Cup', 'sport' => 'pingpong',
            'starts_at' => $this->at(20, 11), 'description' => 'Demo tournament with divisions (local / staging).', 'options' => [['label' => 'Standard', 'price' => 4000]]], [
                ['name' => 'Amateur', 'max_rating' => 1349, 'max_players' => 16],
                ['name' => 'Middle', 'min_rating' => 1350, 'max_rating' => 1449, 'max_players' => 16],
                ['name' => 'Pro', 'min_rating' => 1450, 'max_players' => 8],
            ]);
        $cup->forceFill(['code' => 'demowint'])->save();
        foreach ($cup->divisions as $d) {
            // the players of each division's range
            $in = $players->filter(function (User $u) use ($d) {
                $r = Tournaments::ratingOf($d, $u->id, null);

                return $r !== null && ($d->min_rating === null || $r >= $d->min_rating) && ($d->max_rating === null || $r <= $d->max_rating);
            })->take(6);
            $this->fill($d, $d->division === 'Pro' ? $admin : null, $in, accepted: true);
        }
        // every state on one page: Amateur is being played (a result, a match live), Middle has its draw
        [$amateur, $middle] = [$cup->divisions[0]->fresh(), $cup->divisions[1]->fresh()];
        $this->tt()->draw($amateur);
        $this->payAll($amateur, $admin);
        $first = TournamentMatch::where('tournament_id', $amateur->id)->where('round', 1)->whereNotNull('a_id')->whereNotNull('b_id')->orderBy('slot')->get();
        if ($first->count() >= 2) {
            $this->tt()->result($first[0], $first[0]->a_id, '3:1');
            $first[1]->update(['score' => '2:1']);
            $this->tt()->setLive($first[1]->fresh(), true);
        }
        $this->tt()->draw($middle);
    }

    // ---------- helpers ----------

    /** A day from today ($days, negative: before) at $hour, Yerevan time. */
    private function at(int $days, int $hour): CarbonImmutable
    {
        return LocalTime::at(LocalTime::now()->startOfDay()->addDays($days), $hour)->utc();
    }

    private function tt(): Tournaments
    {
        return app(Tournaments::class);
    }

    /** The demo tournament with this code: made now (registration still open), or null when a fresh one is there. */
    private function make(Venue $club, string $code, string $title, $startsAt, array $options, array $extra = []): ?Tournament
    {
        $old = Tournament::where('code', $code)->first();
        if ($old && $old->created_at->greaterThan(now()->subDays(2))) {
            return null;
        }
        $old?->delete();
        $t = $this->tt()->save(new Tournament(['venue_id' => $club->id]), ['title' => $title, 'sport' => 'pingpong',
            'starts_at' => now()->addDays(30), 'description' => 'Demo tournament (local / staging).', 'options' => $options] + $extra);
        $t->forceFill(['code' => $code])->save();

        // registrations need a date ahead: each case sets its real date ($startsAt) after them
        return $t->fresh();
    }

    /** The admin (first) and the players, registered; accepted when asked. */
    private function fill(Tournament $t, ?User $admin, Collection $players, bool $accepted, int $option = 0): void
    {
        foreach (array_filter([$admin, ...$players->all()]) as $u) {
            $e = $this->register($t, $u, $option);
            if ($accepted && $e) {
                $this->tt()->decide($e, 'accepted');
            }
        }
    }

    private function register(Tournament $t, User $u, int $option = 0): ?TournamentEntry
    {
        [$first, $last] = array_pad(explode(' ', trim((string) $u->name) ?: 'Player', 2), 2, '');

        return $this->tt()->register($t->fresh(), ['first_name' => $first, 'last_name' => $last ?: 'Player', 'phone' => $u->phone, 'option' => $option], $u);
    }

    private function pay(Tournament $t, User $u, string $method): void
    {
        $e = TournamentEntry::where('tournament_id', $t->id)->where('user_id', $u->id)->first();
        if ($e && ! $e->payment_id && Tournaments::fee($t, $e) > 0) {
            $this->tt()->pay($e, $method, $u);
        }
    }

    /** Every accepted player's fee, in cash (the admin's own included). */
    private function payAll(Tournament $t, User $by): void
    {
        foreach (TournamentEntry::where('tournament_id', $t->id)->where('status', 'accepted')->whereNull('payment_id')->get() as $e) {
            if (Tournaments::fee($t, $e) > 0) {
                $this->tt()->pay($e, 'cash', $by);
            }
        }
    }

    /** Every match of a round with both players: the higher seed (side a) wins. */
    private function playRound(Tournament $t, int $round): void
    {
        foreach (TournamentMatch::where('tournament_id', $t->id)->where('round', $round)->orderBy('slot')->get() as $m) {
            $m = $m->fresh();
            if ($m->a_id && $m->b_id && ! $m->winner_id) {
                $this->tt()->result($m, $m->a_id, '3:1');
            }
        }
    }
}
