<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Club cards have cashback, not discounts: new texts for the card pitch, "My cards" and the "card is ready" message,
 * where nobody edited them (the translation seeder never overwrites a row).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }
        $texts = [
            'club.card_pitch' => ['Balance, discounts and your rating, with one QR',
                ['en' => 'Balance, cashback and your rating, with one QR', 'hy' => 'Հաշիվ, քեշբեք և ձեր ռեյթինգը՝ մեկ QR-ով', 'ru' => 'Баланс, кэшбэк и ваш рейтинг — по одному QR']],
            'club.my_cards_sub' => ['One card per club. Show its QR at the desk: they top up your balance, take payments and give your discount. A card works only at its own club.',
                ['en' => 'One card per club. Show its QR at the desk: they top up your balance and take payments, and part of what you pay comes back as cashback. A card works only at its own club.', 'hy' => 'Մեկ քարտ յուրաքանչյուր ակումբի համար։ Ցույց տվեք QR-ը ընդունարանում․ այնտեղ կլիցքավորեն հաշիվը և կգանձեն վճարումները, իսկ վճարածի մի մասը կվերադառնա որպես քեշբեք։ Քարտը գործում է միայն իր ակումբում։', 'ru' => 'По одной карте на клуб. Покажите её QR на ресепшене: там пополнят баланс и примут оплату, а часть оплаченного вернётся кэшбэком. Карта действует только в своём клубе.']],
            'notice.card_created' => ['💳 Your card at :venue is ready. Show its QR at the desk: Profile → My cards (balance, discount, history).',
                ['en' => '💳 Your card at :venue is ready. Show its QR at the desk: Profile → My cards (balance, cashback, history).', 'hy' => '💳 Ձեր քարտը :venue-ում պատրաստ է։ Ցույց տվեք QR-ը ընդունարանում․ Պրոֆիլ → Իմ քարտերը (հաշիվ, քեշբեք, պատմություն)։', 'ru' => '💳 Ваша карта в :venue готова. Покажите QR на ресепшене: Профиль → Мои карты (баланс, кэшбэк, история).']],
        ];
        foreach ($texts as $key => [$old, $new]) {
            DB::table('translations')->where('key', $key)->where('en', $old)->update($new + ['updated_at' => now()]);
        }
    }

    public function down(): void {}
};
