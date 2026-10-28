<?php

namespace Database\Seeders;

use App\Domain\Venues\PriceCalculator;
use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\SportRating;
use App\Models\User;
use App\Models\Venue;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Cases to try the requests at Pingpong.land (local / staging only; the first admin owns it there):
 * - tomorrow 19:00, Table 1: three players ask for the same time (the other tables taken) — the best record here first,
 *   not the highest rating: one who always came (★ Best choice), a new one (#2), one with the highest rating who once
 *   didn't come (#3)
 * - in 2 days 17:00, Table 3: a training request (a player who came late once) and a game request for the same time
 * - tomorrow 12:00: an accepted training (pink on the schedule); yesterday 18:00: a game started and extended +15 min
 *   (its history: "Game started", "Game extended")
 * Made again when none of these is ahead any more (so the dates stay right on staging).
 */
class RequestCasesSeeder extends Seeder
{
    private const NOTE = 'demo-case';

    public function run(): void
    {
        $club = Venue::where('slug', 'pingpong-land')->first();
        if (app()->isProduction() || ! $club || Booking::where('venue_id', $club->id)->where('note_owner', self::NOTE)->where('starts_at', '>', now())->exists()) {
            return;
        }
        $tables = $club->fields()->where('active', true)->orderBy('sort')->orderBy('id')->get()->each->setRelation('venue', $club)->values();
        $p = User::whereIn('phone', collect(range(130, 136))->map(fn ($n) => '+37493000'.$n)->all())->orderBy('phone')->get()->values();
        if ($tables->count() < 3 || $p->count() < 7) {
            return;
        }
        [$lilit, $tigran, $gor, $ani, $arsen, $w1, $w2] = $p->all();
        foreach ([[$lilit, 1300], [$tigran, 1380], [$gor, 1460], [$ani, 1340], [$arsen, 1290]] as [$u, $r]) {
            SportRating::updateOrCreate(['user_id' => $u->id, 'sport' => 'pingpong'], ['rating' => $r, 'venue_id' => $club->id]);
        }

        // their record here (once): came 5 times; came 2 times and once didn't come; came 3 times, once late
        if (! Booking::where('venue_id', $club->id)->where('note_owner', self::NOTE.'-past')->exists()) {
            foreach ([[$lilit, 'completed', 5, null], [$gor, 'completed', 2, null], [$gor, 'no_show', 1, null], [$ani, 'completed', 2, null], [$ani, 'completed', 1, 20]] as [$u, $status, $n, $late]) {
                for ($i = 0; $i < $n; $i++) {
                    $at = LocalTime::at(LocalTime::now()->startOfDay()->subDays(4 + 3 * $i + ($u->id % 3)), 12 + ($u->id % 5));
                    $this->make($club, $tables[$i % 3], $u, $at, 60, $status, self::NOTE.'-past', ['late_minutes' => $late,
                        'started_at' => $status === 'completed' ? $at->addMinutes($late ?? 0)->utc() : null]);
                }
            }
        }

        // tomorrow 19:00: the other two tables taken, three requests for Table 1 (asked one after the other)
        $t1 = LocalTime::at(LocalTime::now()->startOfDay()->addDay(), 19);
        $this->make($club, $tables[1], $w1, $t1, 60, 'accepted', self::NOTE);
        $this->make($club, $tables[2], $w2, $t1, 60, 'accepted', self::NOTE);
        foreach ([$gor, $tigran, $lilit] as $i => $u) {
            $this->make($club, $tables[0], $u, $t1, 60, 'pending', self::NOTE, ['created_at' => now()->subMinutes(40 - 10 * $i)]);
        }

        // in 2 days 17:00: a training and a game for the same table
        $t2 = LocalTime::at(LocalTime::now()->startOfDay()->addDays(2), 17);
        $this->make($club, $tables[0], $w1, $t2, 90, 'accepted', self::NOTE);
        $this->make($club, $tables[1], $w2, $t2, 90, 'accepted', self::NOTE);
        $this->make($club, $tables[2], $ani, $t2, 60, 'pending', self::NOTE, ['purpose' => 'training', 'created_at' => now()->subMinutes(25)]);
        $this->make($club, $tables[2], $arsen, $t2, 60, 'pending', self::NOTE, ['created_at' => now()->subMinutes(15)]);

        // tomorrow 12:00: an accepted training (its own colour on the schedule)
        $this->make($club, $tables[2], $lilit, LocalTime::at(LocalTime::now()->startOfDay()->addDay(), 12), 60, 'accepted', self::NOTE, ['purpose' => 'training']);
        // yesterday 18:00: a game started at the desk and extended +15 min (its history: "Game started", "Game extended")
        $start = LocalTime::at(LocalTime::now()->startOfDay()->subDay(), 18);
        $staff = $club->staff()->first()?->id;
        if ($b = $this->make($club, $tables[1], $tigran, $start, 75, 'completed', self::NOTE, ['started_at' => $start->addMinutes(3)->utc()])) {
            BookingEvent::forceCreate(['booking_id' => $b->id, 'type' => 'started', 'user_id' => $staff, 'created_at' => $start->addMinutes(3)]);
            BookingEvent::forceCreate(['booking_id' => $b->id, 'type' => 'extended', 'user_id' => $staff, 'changes' => ['minutes' => 15], 'created_at' => $start->addMinutes(55)]);
        }
        $this->command?->info('Request cases at Pingpong.land: ready.');
    }

    /** A booking, when its table is free then (the random demo bookings make way; accepted ones block); null when taken. */
    private function make(Venue $club, $table, User $u, CarbonImmutable $at, int $minutes, string $status, string $note, array $extra = []): ?Booking
    {
        $end = $at->addMinutes($minutes);
        if ($end->isFuture()) {
            Booking::where('field_id', $table->id)->where('note_owner', 'demo')->whereIn('status', ['accepted', 'pending'])
                ->where('starts_at', '<', $end->utc())->where('ends_at', '>', $at->utc())->delete();
        }
        if (in_array($status, ['accepted', 'pending'], true) && Booking::where('field_id', $table->id)->where('status', 'accepted')
            ->where('starts_at', '<', $end->utc())->where('ends_at', '>', $at->utc())->exists()) {
            return null;
        }
        $purpose = $extra['purpose'] ?? 'play';
        $b = new Booking([
            'code' => Booking::newCode(), 'venue_id' => $club->id, 'field_id' => $table->id, 'user_id' => $u->id,
            'starts_at' => $at->utc(), 'ends_at' => $end->utc(), 'status' => $status, 'source' => 'player',
            'contact_name' => $u->name, 'contact_phone' => $u->phone, 'purpose' => $purpose, 'note_owner' => $note,
            'price' => app(PriceCalculator::class)->price($table, $at, $minutes, $purpose),
        ] + array_diff_key($extra, ['created_at' => 1]));
        if (isset($extra['created_at'])) {
            $b->created_at = $extra['created_at'];
        }
        $b->save();
        if ($status === 'pending') {
            BookingEvent::forceCreate(['booking_id' => $b->id, 'type' => 'requested', 'user_id' => $u->id, 'created_at' => $b->created_at]);
        }

        return $b;
    }
}
