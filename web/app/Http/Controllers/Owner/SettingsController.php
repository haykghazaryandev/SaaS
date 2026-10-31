<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Club\Cards;
use App\Domain\Venues\Courts;
use App\Domain\Venues\Menus;
use App\Domain\Venues\PriceCalculator;
use App\Enums\FieldSize;
use App\Enums\Sport;
use App\Models\Booking;
use App\Models\Closure;
use App\Models\Field;
use App\Models\OpeningHour;
use App\Models\PriceRule;
use App\Models\Venue;
use App\Support\LocalTime;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Settings of the place: prices, opening hours, booking rules and closures.
 * Everybody of the place can see them; only the owner role can change them. Name and address are changed by the Bookball admin.
 */
class SettingsController extends OwnerController
{
    /** Rules the owner can change (venues.rules) with their limits. */
    public const RULES = [
        'min_notice_min' => 'required|integer|min:0|max:2880',
        'request_expire_min' => 'required|integer|min:15|max:2880',
        'auto_accept_regulars' => 'required|boolean',
        'late_grace_min' => 'sometimes|integer|min:0|max:60',   // "team not here?" this long after the start
        'cashback_tiers' => 'sometimes|array|max:8',            // club cards: [{from: 50000, pct: 3}, …] (Cards::level)
    ];

    /** The cashback levels, item by item. */
    private const TIER_RULES = [
        'cashback_tiers.*.from' => 'required|integer|min:0|max:100000000',
        'cashback_tiers.*.pct' => 'required|integer|min:0|max:50',
    ];

    public function show(Request $request, PriceCalculator $prices)
    {
        $venue = $this->venue($request)->load(['fields.priceRules', 'openingHours']);
        $local = LocalTime::now()->startOfDay();
        // sample moments for the three price bands: weekday day, weekday evening, weekend
        $wed = $local->next(CarbonInterface::WEDNESDAY);
        $sat = $local->next(CarbonInterface::SATURDAY);

        return Inertia::render('owner/Settings', [
            'canEdit' => $this->isOwner($request),
            'place' => [
                'name' => $venue->name,
                'address' => $venue->tr('address'),
                'district' => $venue->district,
                'phone' => $venue->phone ? Phone::format($venue->phone) : null,
                'tel' => $venue->phone,
                'status' => $venue->status,
            ],
            // one price row per kind of court: a price for "Table" applies to every table
            'prices' => $venue->fields->groupBy(fn (Field $f) => $f->size->value)->map(function ($group) use ($venue, $prices, $wed, $sat) {
                $f = $group->sortBy(fn (Field $x) => [$x->sort, $x->id])->first();

                return [
                    'field_id' => $f->id,
                    'label' => $group->count() > 1 ? __('owner.courts.kind_'.$f->size->value) : BookingPresenter::fieldLabel($f),
                    'count' => $group->count(),
                    'sport' => $f->sport()->value,
                    // football fields have a surface; tables don't
                    'surface' => $f->sport() === Sport::Football ? $f->surface : null,
                    'indoor' => $f->indoor,
                    'day' => $prices->hourlyAt($f->priceRules, LocalTime::at($wed, 12)),
                    'evening' => $prices->hourlyAt($f->priceRules, LocalTime::at($wed, 20)),
                    'weekend' => $prices->hourlyAt($f->priceRules, LocalTime::at($sat, 12)),
                    // a training on this kind: one price per hour, or prices by length (empty: no trainings)
                    'training' => ($venue->rules['training_prices'] ?? [])[$f->size->value] ?? null,
                    'training_lengths' => $venue->trainingLengths($f->size->value),
                    // prices by length (15 min / 30 min / 1 h …): when set, they replace the hourly prices of this kind
                    'lengths' => $venue->lengthPrices($f->size->value),
                ];
            })->values(),
            // the courts of each kind: add, rename, switch off, remove (Courts)
            'courts' => $venue->fields->groupBy(fn (Field $f) => $f->size->value)->map(fn ($group, $size) => [
                'size' => $size,
                'sport' => $group->first()->sport()->value,
                'label' => __('owner.courts.kind_'.$size),
                'items' => $group->sortBy(fn (Field $x) => [$x->sort, $x->id])->values()->map(fn (Field $f) => [
                    'id' => $f->id, 'name' => $f->displayName(), 'label' => BookingPresenter::fieldLabel($f), 'active' => $f->active,
                ]),
            ])->values(),
            'hours' => collect(range(0, 6))->map(function ($wd) use ($venue) {
                $oh = $venue->openingHours->firstWhere('weekday', $wd);

                return ['weekday' => $wd, 'open' => $oh ? substr($oh->opens_at, 0, 5) : null, 'close' => $oh ? substr($oh->closes_at, 0, 5) : null];
            }),
            'rules' => collect(array_keys(self::RULES))->mapWithKeys(fn ($k) => [$k => $venue->rule($k)]),
            'closures' => Closure::query()->where('venue_id', $venue->id)->where('ends_at', '>', now())->orderBy('starts_at')->get()
                ->map(fn (Closure $c) => [
                    'id' => $c->id,
                    'field' => $c->field_id ? BookingPresenter::fieldLabel($venue->fields->firstWhere('id', $c->field_id)) : null,
                    'from' => $c->starts_at->setTimezone(LocalTime::tz())->format('Y-m-d H:i'),
                    'to' => $c->ends_at->setTimezone(LocalTime::tz())->format('Y-m-d H:i'),
                    'reason' => $c->reason,
                ])->values(),
        ]);
    }

