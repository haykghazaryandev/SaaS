<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Club cards: no discount per card any more. Cashback grows by itself with what the card has paid at the place
 * (venues.rules.cashback_tiers, default 3% from 50 000 ֏, 5% from 150 000 ֏, 7% from 300 000 ֏).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_cards', fn (Blueprint $t) => $t->dropColumn('discount_pct'));
        DB::statement("update venues set rules = rules - 'cashback_pct' where rules is not null");
        // the admin's feature texts said "discount": new texts where nobody edited them (the seeder never overwrites)
        if (Schema::hasTable('translations')) {
            $texts = [
                'admin.feature.wallet' => ['Player cards (QR, balance, discount)',
                    ['en' => 'Player cards (QR, balance, cashback)', 'hy' => 'Խաղացողի քարտեր (QR, հաշիվ, քեշբեք)', 'ru' => 'Карты игроков (QR, баланс, кэшбэк)']],
                'admin.feature.wallet_help' => ['A card per player with a QR: top-ups, payments, discounts, cashback',
                    ['en' => 'A card per player with a QR: top-ups, payments, cashback that grows with what the card has paid',
                        'hy' => 'Քարտ յուրաքանչյուր խաղացողի համար՝ QR-ով․ լիցքավորում, վճարումներ, քեշբեք, որն աճում է քարտով վճարածի հետ',
                        'ru' => 'Карта на игрока с QR: пополнения, оплата, кэшбэк, который растёт с суммой оплат картой']],
            ];
            foreach ($texts as $key => [$old, $new]) {
                DB::table('translations')->where('key', $key)->where('en', $old)->update($new + ['updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('venue_cards', fn (Blueprint $t) => $t->unsignedTinyInteger('discount_pct')->default(0));
    }
};
