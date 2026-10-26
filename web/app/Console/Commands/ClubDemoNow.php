<?php

namespace App\Console\Commands;

use App\Domain\Club\Cards;
use App\Domain\Club\Checkout;
use App\Domain\Venues\PriceCalculator;
use App\Models\Booking;
use App\Models\Product;
use App\Models\User;
use App\Models\Venue;
use App\Support\LocalTime;
use Illuminate\Console\Command;

/**
 * Local / staging only: puts games on the demo club's tables right now (any hour), to try Owner → Now:
 * table 1 Aram playing 50 min with 2 colas, table 2 Davit's training (his 10% discount), table 3 a phone-only
 * booking already stopped and waiting to be paid. Run again: the previous demo games go first.
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
        $games = [
            [$tables[0] ?? null, $aram, 'play', 50, 60, ['Cola' => 2]],
            [$tables[1] ?? null, $davit, 'training', 25, 90, ['Water' => 1]],
            [$tables[2] ?? null, null, 'play', 65, 60, ['Balls' => 1]],
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
            $pct = $player ? Cards::discountFor($venue->id, $player->id) : 0;
            $b = Booking::create([
                'code' => Booking::newCode(), 'venue_id' => $venue->id, 'field_id' => $table->id, 'user_id' => $player?->id,
                'starts_at' => $start->utc(), 'ends_at' => $start->addMinutes($minutes)->utc(), 'status' => 'accepted', 'source' => $player ? 'player' : 'owner',
                'contact_name' => $player?->name ?? 'Gor (phone booking)', 'contact_phone' => $player?->phone ?? '+37491555444',
                'price' => (int) round($full * (100 - $pct) / 100), 'purpose' => $purpose, 'note_owner' => 'demo-now',
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
        $this->info('Open Owner → Now.');

        return self::SUCCESS;
    }
}