    /**
     * Settings → Menus: which menus the owner panel and the staff panel show, and staff's level on Schedule, Shop,
     * Tournaments and Money. Staff keep at least one menu of the place (they need somewhere to land).
     */
    public function menus(Request $request)
    {
        $this->ensureOwner($request);
        $venue = $this->venue($request);
        $rules = [];
        foreach (Menus::KEYS as $k) {
            $rules["owner.$k"] = 'required|boolean';
            $rules["staff.$k"] = 'required|boolean';
        }
        foreach (Menus::LEVELS as $k => $levels) {
            $rules["level.$k"] = ['required', Rule::in($levels)];
        }
        $data = $request->validate($rules);
        if (! collect(Menus::available($venue))->contains(fn ($k) => $data['staff'][$k])) {
            throw ValidationException::withMessages(['staff' => __('owner.menus.staff_none')]);
        }
        $venue->update(['menus' => [
            'owner' => array_map('boolval', $data['owner']),
            'staff' => array_map('boolval', $data['staff']),
            'level' => $data['level'],
        ]]);

        return back()->with('success', __('owner.flash.saved'));
    }

    /** Three prices per field: weekdays before 18:00, weekday evenings, weekends. Replaces the field's price rules. */
    public function prices(Request $request)
    {
        $this->ensureOwner($request);
        $venue = $this->venue($request)->load('fields');
        $data = $request->validate($this->priceRules($venue), $this->priceMessages());
        DB::transaction(fn () => $this->savePrices($venue, $data['prices']));

        return back()->with('success', __('owner.flash.prices_saved'));
    }

    /** Opening hours per weekday; a day without times is closed. Closing may be 24:00. */
    public function hours(Request $request)
    {
        $this->ensureOwner($request);
        $venue = $this->venue($request);
        $data = $request->validate($this->hourRules());
        $this->checkHours($data['hours']);
        DB::transaction(fn () => $this->saveHours($venue, $data['hours']));

        return back()->with('success', __('owner.flash.saved'));
    }

    /** The schedule's colours per state (empty: the default ones). */
    public function rules(Request $request)
    {
        $this->ensureOwner($request);
        $venue = $this->venue($request);
        $data = $request->validate(self::RULES + self::TIER_RULES);
        $this->saveRules($venue, $data);

        return back()->with('success', __('owner.flash.saved'));
    }

    /**
     * One "Save changes" for the whole page: any of prices, hours, rules and the place phone, checked first and saved together.
     * Errors keep the keys of the single endpoints ("prices.0.day", "hours.3.close", "rules.min_notice_min", "phone").
     */
    public function save(Request $request)
    {
        $this->ensureOwner($request);
        $venue = $this->venue($request)->load('fields');
        $rules = ['phone' => 'sometimes|nullable|string|max:20'];
        if ($request->has('prices')) {
            $rules += $this->priceRules($venue);
        }
        if ($request->has('hours')) {
            $rules += $this->hourRules();
        }
        if ($request->has('rules')) {
            $rules['rules'] = 'array';
            foreach (self::RULES + self::TIER_RULES as $k => $r) {
                $rules["rules.$k"] = $r;
            }
        }
        $data = $request->validate($rules, $this->priceMessages());
        if (isset($data['hours'])) {
            $this->checkHours($data['hours']);
        }
        $phone = null;
        if (array_key_exists('phone', $data) && filled($data['phone'])) {
            $phone = Phone::normalize($data['phone']);
            if (! $phone) {
                throw ValidationException::withMessages(['phone' => __('owner.settings.bad_phone')]);
            }
        }
        DB::transaction(function () use ($venue, $data, $phone) {
            if (isset($data['prices'])) {
                $this->savePrices($venue, $data['prices']);
            }
            if (isset($data['hours'])) {
                $this->saveHours($venue, $data['hours']);
            }
            if (isset($data['rules'])) {
                $this->saveRules($venue, $data['rules']);
            }
            if (array_key_exists('phone', $data)) {
                $venue->forceFill(['phone' => $phone])->save();
            }
        });

        return back()->with('success', __('owner.flash.settings_saved'));
    }

