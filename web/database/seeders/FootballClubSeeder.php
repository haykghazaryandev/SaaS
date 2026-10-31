<?php

namespace Database\Seeders;

use App\Domain\Club\Cards;
use App\Domain\Club\Shop;
use App\Domain\Venues\PriceCalculator;
use App\Models\Booking;
use App\Models\CardEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueCard;
use App\Support\LocalTime;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * Local / staging: the club tools on three football places (the ones the admins own first: Zangak, Masis, Davtashen),
 * so football can be tried like ping-pong: the desk (Now), cards, a shop (drinks, ball rent, bibs), trainings and money.
 * Masis prices its fields by length (60 / 90 / 120 min); the others keep day / evening / weekend prices per hour.
 * No rating (football has none) and no tournaments yet (football ones are for teams, coming later).
 * Safe to run again: a place that already has products is left as it is.
 */
class FootballClubSeeder extends Seeder
{
    public const PLACES = ['zangak', 'masis', 'davtashen'];

    /** [name, price, picture in data/products] */
    private const SHOP = [['Water 0.5 l', 300, 'water'], ['Isotonic 0.5 l', 800, 'isotonic'], ['Ball rent', 2000, 'ball'], ['Bibs, set of 10', 3000, 'bibs']];

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }
        foreach (Venue::whereIn('slug', self::PLACES)->with('fields')->get() as $venue) {
            if (Product::where('venue_id', $venue->id)->exists()) {
                $this->trainings($venue);

                continue;
            }
            $rules = [...($venue->rules ?? []), 'training_prices' => ['small' => 12000, 'big' => 30000],
                'cashback_tiers' => [['from' => 0, 'pct' => 3], ['from' => 150000, 'pct' => 5]]];
            if ($venue->slug === 'masis') {
                // prices by length: one price for the whole game, cheaper per hour the longer they play
                $rules['length_prices'] = [
                    'small' => [['min' => 60, 'day' => 15000, 'evening' => 20000, 'weekend' => 20000], ['min' => 90, 'day' => 21000, 'evening' => 28000, 'weekend' => 28000], ['min' => 120, 'day' => 27000, 'evening' => 36000, 'weekend' => 36000]],
                    'big' => [['min' => 90, 'day' => 55000, 'evening' => 70000, 'weekend' => 70000], ['min' => 120, 'day' => 70000, 'evening' => 90000, 'weekend' => 90000]],
                ];
            }
            $venue->update([
                'features' => array_values(array_unique([...($venue->features ?? Venue::DEFAULT_FEATURES), 'wallet', 'trainings', 'shop', 'money'])),
                'rules' => $rules,
            ]);
            foreach (self::SHOP as $i => [$name, $price, $img]) {
                $file = new UploadedFile(database_path("seeders/data/products/{$img}.png"), "{$img}.png", 'image/png', null, true);
                app(Shop::class)->save(new Product(['venue_id' => $venue->id, 'sort' => $i]), ['name' => $name, 'price' => $price], $file);
            }
            $this->cards($venue);
            $this->trainings($venue);
        }
        $this->command?->info('Football clubs: '.implode(', ', self::PLACES).' have the desk, cards, shop, trainings and money.');
    }

    /** Four demo players with cards (Aram already paid with his, so he has cashback). */
    private function cards(Venue $venue): void
    {
        foreach (User::whereIn('phone', ['+37493000100', '+37493000101', '+37493000102', '+37493000103'])->orderBy('phone')->get() as $k => $u) {
            $card = VenueCard::firstOrCreate(['venue_id' => $venue->id, 'user_id' => $u->id], ['code' => Cards::newCode()]);
            if (! $card->wasRecentlyCreated) {
                continue;
            }
            $balance = [40000, 25000, 10000, 0][$k];
            $paid = $k === 0 ? 120000 : 0;
            $card->update(['balance' => $balance]);
            if ($balance + $paid) {
                CardEntry::create(['card_id' => $card->id, 'amount' => $balance + $paid, 'kind' => 'topup', 'note' => 'demo']);
            }
            if ($paid) {
                $pay = Payment::create(['venue_id' => $venue->id, 'user_id' => $u->id, 'card_id' => $card->id, 'kind' => 'booking', 'method' => 'card', 'amount' => $paid, 'note' => 'demo']);
                CardEntry::create(['card_id' => $card->id, 'amount' => -$paid, 'kind' => 'pay', 'payment_id' => $pay->id, 'note' => 'demo']);
            }
        }
    }

    /** The next 7 days: a kids' training on the small field most evenings (made again once the last ones passed). */
    private function trainings(Venue $venue): void
    {
        if (Booking::where('venue_id', $venue->id)->where('note_owner', 'demo-training')->where('starts_at', '>', now())->exists()) {
            return;
        }
        $field = $venue->fields->where('active', true)->firstWhere('size.value', 'small');
        $coach = User::where('phone', '+37493000104')->first();
        if (! $field || ! $coach) {
            return;
        }
        $prices = app(PriceCalculator::class);
        for ($d = 0; $d < 7; $d++) {
            if ($d % 3 === 2) {
                continue;
            }
            $start = LocalTime::at(LocalTime::now()->startOfDay()->addDays($d), 17);
            $end = $start->addMinutes(90);
            $taken = Booking::where('field_id', $field->id)->whereIn('status', ['pending', 'accepted'])
                ->where('starts_at', '<', $end->utc())->where('ends_at', '>', $start->utc())->exists();
            if ($start->isPast() || $taken) {
                continue;
            }
            Booking::create([
                'code' => Booking::newCode(), 'venue_id' => $venue->id, 'field_id' => $field->id, 'user_id' => $coach->id,
                'starts_at' => $start->utc(), 'ends_at' => $end->utc(), 'status' => 'accepted', 'source' => 'player',
                'contact_name' => $coach->name, 'contact_phone' => $coach->phone, 'purpose' => 'training', 'team_name' => 'Kids academy',
                'price' => $prices->price($field->setRelation('venue', $venue), $start, 90, 'training'), 'note_owner' => 'demo-training',
            ]);
        }
    }
}
