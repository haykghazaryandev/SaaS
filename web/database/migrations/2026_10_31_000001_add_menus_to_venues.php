<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Settings → Menus: which menus the owner panel and the staff panel show, and how much staff may do (null = defaults)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', fn (Blueprint $t) => $t->json('menus')->nullable());
    }

    public function down(): void
    {
        Schema::table('venues', fn (Blueprint $t) => $t->dropColumn('menus'));
    }
};