    // ---------- parts shared by the single endpoints and "Save changes" ----------

    private function priceRules(Venue $venue): array
    {
        return [
            'prices' => 'required|array',
            'prices.*.field_id' => ['required', 'integer', Rule::in($venue->fields->pluck('id')->all())],
            'prices.*.day' => 'required|integer|min:0|max:1000000',
            'prices.*.evening' => 'required|integer|min:0|max:1000000',
            'prices.*.weekend' => 'required|integer|min:0|max:1000000',
            'prices.*.training' => 'nullable|integer|min:0|max:1000000',
            'prices.*.lengths' => 'sometimes|array|max:10',
            'prices.*.lengths.*.min' => 'required|integer|min:5|max:720',
            'prices.*.lengths.*.day' => 'required|integer|min:0|max:1000000',
            'prices.*.lengths.*.evening' => 'nullable|integer|min:0|max:1000000',
            'prices.*.lengths.*.weekend' => 'nullable|integer|min:0|max:1000000',
            'prices.*.training_lengths' => 'sometimes|array|max:10',
            'prices.*.training_lengths.*.min' => 'required|integer|min:5|max:720',
            'prices.*.training_lengths.*.day' => 'required|integer|min:0|max:1000000',
        ];
    }

    private function priceMessages(): array
    {
        return ['prices.*.*' => __('owner.form.bad_price')];
    }

    /** A row's prices go to every court of its kind (all tables cost the same). */
    private function savePrices(Venue $venue, array $prices): void
    {
        $training = $venue->rules['training_prices'] ?? [];
        $lengths = $venue->rules['length_prices'] ?? [];
        $trainingLengths = $venue->rules['training_lengths'] ?? [];
        foreach ($prices as $p) {
            $size = $venue->fields->firstWhere('id', (int) $p['field_id'])->size;
            if (array_key_exists('lengths', $p)) {
                $rows = Venue::cleanLengths($p['lengths'] ?? []);
                $lengths[$size->value] = $rows;
                // the lengths with a price are the lengths players book
                if ($rows) {
                    $mins = array_column($rows, 'min');
                    foreach ($venue->fields->where('size', $size) as $court) {
                        $court->update(['durations' => $mins, 'default_duration' => in_array($court->default_duration, $mins, true) ? $court->default_duration
                            : (in_array(60, $mins, true) ? 60 : $mins[0])]);
                    }
                }
            }
            if (array_key_exists('training_lengths', $p)) {
                $trainingLengths[$size->value] = Venue::cleanLengths($p['training_lengths'] ?? []);
            }
            if (array_key_exists('training', $p)) {
                $training[$size->value] = $p['training'] === null || $p['training'] === '' ? null : (int) $p['training'];
            }
            foreach ($venue->fields->where('size', $size) as $court) {
                PriceRule::where('field_id', $court->id)->delete();
                $base = ['field_id' => $court->id, 'weekdays' => [1, 2, 3, 4, 5]];
                PriceRule::create([...$base, 'from_time' => '00:00', 'to_time' => '18:00', 'price_per_hour' => $p['day'], 'priority' => 1]);
                PriceRule::create([...$base, 'from_time' => '18:00', 'to_time' => '24:00', 'price_per_hour' => $p['evening'], 'priority' => 1]);
                PriceRule::create(['field_id' => $court->id, 'weekdays' => [0, 6], 'from_time' => '00:00', 'to_time' => '24:00', 'price_per_hour' => $p['weekend'], 'priority' => 2]);
            }
        }
        $venue->forceFill(['rules' => [...($venue->rules ?? []), 'training_prices' => array_filter($training, fn ($v) => $v !== null),
            'length_prices' => array_filter($lengths), 'training_lengths' => array_filter($trainingLengths)]])->save();
    }

    // ---------- courts: add / rename / switch off / remove (owner only) ----------

    public function storeCourt(Request $request, Courts $courts)
    {
        $this->ensureOwner($request);
        $venue = $this->venue($request);
        $data = $request->validate(['size' => ['required', Rule::in($venue->fields()->pluck('size')->unique()->all())], 'name' => 'nullable|string|max:40']);
        $this->attempt(fn () => $courts->add($venue, FieldSize::from($data['size']), $data['name'] ?? null));

        return back()->with('success', __('owner.courts.added'));
    }

