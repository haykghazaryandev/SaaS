<?php

namespace Database\Seeders;

use App\Models\Field;
use App\Models\OpeningHour;
use App\Models\PriceRule;
use App\Models\Venue;
use Illuminate\Database\Seeder;

/** The 8 demo places of the prototype (made up), each with a small 5×5 and a big 11×11 field. */
class VenueSeeder extends Seeder
{
    public function run(): void
    {
        $L = fn ($en, $hy, $ru) => compact('en', 'hy', 'ru');
        $places = [
            ['zangak', 'Zangak Football Park', 'arabkir', $L('12 Komitas Ave', 'Կոմիտասի պող. 12', 'пр. Комитаса, 12'), 4.8, 312, [8, 24], ['parking', 'showers', 'lockers', 'cafe', 'lights', 'bibs'],
                $L('A 5×5 field under a roof and a full-size 11×11 pitch with stands. Café with a view of both.', 'Ծածկով 5×5 դաշտ և լիաչափ 11×11 դաշտ՝ տրիբունայով։ Սրճարան՝ երկու դաշտերի տեսարանով։', 'Поле 5×5 под крышей и полноразмерное поле 11×11 с трибуной. Кафе с видом на оба поля.'),
                ['indoor' => true, 'day' => 15000, 'prime' => 20000], ['day' => 40000, 'prime' => 50000], [40.2079, 44.4953]],
            ['masis', 'Masis Football Center', 'shengavit', $L('40 Bagratunyats Ave', 'Բագրատունյաց պող. 40', 'пр. Багратуняц, 40'), 4.6, 389, [9, 24], ['parking', 'showers', 'lockers', 'lights', 'stands'],
                $L('The biggest football centre in the south of the city.', 'Քաղաքի հարավի ամենամեծ ֆուտբոլային կենտրոնը։', 'Самый большой футбольный центр на юге города.'),
                ['day' => 13000, 'prime' => 17000], ['day' => 38000, 'prime' => 48000], [40.1569, 44.4930]],
            ['davtashen', 'Davtashen Arena', 'davtashen', $L('3 Davtashen 4th Block', 'Դավթաշեն 4-րդ թաղ. 3', 'Давташен, 4-й кв., 3'), 4.7, 176, [8, 23], ['parking', 'showers', 'lockers', 'lights'],
                $L('A quiet place by the park: indoor 5×5 for winter and a natural grass big pitch.', 'Հանգիստ վայր այգու մոտ․ փակ 5×5 ձմռան համար և բնական խոտով մեծ դաշտ։', 'Тихое место у парка: крытое 5×5 на зиму и большое поле с натуральным газоном.'),
                ['indoor' => true, 'day' => 14000, 'prime' => 19000], ['surface' => 'grass', 'day' => 45000, 'prime' => 55000], [40.2268, 44.4891]],
            ['avan', 'Avan Stadium', 'avan', $L('8 Tsarav Aghbyur St', 'Ծարավ Աղբյուրի փ. 8', 'ул. Царав Ахбюр, 8'), 4.4, 97, [8, 23], ['parking', 'showers', 'bibs'],
                $L('An affordable neighbourhood stadium. Balls and bibs are free.', 'Մատչելի թաղային մարզադաշտ։ Գնդակներն ու մայկաները անվճար են։', 'Доступный районный стадион. Мячи и манишки бесплатно.'),
                ['day' => 10000, 'prime' => 13000], ['day' => 30000, 'prime' => 38000], [40.2167, 44.5822]],
            ['nork', 'Nork Football Club', 'nornork', $L('2 Gai Ave', 'Գայի պող. 2', 'пр. Гая, 2'), 4.3, 84, [10, 24], ['parking', 'showers', 'lights'],
                $L('Late games until midnight on both fields.', 'Ուշ խաղեր մինչև կեսգիշեր երկու դաշտում։', 'Поздние игры до полуночи на обоих полях.'),
                ['indoor' => true, 'day' => 14000, 'prime' => 18000], ['day' => 36000, 'prime' => 45000], [40.1946, 44.5602]],
            ['ajapnyak', 'Ajapnyak Sport Base', 'ajapnyak', $L('15 Halabyan St', 'Հալաբյանի փ. 15', 'ул. Алабяна, 15'), 4.5, 122, [8, 23], ['parking', 'lockers', 'cafe', 'stands'],
                $L('A big base with stands for 300 people, often used for amateur finals.', 'Մեծ բազա 300 հոգանոց տրիբունայով։', 'Большая база с трибуной на 300 человек.'),
                ['day' => 12000, 'prime' => 16000], ['day' => 40000, 'prime' => 50000], [40.1950, 44.4560]],
            ['kentron', 'Kentron Football Club', 'kentron', $L('27 Abovyan St', 'Աբովյանի փ. 27', 'ул. Абовяна, 27'), 4.6, 141, [9, 23], ['showers', 'lockers', 'cafe', 'lights'],
                $L('Football in the city centre, five minutes from the metro.', 'Ֆուտբոլ քաղաքի կենտրոնում, մետրոյից հինգ րոպե։', 'Футбол в центре города, пять минут от метро.'),
                ['indoor' => true, 'day' => 18000, 'prime' => 24000], ['day' => 48000, 'prime' => 58000], [40.1872, 44.5152]],
            ['malatia', 'Malatia Fields', 'malatia', $L('50 Sebastia St', 'Սեբաստիայի փ. 50', 'ул. Себастии, 50'), 4.2, 68, [9, 23], ['parking', 'bibs'],
                $L('Simple and cheap: good for regular weekly games.', 'Պարզ և էժան․ լավ է շաբաթական խաղերի համար։', 'Просто и недорого: хорошо для регулярных игр.'),
                ['day' => 9000, 'prime' => 12000], ['day' => 28000, 'prime' => 35000], [40.1747, 44.4436]],
        ];

        foreach ($places as [$slug, $name, $district, $address, $rating, $reviews, $open, $amenities, $about, $small, $big, $geo]) {
            $venue = Venue::updateOrCreate(['slug' => $slug], [
                'name' => $name, 'district' => $district, 'address' => $address, 'description' => $about,
                'lat' => $geo[0], 'lng' => $geo[1], 'rating_avg' => $rating, 'rating_count' => $reviews,
                'amenities' => $amenities, 'rules' => [], 'status' => 'active',
            ]);
            $venue->openingHours()->delete();
            foreach (range(0, 6) as $wd) {
                OpeningHour::create(['venue_id' => $venue->id, 'weekday' => $wd, 'opens_at' => sprintf('%02d:00', $open[0]), 'closes_at' => sprintf('%02d:00', $open[1])]);
            }
            foreach ([['small', $small, [60, 90, 120], 60, 0], ['big', $big, [90, 120], 90, 1]] as [$size, $p, $durations, $def, $sort]) {
                $field = Field::updateOrCreate(['venue_id' => $venue->id, 'size' => $size], [
                    'format' => $size === 'small' ? '5×5' : '11×11', 'surface' => $p['surface'] ?? 'turf', 'indoor' => $p['indoor'] ?? false,
                    'max_players' => $size === 'small' ? 10 : 22, 'durations' => $durations, 'default_duration' => $def, 'sort' => $sort, 'active' => true,
                ]);
                $field->priceRules()->delete();
                // weekdays before 18:00 = day price; weekday evenings and all weekend = prime price
                PriceRule::create(['field_id' => $field->id, 'weekdays' => [1, 2, 3, 4, 5], 'from_time' => '00:00', 'to_time' => '18:00', 'price_per_hour' => $p['day'], 'priority' => 1]);
                PriceRule::create(['field_id' => $field->id, 'weekdays' => [1, 2, 3, 4, 5], 'from_time' => '18:00', 'to_time' => '24:00', 'price_per_hour' => $p['prime'], 'priority' => 1]);
                PriceRule::create(['field_id' => $field->id, 'weekdays' => [0, 6], 'from_time' => '00:00', 'to_time' => '24:00', 'price_per_hour' => $p['prime'], 'priority' => 2]);
            }
        }
    }
}
