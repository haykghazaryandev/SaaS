<?php

namespace Database\Seeders;

use App\Domain\Club\Cards;
use App\Domain\Club\Shop;
use App\Domain\Club\Tournaments;
use App\Domain\Venues\Courts;
use App\Domain\Venues\PriceCalculator;
use App\Enums\FieldSize;
use App\Models\Booking;
use App\Models\CardEntry;
use App\Models\Field;
use App\Models\OpeningHour;
use App\Models\Payment;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\SportRating;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueCard;
use App\Support\LocalTime;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * The demo ping-pong club (made-up details): 3 tables booked as "any free table", weekday and weekend prices.
 * Owner: +374 91 000 009. Safe to run again: an existing club is left as it is.
 */
class PingpongSeeder extends Seeder
{
    public function run(): void
    {
        if ($club = Venue::withTrashed()->where('slug', 'pingpong-land')->first()) {
            $this->clubExtras($club);
            $this->demoTournament($club);
            $this->adminOwns($club);
            $this->demoBookings($club);
            $this->command?->info('Ping-pong club: already there.');

            return;
        }
        $L = fn ($en, $hy, $ru) => compact('en', 'hy', 'ru');
        $venue = Venue::create([
            'slug' => 'pingpong-land', 'name' => 'Pingpong.land', 'district' => 'kentron',
            'address' => $L('10 Pushkin St', 'Պուշկինի փ. 10', 'ул. Пушкина, 10'),
            'description' => $L('Table tennis club: tables for rent by the hour, trainings and monthly tournaments.',
                'Սեղանի թենիսի ակումբ․ սեղաններ ժամավարձով, մարզումներ և ամսական մրցաշարեր։',
                'Клуб настольного тенниса: столы на час, тренировки и ежемесячные турниры.'),
            'lat' => 40.1838, 'lng' => 44.5096, 'phone' => '+37410555123',
            'amenities' => ['lockers', 'cafe'], 'rules' => ['min_notice_min' => 15, 'step_min' => 15], 'status' => 'active', 'listed' => true,
            // games (1×1, 2×2) and the whole club package on
            'features' => ['booking', 'team', 'alerts', 'reviews', 'games', 'desk', 'wallet', 'rating', 'trainings', 'shop', 'tournaments', 'money'],
        ]);
        foreach (range(0, 6) as $wd) {
            OpeningHour::create(['venue_id' => $venue->id, 'weekday' => $wd, 'opens_at' => '10:00', 'closes_at' => '22:00']);
        }
        $first = Field::create(['venue_id' => $venue->id, 'name' => __('owner.courts.name_table', ['n' => 1]), 'size' => FieldSize::Table->value,
            'format' => FieldSize::Table->format(), 'surface' => 'turf', 'indoor' => true, 'max_players' => FieldSize::Table->maxPlayers(),
            'durations' => [30, 45, 60, 90, 120], 'default_duration' => 60, 'sort' => 0, 'active' => true]);
        // weekdays 3 000 ֏/h, weekday evenings and weekends 4 000 ֏/h
        PriceRule::create(['field_id' => $first->id, 'weekdays' => [1, 2, 3, 4, 5], 'from_time' => '00:00', 'to_time' => '18:00', 'price_per_hour' => 3000, 'priority' => 1]);
        PriceRule::create(['field_id' => $first->id, 'weekdays' => [1, 2, 3, 4, 5], 'from_time' => '18:00', 'to_time' => '24:00', 'price_per_hour' => 4000, 'priority' => 1]);
        PriceRule::create(['field_id' => $first->id, 'weekdays' => [0, 6], 'from_time' => '00:00', 'to_time' => '24:00', 'price_per_hour' => 4000, 'priority' => 2]);
        $courts = app(Courts::class);
        $courts->add($venue, FieldSize::Table);
        $courts->add($venue, FieldSize::Table);

        $owner = User::firstOrCreate(['phone' => '+37491000009'], [
            'name' => 'Sahak Ping', 'locale' => 'hy', 'phone_verified_at' => now(), 'registered_at' => now(),
        ]);
        $venue->staff()->syncWithoutDetaching([$owner->id => ['role' => 'owner']]);
        $this->clubExtras($venue);
        $this->demoTournament($venue);
        $this->adminOwns($venue);
        $this->demoBookings($venue);
        $this->command?->info('Ping-pong club: added (owner +374 91 000 009).');
    }

    /**
     * Local / staging: the first admin (ADMIN_PHONE) owns this club too; a second admin gets their own club,
     * Spin Club (2 tables), to try the owner panel apart.
     */
    private function adminOwns(Venue $venue): void
    {
        if (app()->isProduction()) {
            return;
        }
        $admins = collect(AdminSeeder::phones())->map(fn ($p) => User::where('phone', $p)->first())->filter()->values();
        if ($first = $admins->get(0)) {
            AdminSeeder::ownBy($venue, $first);
        }
        // every other admin: a club of their own (Spin Club, Smash Club, then "Ping-pong club N")
        foreach ($admins->slice(1) as $i => $admin) {
            [$slug, $name] = [1 => ['spin-club', 'Spin Club'], 2 => ['smash-club', 'Smash Club']][$i] ?? ['pp-club-'.($i + 1), 'Ping-pong club '.($i + 1)];
            AdminSeeder::ownBy($this->spinClub($slug, $name), $admin);
        }
    }