    public function updateCourt(Request $request, Field $field, Courts $courts)
    {
        $this->ensureOwner($request);
        abort_unless($field->venue_id === $this->venue($request)->id, 403);
        $data = $request->validate(['name' => 'sometimes|nullable|string|max:40', 'active' => 'sometimes|boolean']);
        $this->attempt(fn () => $courts->update($field, $data));

        return back()->with('success', __('owner.flash.saved'));
    }

    public function destroyCourt(Request $request, Field $field, Courts $courts)
    {
        $this->ensureOwner($request);
        abort_unless($field->venue_id === $this->venue($request)->id, 403);
        $how = $this->attempt(fn () => $courts->remove($field));

        return back()->with('success', __($how === 'off' ? 'owner.courts.switched_off' : 'owner.courts.removed'));
    }

    private function hourRules(): array
    {
        $time = ['nullable', 'regex:/^(([01]\d|2[0-3]):(00|15|30|45)|24:00)$/'];

        return [
            'hours' => 'required|array|size:7',
            'hours.*.weekday' => 'required|integer|between:0,6|distinct',
            'hours.*.open' => $time,
            'hours.*.close' => $time,
        ];
    }

    private function checkHours(array $hours): void
    {
        foreach ($hours as $i => $h) {
            $open = $h['open'] ?? null;
            $close = $h['close'] ?? null;
            if (($open === null) !== ($close === null) || ($open !== null && OpeningHour::toHours($close) <= OpeningHour::toHours($open)) || $open === '24:00') {
                throw ValidationException::withMessages(["hours.$i.close" => __('owner.settings.hours_bad')]);
            }
        }
    }

    private function saveHours(Venue $venue, array $hours): void
    {
        foreach ($hours as $h) {
            if (empty($h['open'])) {
                OpeningHour::where('venue_id', $venue->id)->where('weekday', $h['weekday'])->delete();
            } else {
                OpeningHour::updateOrCreate(['venue_id' => $venue->id, 'weekday' => $h['weekday']], ['opens_at' => $h['open'], 'closes_at' => $h['close']]);
            }
        }
    }

    private function saveRules(Venue $venue, array $data): void
    {
        $data = array_intersect_key($data, self::RULES);
        $data['auto_accept_regulars'] = (bool) $data['auto_accept_regulars'];
        if (array_key_exists('cashback_tiers', $data)) {
            $data['cashback_tiers'] = Cards::cleanTiers($data['cashback_tiers']);
        }
        foreach (['min_notice_min', 'request_expire_min', 'late_grace_min'] as $k) {
            if (array_key_exists($k, $data)) {
                $data[$k] = (int) $data[$k];
            }
        }
        $venue->forceFill(['rules' => [...($venue->rules ?? []), ...$data]])->save();
    }

    /** Close a field (or the whole place) for a time. Existing bookings are kept; the owner is told how many overlap. */
    public function storeClosure(Request $request)
    {
        $this->ensureOwner($request);
        $venue = $this->venue($request)->load('fields');
        $data = $request->validate([
            'field_id' => ['nullable', 'integer', Rule::in($venue->fields->pluck('id')->all())],
            'from' => 'required|date_format:Y-m-d\TH:i',
            'to' => 'required|date_format:Y-m-d\TH:i',
            'reason' => 'nullable|string|max:255',
        ]);
        $from = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['from'], LocalTime::tz());
        $to = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['to'], LocalTime::tz());
        if ($to->lessThanOrEqualTo($from)) {
            throw ValidationException::withMessages(['to' => __('common.end_after_start')]);
        }
        if ($to->isPast()) {
            throw ValidationException::withMessages(['to' => __('owner.settings.closure_past')]);
        }
        Closure::create([
            'venue_id' => $venue->id, 'field_id' => $data['field_id'] ?? null,
            'starts_at' => $from->utc(), 'ends_at' => $to->utc(), 'reason' => $data['reason'] ?? null, 'created_by' => $request->user()->id,
        ]);
        $overlap = Booking::query()->holding()->where('venue_id', $venue->id)
            ->when($data['field_id'] ?? null, fn ($q, $f) => $q->where('field_id', $f))
            ->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())->count();
        $msg = __('owner.flash.closure_added');
        if ($overlap) {
            $msg .= ' · '.__('owner.flash.closure_overlap', ['n' => $overlap]);
        }

        return back()->with('success', $msg);
    }

    public function destroyClosure(Request $request, Closure $closure)
    {
        $this->ensureOwner($request);
        abort_unless($closure->venue_id === $this->venue($request)->id, 403);
        $closure->delete();

        return back()->with('success', __('owner.flash.closure_removed'));
    }
}
