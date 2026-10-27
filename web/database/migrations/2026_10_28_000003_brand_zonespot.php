<?php

use App\Support\Translations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The brand is ZoneSpot now (was Bookball): the name in every text of the table, also in the ones the admin edited
 * (the translation seeder never overwrites a row). The bot's @username and the bookball.am address stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }
        DB::table('translations')
            ->where(fn ($q) => $q->where('en', 'like', '%Bookball%')->orWhere('hy', 'like', '%Bookball%')->orWhere('ru', 'like', '%Bookball%'))
            ->orderBy('id')->get(['id', 'en', 'hy', 'ru'])
            ->each(fn ($row) => DB::table('translations')->where('id', $row->id)->update([
                'en' => $row->en === null ? null : str_replace('Bookball', 'ZoneSpot', $row->en),
                'hy' => $row->hy === null ? null : str_replace('Bookball', 'ZoneSpot', $row->hy),
                'ru' => $row->ru === null ? null : str_replace('Bookball', 'ZoneSpot', $row->ru),
                'updated_at' => now(),
            ]));
        app(Translations::class)->flush();
    }

    public function down(): void {}
};
