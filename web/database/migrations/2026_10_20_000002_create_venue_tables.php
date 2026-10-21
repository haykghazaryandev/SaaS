<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A place with (in the MVP) two fields: a small 5×5 and a big 11×11
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('district', 32)->index();
            $table->jsonb('address');                 // {"hy": "...", "ru": "...", "en": "..."}
            $table->jsonb('description')->nullable();
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->string('phone', 20)->nullable();
            $table->decimal('rating_avg', 2, 1)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->jsonb('amenities')->default('[]');
            $table->jsonb('rules')->default('{}');    // see App\Models\Venue::DEFAULT_RULES
            $table->string('status', 16)->default('active'); // draft | active | paused
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->string('size', 8);                // small | big
            $table->string('format', 8);              // 5×5 | 11×11
            $table->string('surface', 8)->default('turf'); // turf | grass
            $table->boolean('indoor')->default(false);
            $table->unsignedTinyInteger('max_players');
            $table->jsonb('durations');               // [60, 90, 120]
            $table->unsignedSmallInteger('default_duration');
            $table->unsignedTinyInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['venue_id', 'size']);
        });

        Schema::create('opening_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');   // 0 = Sunday … 6 = Saturday
            $table->time('opens_at');
            $table->time('closes_at');                // '24:00' is allowed (Postgres time accepts 24:00:00)
            $table->unique(['venue_id', 'weekday']);
        });

        // Price per hour by time band, e.g. Mon–Fri 08–18 = 15 000, evenings and weekends = 20 000
        Schema::create('price_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_id')->constrained()->cascadeOnDelete();
            $table->jsonb('weekdays');                // [1,2,3,4,5]
            $table->time('from_time');
            $table->time('to_time');
            $table->unsignedInteger('price_per_hour');
            $table->unsignedTinyInteger('priority')->default(0);
            $table->timestamps();
        });

        Schema::create('closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_id')->nullable()->constrained()->cascadeOnDelete(); // null = whole place
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Owners and staff; owner accounts are created by the admin only
        Schema::create('venue_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 8);                // owner | staff
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['venue_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['venue_users', 'closures', 'price_rules', 'opening_hours', 'fields', 'venues'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
