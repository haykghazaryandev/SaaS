<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Tournaments finished before their chat was closed at the end: read-only now (kept, not deleted). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('conversations')->whereIn('tournament_id', DB::table('tournaments')->where('status', 'done')->select('id'))
            ->where('status', '!=', 'archived')->update(['status' => 'archived', 'archived_at' => now(), 'delete_after' => null]);
    }

    public function down(): void {}
};
