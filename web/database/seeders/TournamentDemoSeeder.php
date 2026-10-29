<?php

namespace Database\Seeders;

use App\Domain\Club\Tournaments;
use App\Models\Payment;
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
 * there and plays in them too. Every tournament has divisions by rating (Amateur ≤1349, Middle 1350–1449, Pro 1450+),
 * each division in its own state, so every case can be seen:
 * - Autumn Cup (today): Amateur on now; Pro on now, the admin through to the Final
 * - Weekend Open (in 2 days): the draws made, fees taken; Amateur still waiting for one fee
 * - Club Championship (in 14 days): registration open, the admin waiting for the answer in Pro
 * - Winter Cup (in 20 days): registration open, the admin accepted in Pro (the fee after registration)
 * - October Cup (3 days ago): Amateur and Pro over (the admin runner-up), Middle cancelled — not enough players
 * Made once; made again when older than 2 days (so the dates stay right on staging). Nobody is notified while seeding.
 */
class TournamentDemoSeeder extends Seeder
{
    private const DIVISIONS = [
        ['name' => 'Amateur', 'max_rating' => 1349, 'max_players' => 16],
        ['name' => 'Middle', 'min_rating' => 1350, 'max_rating' => 1449, 'max_players' => 16],
        ['name' => 'Pro', 'min_rating' => 1450, 'max_players' => 8],
    ];

    public function run(): void
    {
        $club = Venue::where('slug', 'pingpong-land')->first();
        $admin = ($phone = AdminSeeder::phones()[0] ?? null) ? User::where('phone', $phone)->first() : null;
        if (! $club || ! $admin) {
            return;
        }
        $players = User::where('phone', 'like', '+3749300%')->orderBy('phone')->limit(24)->get();
        if ($players->count() < 15) {
            return;
        }
        // the admin is the best (1650): Pro, the top of its bracket
        SportRating::updateOrCreate(['user_id' => $admin->id, 'sport' => 'pingpong'], ['rating' => 1650, 'venue_id' => $club->id]);
        foreach ($players->values() as $i => $u) {
            SportRating::firstOrCreate(['user_id' => $u->id, 'sport' => 'pingpong'], ['rating' => 1600 - 15 * $i, 'venue_id' => $club->id]);
        }

        $real = Notification::getFacadeRoot();
        Notification::fake();   // no messages / Telegram for the demo
        try {
            $this->autumnCup($club, $admin, $players);
            $this->weekendOpen($club, $admin, $players);
            $this->championship($club, $admin, $players);
            $this->winterCup($club, $admin, $players);
            $this->octoberCup($club, $admin, $players);
        } finally {
            Notification::swap($real);
        }
        $this->command?->info('Demo tournaments (with divisions): ready for '.$admin->phone.'.');
    }

    // ---------- the cases ----------

    private function autumnCup(Venue $club, User $admin, Collection $players): void
    {
        if (! [$cup, $am, $mid, $pro] = $this->cup($club, 'demoautm', 'Autumn Cup', [['label' => '', 'price' => 3000]])) {
            return;
        }
        $this->fill($am, null, $this->inRange($am, $players)->take(7), accepted: true);
        $this->fill($pro, $admin, $this->inRange($pro, $players)->take(3), accepted: true);
        foreach ([$am, $pro] as $d) {
            $this->tt()->draw($d->fresh());
            $this->payAll($d, $admin);
        }
        // Amateur: the first round half played, one match live
        $this->live($am, 1);
        // Pro (4 players): the admin (seed 1) through to the Final, the other semi-final live
        $mine = $this->entryOf($pro, $admin);
        $semis = TournamentMatch::where('tournament_id', $pro->id)->where('round', 1)->orderBy('slot')->get();
        foreach ($semis as $m) {
            if (! $m->a_id || ! $m->b_id) {
                continue;
            }
            if (in_array($mine, [$m->a_id, $m->b_id], true)) {
                $this->tt()->result($m, $mine, '3:1');
            } else {
                $m->update(['score' => '1:1']);
                $this->tt()->setLive($m->fresh(), true);
            }
        }
        $this->tt()->cancel($mid->fresh(), $admin, 'Not enough players registered');
        $this->dates($cup, now()->subHour());
    }

    private function weekendOpen(Venue $club, User $admin, Collection $players): void
    {
        if (! [$cup, $am, $mid, $pro] = $this->cup($club, 'demowkd2', 'Weekend Open', [['label' => '', 'price' => 5000]])) {
            return;
        }
        foreach ([[$am, null, 9], [$mid, null, 6], [$pro, $admin, 3]] as [$d, $who, $n]) {
            $this->fill($d, $who, $this->inRange($d, $players)->take($n), accepted: true);
            $this->tt()->draw($d->fresh());
            $this->payAll($d, $admin);
        }
        // one Amateur fee still to take
        $last = TournamentEntry::where('tournament_id', $am->id)->whereNotNull('payment_id')->latest('id')->first();
        if ($last) {
            Payment::whereKey($last->payment_id)->delete();
            $last->update(['payment_id' => null]);
        }
        $this->dates($cup, $this->at(2, 11));
    }

