<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Now (the desk) became its own feature; places that had it through cards or the shop keep it
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('venues')->whereNotNull('features')->get(['id', 'features']) as $v) {
            $f = json_decode($v->features, true) ?: [];
            if (! in_array('desk', $f, true) && array_intersect(['wallet', 'shop'], $f)) {
                $f[] = 'desk';
                DB::table('venues')->where('id', $v->id)->update(['features' => json_encode(array_values($f))]);
            }
        }
    }

    public function down(): void {}
};
