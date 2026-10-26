<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The club features a place can switch on (venues.features): a player card per place (QR, balance, discount),
 * the money the place takes, ratings per sport, trainings, a shop and tournaments.
 */
return new class extends Migration
{
    public function up(): void
    {
        // one card per player per place: its QR only works at that place
        Schema::create('venue_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32)->unique();              // in the QR: random, not the ids
            $table->integer('balance')->default(0);            // ֏, kept equal to the sum of card_entries
            $table->unsignedTinyInteger('discount_pct')->default(0);
            $table->string('note', 200)->nullable();           // the place's own note about the player
            $table->timestamps();
            $table->unique(['venue_id', 'user_id']);
        });

        // every sale or top-up the place records (booking, training, shop, tournament, top-up), paid by card or cash
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('card_id')->nullable()->constrained('venue_cards')->nullOnDelete();
            $table->string('kind', 16);                        // booking | training | shop | tournament | topup | other
            $table->string('method', 8);                       // card | cash
            $table->integer('amount');                         // ֏ (after the discount)
            $table->integer('discount')->default(0);           // ֏ taken off
            $table->unsignedBigInteger('ref_id')->nullable();  // the booking / training visit / sale / tournament entry
            $table->string('note', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['venue_id', 'created_at']);
            $table->index(['kind', 'ref_id']);
        });

        // the card's history: top-ups (+), payments from the card (−), cashback (+), corrections
        Schema::create('card_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('venue_cards')->cascadeOnDelete();
            $table->integer('amount');
            $table->string('kind', 12);                        // topup | pay | cashback | adjust
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // a player's rating in one sport (ping-pong and football apart), set by the staff of a place
        Schema::create('sport_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('sport', 16);
            $table->unsignedSmallInteger('rating')->nullable();   // points, e.g. 1450
            $table->string('intl_id', 40)->nullable();            // the player's number in the international rating
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();   // who set it last
            $table->timestamps();
            $table->unique(['user_id', 'sport']);
        });
        Schema::create('rating_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('sport', 16);
            $table->unsignedSmallInteger('old')->nullable();
            $table->unsignedSmallInteger('new')->nullable();
            $table->string('note', 200)->nullable();
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('trainings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->string('title', 80);
            $table->string('coach', 80)->nullable();
            $table->jsonb('weekdays');                         // [1, 3, 5]
            $table->time('starts_at');
            $table->unsignedSmallInteger('minutes');
            $table->unsignedInteger('price');                  // one visit
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('note', 300)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('training_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['training_id', 'user_id', 'date']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('description', 300)->nullable();
            $table->unsignedInteger('price');
            $table->unsignedTinyInteger('discount_pct')->default(0);
            $table->boolean('in_stock')->default(true);
            $table->boolean('active')->default(true);
            $table->string('photo_token', 16)->nullable();     // part of the photo URL (a new photo, a new URL)
            $table->text('photo')->nullable();                 // base64 WebP (no files: they are lost on deploys)
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name', 80);                // kept if the product is removed
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('qty');
            $table->unsignedInteger('price_each');
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->string('sport', 16);
            $table->string('title', 100);
            $table->string('code', 16)->unique();              // the registration link /t/{code}
            $table->timestampTz('starts_at');
            $table->string('description', 1000)->nullable();
            $table->jsonb('options');                          // up to 3: [{"label": "Free", "price": 0}, …]
            $table->string('status', 10)->default('open');     // open | closed | done
            $table->timestamps();
        });
        Schema::create('tournament_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('first_name', 60);
            $table->string('last_name', 60);
            $table->string('phone', 20);
            $table->unsignedSmallInteger('level')->nullable();   // the player's own level / rating points
            $table->unsignedTinyInteger('option');               // index into tournaments.options
            $table->string('status', 10)->default('applied');    // applied | accepted | declined
            $table->unsignedSmallInteger('place')->nullable();   // result: 1st, 2nd …
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['tournament_id', 'phone']);
        });
    }

    public function down(): void
    {
        foreach (['tournament_entries', 'tournaments', 'sales', 'products', 'training_visits', 'trainings', 'rating_changes', 'sport_ratings', 'card_entries', 'payments', 'venue_cards'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