    /** Another demo club with two tables (only made when there is an admin to own it). */
    private function spinClub(string $slug = 'spin-club', string $name = 'Spin Club'): Venue
    {
        if ($club = Venue::where('slug', $slug)->first()) {
            return $club;
        }
        $L = fn ($en, $hy, $ru) => compact('en', 'hy', 'ru');
        $club = Venue::create([
            'slug' => $slug, 'name' => $name, 'district' => 'arabkir',
            'address' => $L('5 Baghramyan Ave', 'Բաղրամյան պող. 5', 'пр. Баграмяна, 5'),
            'description' => $L('A small table tennis club with two tables.', 'Սեղանի թենիսի փոքր ակումբ՝ երկու սեղանով։', 'Небольшой клуб настольного тенниса с двумя столами.'),
            'lat' => 40.1950, 'lng' => 44.5040, 'amenities' => ['cafe'], 'rules' => ['min_notice_min' => 15, 'step_min' => 15, 'training_prices' => ['table' => 5000]],
            'status' => 'active', 'listed' => true,
            'features' => ['booking', 'team', 'alerts', 'reviews', 'games', 'desk', 'wallet', 'rating', 'trainings', 'shop', 'tournaments', 'money'],
        ]);
        foreach (range(0, 6) as $wd) {
            OpeningHour::create(['venue_id' => $club->id, 'weekday' => $wd, 'opens_at' => '11:00', 'closes_at' => '23:00']);
        }
        $first = Field::create(['venue_id' => $club->id, 'name' => 'Table 1', 'size' => FieldSize::Table->value, 'format' => FieldSize::Table->format(),
            'surface' => 'turf', 'indoor' => true, 'max_players' => 4, 'durations' => [30, 60, 90, 120], 'default_duration' => 60, 'sort' => 0, 'active' => true]);
        PriceRule::create(['field_id' => $first->id, 'weekdays' => [0, 1, 2, 3, 4, 5, 6], 'from_time' => '00:00', 'to_time' => '24:00', 'price_per_hour' => 2500, 'priority' => 1]);
        app(Courts::class)->add($club, FieldSize::Table, 'Table 2');
        foreach ([['Cola 0.5 l', 500, 'cola'], ['Water 0.5 l', 300, 'water']] as $i => [$name, $price, $img]) {
            $file = new UploadedFile(database_path("seeders/data/products/{$img}.png"), "{$img}.png", 'image/png', null, true);
            app(Shop::class)->save(new Product(['venue_id' => $club->id, 'sort' => $i]), ['name' => $name, 'price' => $price], $file);
        }

        return $club;
    }

    /**
     * The demo tournament gets 8 accepted players (demo players +374 93 000 100 … 107, with ratings), once, so the
     * owner can try the draw and the bracket. The draw itself is left to the owner.
     */
    private function demoTournament(Venue $venue): void
    {
        $t = Tournament::where('venue_id', $venue->id)->where('code', 'novopen1')->first();
        if (! $t || $t->entries()->exists() || $t->starts_at->isPast()) {
            return;
        }
        $tt = app(Tournaments::class);
        $users = User::whereIn('phone', collect(range(100, 107))->map(fn ($n) => '+37493000'.$n))->orderBy('phone')->get();
        foreach ($users as $i => $u) {
            SportRating::firstOrCreate(['user_id' => $u->id, 'sport' => 'pingpong'], ['rating' => 1500 - 40 * $i, 'venue_id' => $venue->id]);
            [$first, $last] = array_pad(explode(' ', (string) $u->name, 2), 2, '');
            $e = $tt->register($t, ['first_name' => $first ?: 'Player', 'last_name' => $last ?: (string) ($i + 1), 'phone' => $u->phone, 'option' => $i % 3], $u);
            $tt->decide($e, 'accepted');
        }
    }

