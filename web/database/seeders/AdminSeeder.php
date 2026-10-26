<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Venue;
use App\Support\Phone;
use Illuminate\Database\Seeder;

/**
 * The admins of the Filament panel: ADMIN_PHONE, one number or several separated by commas (set it before seeding).
 * Local / staging: each admin also owns demo places to try the owner panel: the first Zangak (football),
 * the second Masis (football); their ping-pong clubs come in PingpongSeeder. Safe to run again.
 */
class AdminSeeder extends Seeder
{
    /** The football place each admin owns on local / staging, by their place in ADMIN_PHONE. */
    public const FOOTBALL = ['zangak', 'masis'];

    public function run(): void
    {
        $now = now();
        foreach (self::phones() as $i => $phone) {
            $admin = User::firstOrCreate(['phone' => $phone], ['name' => $i ? 'Admin '.($i + 1) : 'Admin', 'locale' => 'en', 'phone_verified_at' => $now, 'registered_at' => $now]);
            $admin->forceFill(['is_admin' => true])->save();
            $place = app()->isProduction() ? null : Venue::where('slug', self::FOOTBALL[$i] ?? '')->first();
            if ($place) {
                $place->staff()->syncWithoutDetaching([$admin->id => ['role' => 'owner']]);
            }
            $this->command?->info("Admin: {$admin->phone}");
        }
    }

    /** ADMIN_PHONE as normalized numbers, in order (the default for a fresh computer: +374 99 000 000). */
    public static function phones(): array
    {
        $raw = (string) (config('app.admin_phone') ?: '+37499000000');

        return array_values(array_unique(array_filter(array_map(fn ($p) => Phone::normalize(trim($p)), explode(',', $raw)))));
    }
}
