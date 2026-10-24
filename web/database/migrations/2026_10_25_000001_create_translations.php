<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interface texts (hy, ru, en) live in the database. TranslationSeeder fills the table from
 * database/seeders/data/translations.json; the admin panel edits them; `translations:export` writes them back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('group', 40)->index();       // the first part of the key ("player"), or "*" for keys without a dot
            $table->string('key', 191)->unique();       // the key used in code: player.size.small, nav.fields, brand
            $table->text('en');                         // English is the source language, always present
            $table->text('hy')->nullable();             // empty = English is shown
            $table->text('ru')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
