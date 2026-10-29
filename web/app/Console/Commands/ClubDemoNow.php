<?php

namespace App\Console\Commands;

use App\Domain\Club\Checkout;
use App\Domain\Venues\PriceCalculator;
use App\Models\Booking;
use App\Models\Product;
use App\Models\User;
use App\Models\Venue;
use App\Support\LocalTime;
use Illuminate\Console\Command;

/**
 * Local / staging only: puts games on the place's courts right now (any hour), to try Owner → Now, with real lengths:
 * court 1 Aram's 60 min game, 15 min left (2 colas), the next team 5 min after it; court 2 Davit's 90 min training,
 * started 20 min ago; court 3 a 45 min phone booking already stopped and waiting to be paid; a 4th court (if any):
 * a 15 min game, 6 min left. Run again: the previous demo games go first.
 */
class ClubDemoNow extends Command
{
    protected $signature = 'club:demo-now {venue=pingpong-land}';

    protected $description = 'Demo games on the club tables right now (not in production)';

    public function handle(PriceCalculator $prices, Checkout $checkout): int
    {
        if (app()->isProduction()) {
            $this->error('Not in production.');

            return self::FAILURE;
        }
        $venue = Venue::where('slug', $this->argument('venue'))->with('fields')->first();
        if (! $venue) {
            $this->error('No such place.');

            return self::FAILURE;
        }
        $tables = $venue->fields->where('active', true)->sortBy(fn ($f) => [$f->sort, $f->id])->values();
        $owner = $venue->staff()->wherePivot('role', 'owner')->first();
        Booking::where('venue_id', $venue->id)->where('note_owner', 'demo-now')->delete();

        $now = LocalTime::now()->second(0);
        $aram = User::where('phone', '+37493000100')->first();
        $davit = User::where('phone', '+37493000101')->first();
        // [court, player, purpose, started minutes ago, length, items]
        $games = [
            [$tables[0] ?? null, $aram, 'play', 45, 60, ['Cola' => 2]],
            [$tables[1] ?? null, $davit, 'training', 20, 90, ['Water' => 1]],
            [$tables[2] ?? null, null, 'play', 50, 45, ['Cola' => 1, 'Water' => 2]],
            [$tables[3] ?? null, null, 'play', 9, 15, []],
        ];
        foreach ($games as $i => [$table, $player, $purpose, $ago, $minutes, $items]) {
            if (! $table) {
                continue;
            }
            $start = $now->subMinutes($ago);
            // nothing else on that table now
            Booking::where('field_id', $table->id)->whereIn('status', ['pending', 'accepted'])
                ->where('starts_at', '<', $start->addMinutes($minutes)->utc())->where('ends_at', '>', $start->utc())->update(['status' => 'cancelled']);
            $full = $prices->price($table, $start, $minutes, $venue->trainingPrice($table->size->value) !== null ? $purpose : 'play');
            $b = Booking::create([
                'code' => Booking::newCode(), 'venue_id' => $venue->id, 'field_id' => $table->id, 'user_id' => $player?->id,
                'starts_at' => $start->utc(), 'ends_at' => $start->addMinutes($minutes)->utc(), 'status' => 'accepted', 'source' => $player ? 'player' : 'owner',
                'contact_name' => $player?->name ?? ($i === 3 ? 'Walk-in' : 'Gor (phone booking)'), 'contact_phone' => $player?->phone ?? ($i === 3 ? null : '+37491555444'),
                'price' => $full, 'purpose' => $purpose, 'note_owner' => 'demo-now',
                // started at the desk when they came
                'started_at' => $start->utc(), 'arrived_at' => $start->utc(),
            ]);
            foreach ($items as $name => $qty) {
                if ($p = Product::where('venue_id', $venue->id)->where('name', 'ilike', $name.'%')->first()) {
                    $checkout->addItem($b, $p, $qty, $owner);
                }
            }
            if ($i === 2) {
                $checkout->stop($b->fresh(), $owner);   // finished: waiting to be paid
            }
            $this->line(sprintf('%s: %s, %s, started %d min ago', $table->name, $player?->name ?? 'phone booking', $purpose, $ago));
        }
        // the next team on the first court, 5 min after its game ends: Now shows "+5 min" only, then "Start game"
        if ($t = $tables[0] ?? null) {
            $at = $now->addMinutes(20);
            Booking::where('field_id', $t->id)->whereIn('status', ['pending', 'accepted'])
                ->where('starts_at', '<', $at->addHour()->utc())->where('ends_at', '>', $at->utc())->update(['status' => 'cancelled']);
            Booking::create(['code' => Booking::newCode(), 'venue_id' => $venue->id, 'field_id' => $t->id, 'starts_at' => $at->utc(), 'ends_at' => $at->addHour()->utc(),
                'status' => 'accepted', 'source' => 'owner', 'contact_name' => "Narek's team", 'contact_phone' => '+37491555777',
                'price' => $prices->price($t, $at, 60, 'play'), 'note_owner' => 'demo-now']);
        }
        $this->info('Open Owner → Now.');

        return self::SUCCESS;
    }
}
