<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * php artisan db:seed (add --force on staging). Every seeder only adds what is missing,
 * so it is safe to run again on a database with real data.
 * Production gets the translations and the admin; the made-up places, people and games are for local and staging.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(TranslationSeeder::class);   // the interface texts, in every environment
        if (app()->isProduction()) {
            $this->call(AdminSeeder::class);
            $this->command?->warn('Production: demo places, people and games are not seeded.');

            return;
        }
        $this->call([VenueSeeder::class, AdminSeeder::class, DemoSeeder::class, SocialSeeder::class, PingpongSeeder::class]);
    }
}
