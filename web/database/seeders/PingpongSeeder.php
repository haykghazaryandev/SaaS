<?php

namespace Database\Seeders;

use App\Domain\Venues\Courts;
use App\Enums\FieldSize;
use App\Models\Field;
use App\Models\OpeningHour;
use App\Models\PriceRule;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Seeder;

/**
 * The demo ping-pong club (made-up details): 3 tables booked as "any free table", weekday and weekend prices.
 * Owner: +374 91 000 009. Safe to run again: an existing club is left as it is.
 */
class PingpongSeeder extends Seeder
{
    public function run(): void
    {
        if (Venue::withTrashed()->where('slug', 'pingpong-land')->exists()) {
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
            'lat' => 40.1838, 'lng' => 44.5096, 'phone' => '+37410555123', 'rating_avg' => 4.9, 'rating_count' => 37,
            'amenities' => ['lockers', 'cafe'], 'rules' => ['min_notice_min' => 30, 'step_min' => 30], 'status' => 'active', 'listed' => true,
            // no games yet (step 2): booking, the team and "notify me" only
            'features' => ['booking', 'team', 'alerts', 'reviews'],
        ]);
        foreach (range(0, 6) as $wd) {
            OpeningHour::create(['venue_id' => $venue->id, 'weekday' => $wd, 'opens_at' => '10:00', 'closes_at' => '23:00']);
        }
        $first = Field::create(['venue_id' => $venue->id, 'name' => __('owner.courts.name_table', ['n' => 1]), 'size' => FieldSize::Table->value,
            'format' => FieldSize::Table->format(), 'surface' => 'turf', 'indoor' => true, 'max_players' => FieldSize::Table->maxPlayers(),
            'durations' => [30, 60, 90, 120], 'default_duration' => 60, 'sort' => 0, 'active' => true]);
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
        $this->command?->info('Ping-pong club: added (owner +374 91 000 009).');
    }
}
