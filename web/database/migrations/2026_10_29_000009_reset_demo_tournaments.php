<?php

use App\Models\Tournament;
use App\Models\Venue;
use Illuminate\Database\Migrations\Migration;

/**
 * Local / staging, once: the old demo tournaments of the demo clubs go away; TournamentDemoSeeder then makes the new
 * set (every tournament with divisions). Never in production.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->isProduction()) {
            return;
        }
        $ids = Venue::whereIn('slug', ['pingpong-land', 'spin-club'])->pluck('id');
        Tournament::whereIn('venue_id', $ids)->whereNull('parent_id')->get()->each->delete();
        Tournament::whereIn('venue_id', $ids)->get()->each->delete();
    }

    public function down(): void {}
};
