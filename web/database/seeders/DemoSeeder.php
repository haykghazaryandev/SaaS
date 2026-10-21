<?php

namespace Database\Seeders;

use App\Domain\Venues\PriceCalculator;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Field;
use App\Models\User;
use App\Models\Venue;
use App\Support\LocalTime;
use Illuminate\Database\Seeder;

/**
 * Demo people and bookings so the apps look alive: an admin, one owner per place, 60 players,
 * and bookings for the past 7 and next 14 days (some still waiting for the owner).
 */
class DemoSeeder extends Seeder
{
    public const FIRST = ['Aram', 'Davit', 'Narek', 'Tigran', 'Gor', 'Hayk', 'Arman', 'Levon', 'Vahe', 'Ashot', 'Karen', 'Samvel', 'Erik', 'Ruben', 'Mher', 'Artur', 'Sargis', 'Hovhannes', 'Grigor', 'Edgar'];

    public const LAST = ['Petrosyan', 'Grigoryan', 'Hakobyan', 'Sargsyan', 'Avetisyan', 'Mkrtchyan', 'Karapetyan', 'Harutyunyan', 'Ghazaryan', 'Vardanyan', 'Manukyan', 'Martirosyan', 'Hovhannisyan', 'Stepanyan', 'Abrahamyan', 'Poghosyan'];

    public const TEAMS = ['Kentron FC', 'Arabkir United', 'Nork Wolves', 'Masis Eagles', 'Davtashen Stars', 'Ajapnyak Rangers', 'Erebuni 2800', 'Shengavit Storm', 'Avan Titans', 'Malatia Bulls', 'Komitas Kickers', 'Zeytun Boys'];

    public function run(): void
    {
        mt_srand(374);
        $now = now();

        // you: the only admin (change ADMIN_PHONE in .env before seeding)
        $admin = User::updateOrCreate(['phone' => env('ADMIN_PHONE', '+37499000000')], ['name' => 'Admin', 'locale' => 'en', 'phone_verified_at' => $now, 'registered_at' => $now]);
        $admin->forceFill(['is_admin' => true])->save();

        // one owner per place: +374 91 000 001 … 008
        foreach (Venue::orderBy('id')->get() as $i => $venue) {
            $owner = User::updateOrCreate(['phone' => sprintf('+3749100000%d', $i + 1)], [
                'name' => self::FIRST[$i].' '.self::LAST[$i + 4], 'locale' => 'hy', 'phone_verified_at' => $now, 'registered_at' => $now,
            ]);
            $venue->staff()->syncWithoutDetaching([$owner->id => ['role' => 'owner']]);
        }

        // players: +374 93 000 100 …
        $players = collect(range(0, 59))->map(fn ($i) => User::updateOrCreate(['phone' => sprintf('+37493000%03d', 100 + $i)], [
            'name' => self::FIRST[$i % 20].' '.self::LAST[($i * 7) % 16], 'locale' => ['hy', 'ru', 'en'][$i % 3],
            'level' => ['beginner', 'mid', 'pro'][$i % 3], 'phone_verified_at' => $now, 'registered_at' => $now,
            'username' => strtolower(self::FIRST[$i % 20]).($i + 10),
        ]));

        // bookings
        Booking::query()->delete();
        $prices = app(PriceCalculator::class);
        foreach (Field::with(['venue.openingHours', 'priceRules'])->get() as $field) {
            for ($d = -7; $d <= 14; $d++) {
                $day = LocalTime::now()->startOfDay()->addDays($d);
                $oh = $field->venue->openingHours->firstWhere('weekday', $day->dayOfWeek);
                [$open, $close] = [(int) substr($oh->opens_at, 0, 2), (int) substr($oh->closes_at, 0, 2) ?: 24];
                $big = $field->size->value === 'big';
                for ($t = $open; $t + 1 <= $close;) {
                    $prime = $t >= 18 || in_array($day->dayOfWeek, [0, 6], true);
                    $p = ($prime ? ($big ? 0.5 : 0.62) : ($big ? 0.15 : 0.28)) * ($d > 7 ? 0.5 : 1) + ($t >= 19 && $t < 22 ? 0.15 : 0);
                    if (mt_rand() / mt_getrandmax() >= $p) {
                        $t += mt_rand(0, 1) ? 0.5 : 1;

                        continue;
                    }
                    $len = $big ? (mt_rand(0, 9) < 6 ? 1.5 : 2) : (mt_rand(0, 9) < 2 ? 2 : (mt_rand(0, 9) < 2 ? 1.5 : 1));
                    $len = min($len, $close - $t);
                    $start = LocalTime::at($day, $t);
                    $player = $players[mt_rand(0, $players->count() - 1)];
                    $fromApp = mt_rand(0, 9) < 7;
                    $status = $d < 0 ? (mt_rand(0, 19) === 0 ? BookingStatus::NoShow : BookingStatus::Completed)
                        : ($d <= 3 && $fromApp && mt_rand(0, 9) < 2 ? BookingStatus::Pending : BookingStatus::Accepted);
                    if ($status === BookingStatus::Pending && $start->lessThan(LocalTime::now()->addHour())) {
                        $status = BookingStatus::Accepted;
                    }
                    Booking::create([
                        'code' => Booking::newCode(), 'venue_id' => $field->venue_id, 'field_id' => $field->id,
                        'starts_at' => $start->utc(), 'ends_at' => $start->addMinutes((int) ($len * 60))->utc(),
                        'status' => $status, 'source' => $fromApp ? BookingSource::Player : BookingSource::Owner,
                        'user_id' => $fromApp ? $player->id : null,
                        'contact_name' => $fromApp ? $player->name : self::FIRST[mt_rand(0, 19)].' '.self::LAST[mt_rand(0, 15)],
                        'contact_phone' => $fromApp ? $player->phone : sprintf('+3749%d%06d', mt_rand(1, 9), mt_rand(0, 999999)),
                        'team_name' => mt_rand(0, 9) < 4 ? self::TEAMS[mt_rand(0, 11)] : null,
                        'price' => $prices->price($field, $start, (int) ($len * 60)),
                        'decided_at' => $status === BookingStatus::Pending ? null : $start->subDay()->utc(),
                    ]);
                    $t += $len;
                }
            }
        }
    }
}
