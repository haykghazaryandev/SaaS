<?php

namespace Database\Seeders;

use App\Domain\Club\Cards;
use App\Domain\Club\Shop;
use App\Domain\Venues\Courts;
use App\Enums\FieldSize;
use App\Models\CardEntry;
use App\Models\Field;
use App\Models\OpeningHour;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\SportRating;
use App\Models\Tournament;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueCard;
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
            $this->adminOwns($club);
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
            'features' => ['booking', 'team', 'alerts', 'reviews', 'games', 'wallet', 'rating', 'trainings', 'shop', 'tournaments', 'money'],
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
        $this->adminOwns($venue);
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
            $venue->staff()->syncWithoutDetaching([$first->id => ['role' => 'owner']]);
        }
        if ($second = $admins->get(1)) {
            $this->spinClub()->staff()->syncWithoutDetaching([$second->id => ['role' => 'owner']]);
        }
    }

    /** A second demo club (only made when there is a second admin to own it). */
    private function spinClub(): Venue
    {
        if ($club = Venue::where('slug', 'spin-club')->first()) {
            return $club;
        }
        $L = fn ($en, $hy, $ru) => compact('en', 'hy', 'ru');
        $club = Venue::create([
            'slug' => 'spin-club', 'name' => 'Spin Club', 'district' => 'arabkir',
            'address' => $L('5 Baghramyan Ave', 'Բաղրամյան պող. 5', 'пр. Баграмяна, 5'),
            'description' => $L('A small table tennis club with two tables.', 'Սեղանի թենիսի փոքր ակումբ՝ երկու սեղանով։', 'Небольшой клуб настольного тенниса с двумя столами.'),
            'lat' => 40.1950, 'lng' => 44.5040, 'amenities' => ['cafe'], 'rules' => ['min_notice_min' => 15, 'step_min' => 15, 'training_prices' => ['table' => 5000]],
            'status' => 'active', 'listed' => true,
            'features' => ['booking', 'team', 'alerts', 'reviews', 'games', 'wallet', 'rating', 'trainings', 'shop', 'tournaments', 'money'],
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

    /** Training price, shop, a tournament and two players' cards, once (only what is missing). */
    private function clubExtras(Venue $venue): void
    {
        if (Product::where('venue_id', $venue->id)->exists()) {
            return;
        }
        // trainings are booked like a table, at their own price per hour
        $venue->update(['features' => array_values(array_unique([...($venue->features ?? Venue::DEFAULT_FEATURES), 'games', 'wallet', 'rating', 'trainings', 'shop', 'tournaments', 'money'])),
            'rules' => [...($venue->rules ?? []), 'cashback_pct' => 3, 'training_prices' => ['table' => 6000]]]);
        // the shop: drinks with their pictures (data/products/*.png)
        foreach ([['Cola 0.5 l', 500, 'cola'], ['Water 0.5 l', 300, 'water']] as $i => [$name, $price, $img]) {
            $file = new UploadedFile(database_path("seeders/data/products/{$img}.png"), "{$img}.png", 'image/png', null, true);
            app(Shop::class)->save(new Product(['venue_id' => $venue->id, 'sort' => $i]), ['name' => $name, 'price' => $price], $file);
        }
        Tournament::create(['venue_id' => $venue->id, 'sport' => 'pingpong', 'title' => 'November Open', 'code' => 'novopen1',
            'starts_at' => now()->addDays(12)->setTime(7, 0), 'description' => "Singles, groups then play-off.\nBring your own racket.",
            'options' => [['label' => 'Amateur', 'price' => 0], ['label' => 'Standard', 'price' => 5000], ['label' => 'Pro', 'price' => 10000]]]);
        // two demo players with cards: +374 93 000 100 (Aram) and 101
        foreach (User::whereIn('phone', ['+37493000100', '+37493000101'])->get() as $k => $u) {
            $card = VenueCard::firstOrCreate(['venue_id' => $venue->id, 'user_id' => $u->id], ['code' => Cards::newCode(), 'discount_pct' => $k ? 0 : 10]);
            if ($card->wasRecentlyCreated) {
                $card->update(['balance' => 15000]);
                CardEntry::create(['card_id' => $card->id, 'amount' => 15000, 'kind' => 'topup', 'note' => 'demo']);
            }
            SportRating::firstOrCreate(['user_id' => $u->id, 'sport' => 'pingpong'], ['rating' => $k ? 1180 : 1420, 'venue_id' => $venue->id]);
        }
    }
}
