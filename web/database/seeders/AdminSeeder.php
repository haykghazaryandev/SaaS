<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/** You: the admin of the Filament panel, by ADMIN_PHONE (set it before seeding). Safe to run again. */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $admin = User::firstOrCreate(['phone' => config('app.admin_phone') ?: '+37499000000'], ['name' => 'Admin', 'locale' => 'en', 'phone_verified_at' => $now, 'registered_at' => $now]);
        $admin->forceFill(['is_admin' => true])->save();
        // local / staging: the admin also owns the demo place Zangak, to try the owner panel and its Telegram messages
        $zangak = app()->isProduction() ? null : \App\Models\Venue::where('slug', 'zangak')->first();
        if ($zangak && ! $admin->venues()->exists()) {
            $zangak->staff()->syncWithoutDetaching([$admin->id => ['role' => 'owner']]);
        }
        $this->command?->info("Admin: {$admin->phone}");
    }
}
