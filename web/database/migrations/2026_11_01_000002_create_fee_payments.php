<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Admin → ZoneSpot fee: a place paid ZoneSpot's fee for a month (the amount owed then, when, a note, who marked it)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $t->date('month');                 // the first day of the month paid for
            $t->unsignedBigInteger('amount');  // the fee owed for that month when it was marked
            $t->timestamp('paid_at');
            $t->string('note', 200)->nullable();
            $t->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['venue_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_payments');
    }
};