    private function championship(Venue $club, User $admin, Collection $players): void
    {
        if (! [$cup, $am, $mid, $pro] = $this->cup($club, 'demochm2', 'Club Championship', [['label' => '', 'price' => 4000]])) {
            return;
        }
        $this->fill($am, null, $this->inRange($am, $players)->take(5), accepted: true);
        $this->fill($mid, null, $this->inRange($mid, $players)->take(4), accepted: true);
        $this->fill($pro, null, $this->inRange($pro, $players)->take(2), accepted: true);
        $this->register($pro, $admin);   // the admin: waiting for the answer
        $this->dates($cup, $this->at(14, 12));
    }

    private function winterCup(Venue $club, User $admin, Collection $players): void
    {
        if (! [$cup, $am, $mid, $pro] = $this->cup($club, 'demowin2', 'Winter Cup', [['label' => '', 'price' => 4000]])) {
            return;
        }
        $this->fill($am, null, $this->inRange($am, $players)->take(6), accepted: true);
        $this->fill($mid, null, $this->inRange($mid, $players)->take(4), accepted: true);
        $this->fill($pro, $admin, $this->inRange($pro, $players)->take(2), accepted: true);
        $this->dates($cup, $this->at(20, 11));
    }

    private function octoberCup(Venue $club, User $admin, Collection $players): void
    {
        if (! [$cup, $am, $mid, $pro] = $this->cup($club, 'demooct2', 'October Cup', [['label' => '', 'price' => 3000]])) {
            return;
        }
        $this->fill($am, null, $this->inRange($am, $players)->take(4), accepted: true);
        $this->fill($pro, $admin, $this->inRange($pro, $players)->take(3), accepted: true);
        $this->fill($mid, null, $this->inRange($mid, $players)->take(1), accepted: true);
        $this->tt()->cancel($mid->fresh(), $admin, 'Not enough players registered');
        foreach ([$am, $pro] as $d) {
            $this->tt()->draw($d->fresh());
            $this->payAll($d, $admin);
            $this->playRound($d, 1);
        }
        // the Finals: Amateur by the higher seed, Pro lost by the admin
        foreach ([$am, $pro] as $d) {
            $final = TournamentMatch::where('tournament_id', $d->id)->orderByDesc('round')->first();
            if ($final?->a_id && $final->b_id) {
                $this->tt()->result($final, $final->a_id === $this->entryOf($d, $admin) ? $final->b_id : $final->a_id, '3:2');
            }
        }
        $this->dates($cup, $this->at(-3, 11));
    }

    // ---------- divisions ----------

    /** The cup with its three divisions, made now (registration open), or null when a fresh one is there. */
    private function cup(Venue $club, string $code, string $title, array $options): ?array
    {
        $old = Tournament::where('code', $code)->first();
        if ($old && $old->created_at->greaterThan(now()->subDays(2))) {
            return null;
        }
        $old?->delete();
        $cup = $this->tt()->saveWithDivisions(new Tournament(['venue_id' => $club->id]), ['title' => $title, 'sport' => 'pingpong',
            'starts_at' => now()->addDays(30), 'description' => 'Demo tournament with divisions (local / staging).', 'options' => $options], self::DIVISIONS);
        $cup->forceFill(['code' => $code])->save();
        $d = $cup->divisions()->get()->each(fn ($x) => $x->setRelation('venue', $club))->values();

        return [$cup, $d[0], $d[1], $d[2]];
    }

    /** The user's entry id in the division (matches hold entry ids). */
    private function entryOf(Tournament $d, User $u): ?int
    {
        return TournamentEntry::where('tournament_id', $d->id)->where('user_id', $u->id)->value('id');
    }

    /** The players whose rating is in the division's range. */
    private function inRange(Tournament $d, Collection $players): Collection
    {
        return $players->filter(function (User $u) use ($d) {
            $r = Tournaments::ratingOf($d, $u->id, null);

            return $r !== null && ($d->min_rating === null || $r >= $d->min_rating) && ($d->max_rating === null || $r <= $d->max_rating);
        })->values();
    }

    /** Half of a round played (the higher seed wins), the next match being played now. */
    private function live(Tournament $d, int $round): void
    {
        $ms = TournamentMatch::where('tournament_id', $d->id)->where('round', $round)->whereNotNull('a_id')->whereNotNull('b_id')->orderBy('slot')->get();
        foreach ($ms->values() as $i => $m) {
            if ($i < intdiv($ms->count(), 2)) {
                $this->tt()->result($m, $m->a_id, '3:1');
            } elseif ($i === intdiv($ms->count(), 2)) {
                $m->update(['score' => '2:1']);
                $this->tt()->setLive($m->fresh(), true);
            }
        }
    }

    /** The real date of the cup and its divisions (after the registrations). */
    private function dates(Tournament $cup, $at): void
    {
        Tournament::whereKey($cup->id)->orWhere('parent_id', $cup->id)->update(['starts_at' => $at instanceof CarbonImmutable ? $at->utc() : $at]);
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