    /** Training price, shop, a tournament and two players' cards, once (only what is missing). */
    private function clubExtras(Venue $venue): void
    {
        if (Product::where('venue_id', $venue->id)->exists()) {
            return;
        }
        // trainings are booked like a table, at their own price per hour
        $venue->update(['features' => array_values(array_unique([...($venue->features ?? Venue::DEFAULT_FEATURES), 'games', 'desk', 'wallet', 'rating', 'trainings', 'shop', 'tournaments', 'money'])),
            'rules' => [...($venue->rules ?? []), 'training_prices' => ['table' => 6000]]]);
        // the shop: drinks with their pictures (data/products/*.png)
        foreach ([['Cola 0.5 l', 500, 'cola'], ['Water 0.5 l', 300, 'water']] as $i => [$name, $price, $img]) {
            $file = new UploadedFile(database_path("seeders/data/products/{$img}.png"), "{$img}.png", 'image/png', null, true);
            app(Shop::class)->save(new Product(['venue_id' => $venue->id, 'sort' => $i]), ['name' => $name, 'price' => $price], $file);
        }
        Tournament::create(['venue_id' => $venue->id, 'sport' => 'pingpong', 'title' => 'November Open', 'code' => 'novopen1',
            'starts_at' => now()->addDays(12)->setTime(7, 0), 'description' => "Singles, groups then play-off.\nBring your own racket.",
            'options' => [['label' => 'Amateur', 'price' => 0], ['label' => 'Standard', 'price' => 5000], ['label' => 'Pro', 'price' => 10000]]]);
        // two demo players with cards: +374 93 000 100 (Aram, already paid 62 000 ֏ with it: 3% cashback) and 101
        foreach (User::whereIn('phone', ['+37493000100', '+37493000101'])->orderBy('phone')->get() as $k => $u) {
            $card = VenueCard::firstOrCreate(['venue_id' => $venue->id, 'user_id' => $u->id], ['code' => Cards::newCode()]);
            if ($card->wasRecentlyCreated) {
                $paid = $k ? 0 : 62000;
                $card->update(['balance' => 15000]);
                CardEntry::create(['card_id' => $card->id, 'amount' => 15000 + $paid, 'kind' => 'topup', 'note' => 'demo']);
                if ($paid) {
                    $pay = Payment::create(['venue_id' => $venue->id, 'user_id' => $u->id, 'card_id' => $card->id, 'kind' => 'booking', 'method' => 'card', 'amount' => $paid, 'note' => 'demo']);
                    CardEntry::create(['card_id' => $card->id, 'amount' => -$paid, 'kind' => 'pay', 'payment_id' => $pay->id, 'note' => 'demo']);
                }
            }
            SportRating::firstOrCreate(['user_id' => $u->id, 'sport' => 'pingpong'], ['rating' => $k ? 1180 : 1420, 'venue_id' => $venue->id]);
        }
    }

    /**
     * Local / staging: the next 7 days look like a real club (games, requests, trainings, the owner's own bookings).
     * Made again on a deploy once the last ones have passed. Marked note_owner = "demo".
     */
    private function demoBookings(Venue $venue): void
    {
        if (app()->isProduction() || Booking::where('venue_id', $venue->id)->where('note_owner', 'demo')->where('starts_at', '>', now())->exists()) {
            return;
        }
        $tables = $venue->fields()->where('active', true)->orderBy('sort')->orderBy('id')->get();
        $players = User::whereIn('phone', collect(range(102, 115))->map(fn ($n) => '+37493000'.$n)->all())->get()->values();
        if ($tables->count() < 3 || $players->isEmpty()) {
            return;
        }
        // [table, from, hours, status, source, purpose]
        $plan = [
            [0, 11, 2, 'accepted', 'player', 'play'], [0, 18, 1, 'pending', 'player', 'play'], [0, 20, 1.5, 'accepted', 'owner', 'play'],
            [1, 12, 1.5, 'accepted', 'player', 'training'], [1, 19.5, 1.5, 'accepted', 'player', 'play'],
            [2, 17, 1.5, 'accepted', 'owner', 'play'], [2, 19, 1, 'pending', 'player', 'play'], [2, 20.5, 1, 'accepted', 'player', 'play'],
        ];
        $prices = app(PriceCalculator::class);
        $k = 0;
        for ($d = 0; $d < 7; $d++) {
            $day = LocalTime::now()->startOfDay()->addDays($d);
            foreach ($plan as $j => [$t, $from, $hours, $status, $source, $purpose]) {
                // a bit different every day: skip some, move some
                if (($d + $j) % 4 === 3) {
                    continue;
                }
                $start = LocalTime::at($day, $from + ($d % 2 ? 0.5 : 0));
                $minutes = (int) ($hours * 60);
                if ($start->isPast() || LocalTime::hourOf($start->addMinutes($minutes), $day) > 22) {
                    continue;
                }
                $field = $tables[$t];
                $player = $players[$k++ % $players->count()];
                $free = ! Booking::where('field_id', $field->id)->whereIn('status', ['pending', 'accepted'])
                    ->where('starts_at', '<', $start->addMinutes($minutes)->utc())->where('ends_at', '>', $start->utc())->exists();
                if (! $free) {
                    continue;
                }
                Booking::create([
                    'code' => Booking::newCode(), 'venue_id' => $venue->id, 'field_id' => $field->id, 'user_id' => $source === 'player' ? $player->id : null,
                    'starts_at' => $start->utc(), 'ends_at' => $start->addMinutes($minutes)->utc(), 'status' => $status, 'source' => $source,
                    'contact_name' => $player->name, 'contact_phone' => $player->phone, 'purpose' => $purpose,
                    'price' => $prices->price($field->setRelation('venue', $venue), $start, $minutes, $purpose), 'note_owner' => 'demo',
                ]);
            }
        }
    }
}
