<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Many sports on one platform (App\Enums\Sport):
 *  - sports: which sports players see on the home page (the admin switches them on and off)
 *  - venues.listed: the place is shown on the home page; venues.features: what the place offers (null = everything)
 *  - fields.name: "Table 3"; a place may now have many courts of one kind (ping-pong tables), booked as "any free one"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports', function (Blueprint $table) {
            $table->string('key', 16)->primary();      // football | pingpong
            $table->boolean('visible')->default(true);  // shown on the home page
            $table->unsignedTinyInteger('sort')->default(0);
            $table->timestamps();
        });
        DB::table('sports')->insert([
            ['key' => 'football', 'visible' => true, 'sort' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'pingpong', 'visible' => true, 'sort' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::table('venues', function (Blueprint $table) {
            $table->boolean('listed')->default(true)->after('status');
            $table->jsonb('features')->nullable()->after('rules');
        });

        Schema::table('fields', function (Blueprint $table) {
            $table->string('name', 40)->nullable()->after('venue_id');
            $table->dropUnique(['venue_id', 'size']);
            $table->index(['venue_id', 'size']);
        });
    }

    public function down(): void
    {
        Schema::table('fields', function (Blueprint $table) {
            $table->dropIndex(['venue_id', 'size']);
            $table->unique(['venue_id', 'size']);
            $table->dropColumn('name');
        });
        Schema::table('venues', function (Blueprint $table) {
            $table->dropColumn(['listed', 'features']);
        });
        Schema::dropIfExists('sports');
    }
};
