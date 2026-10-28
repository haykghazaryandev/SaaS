<?php

use App\Support\Translations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The booking confirmation and the day-before reminder tell the player that a no-show is noted at the place.
 * Only texts nobody edited in the admin are changed (the seeder only adds missing keys).
 */
return new class extends Migration
{
    private const TEXTS = [
        'notice.booking_accepted' => [
            'en' => ['✅ Confirmed: :venue :field, :date :time. Code :code.', "✅ Confirmed: :venue :field, :date :time. Code :code. Can't come? Cancel in time: a no-show is noted at this place, and next time the place may give the time to a player who always comes."],
            'hy' => ['✅ Հաստատված է՝ :venue :field, :date :time։ Կոդ՝ :code։', '✅ Հաստատված է՝ :venue :field, :date :time։ Կոդ՝ :code։ Չե՞ք կարող գալ։ Չեղարկեք ժամանակին․ չգալը նշվում է այս վայրում, և հաջորդ անգամ վայրը կարող է ժամը տալ այն խաղացողին, ով միշտ գալիս է։'],
            'ru' => ['✅ Подтверждено: :venue :field, :date :time. Код :code.', '✅ Подтверждено: :venue :field, :date :time. Код :code. Не сможете прийти? Отмените заранее: неявка отмечается в этом заведении, и в следующий раз время могут отдать игроку, который всегда приходит.'],
        ],
        'notice.booking_reminder_day' => [
            'en' => ['⚽ Tomorrow at :start: :venue, :field (:time). Booking :code.', "⚽ Tomorrow at :start: :venue, :field (:time). Booking :code. Can't come? Cancel now: a no-show is noted at this place."],
            'hy' => ['⚽ Վաղը :start-ին՝ :venue, :field (:time)։ Ամրագրում :code։', '⚽ Վաղը :start-ին՝ :venue, :field (:time)։ Ամրագրում :code։ Չե՞ք կարող գալ։ Չեղարկեք հիմա․ չգալը նշվում է այս վայրում։'],
            'ru' => ['⚽ Завтра в :start: :venue, :field (:time). Бронь :code.', '⚽ Завтра в :start: :venue, :field (:time). Бронь :code. Не сможете прийти? Отмените сейчас: неявка отмечается в этом заведении.'],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }
        foreach (self::TEXTS as $key => $langs) {
            foreach ($langs as $locale => [$from, $to]) {
                // one column per language (en / hy / ru)
                DB::table('translations')->where('key', $key)->where($locale, $from)->update([$locale => $to]);
            }
        }
        app(Translations::class)->flush();
    }

    public function down(): void {}
};
