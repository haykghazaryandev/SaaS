<?php

namespace Database\Seeders;

use App\Domain\Club\Shop;
use App\Domain\Venues\PriceCalculator;
use App\Models\Booking;
use App\Models\Product;
use App\Models\User;
use App\Models\Venue;
use App\Support\LocalTime;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * Local / staging: what a football place fills in itself, so football can be tried like ping-pong. The club tools
 * (Now, shop, trainings, money…) every place has by default (Venue::defaultFeatures; cards: ping-pong only); this adds
 * the data: a shop (drinks, ball rent, bibs), training prices and kids' trainings. Masis prices its fields by length
 * (60 / 90 / 120 min); the others keep day / evening / weekend prices per hour. Football has no rating, and its
 * tournaments (teams) come later. Safe to run again: a place that already has products is left as it is.
 */
class FootballClubSeeder extends Seeder
{
    public const PLACES = ['zangak', 'masis', 'davtashen', 'avan', 'nork', 'ajapnyak', 'kentron', 'malatia'];

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
            $rules = [...($venue->rules ?? []), 'training_prices' => ['small' => 12000, 'big' => 30000]];
            if ($venue->slug === 'masis') {
                // prices by length: one price for the whole game, cheaper per hour the longer they play
                $rules['length_prices'] = [
                    'small' => [['min' => 60, 'day' => 15000, 'evening' => 20000, 'weekend' => 20000], ['min' => 90, 'day' => 21000, 'evening' => 28000, 'weekend' => 28000], ['min' => 120, 'day' => 27000, 'evening' => 36000, 'weekend' => 36000]],
                    'big' => [['min' => 90, 'day' => 55000, 'evening' => 70000, 'weekend' => 70000], ['min' => 120, 'day' => 70000, 'evening' => 90000, 'weekend' => 90000]],
                ];
            }
            $venue->update(['rules' => $rules]);
            foreach (self::SHOP as $i => [$name, $price, $img]) {
                $file = new UploadedFile(database_path("seeders/data/products/{$img}.png"), "{$img}.png", 'image/png', null, true);
                app(Shop::class)->save(new Product(['venue_id' => $venue->id, 'sort' => $i]), ['name' => $name, 'price' => $price], $file);
            }
            $this->trainings($venue);
        }
        $this->command?->info('Football places: shop, training prices and kids\' trainings added.');
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
