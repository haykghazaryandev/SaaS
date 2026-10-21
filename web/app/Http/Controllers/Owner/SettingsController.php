<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Venues\PriceCalculator;
use App\Models\Booking;
use App\Models\Closure;
use App\Models\Field;
use App\Models\OpeningHour;
use App\Models\PriceRule;
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
        'free_cancel_hours' => 'required|integer|min:0|max:168',
        'min_notice_min' => 'required|integer|min:0|max:2880',
        'request_expire_min' => 'required|integer|min:15|max:2880',
        'auto_accept_regulars' => 'required|boolean',
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
                'status' => $venue->status,
            ],
            'prices' => $venue->fields->map(fn (Field $f) => [
                'field_id' => $f->id,
                'label' => BookingPresenter::fieldLabel($f),
                'surface' => $f->surface,
                'indoor' => $f->indoor,
                'day' => $prices->hourlyAt($f->priceRules, LocalTime::at($wed, 12)),
                'evening' => $prices->hourlyAt($f->priceRules, LocalTime::at($wed, 20)),
                'weekend' => $prices->hourlyAt($f->priceRules, LocalTime::at($sat, 12)),
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

    /** Three prices per field: weekdays before 18:00, weekday evenings, weekends. Replaces the field's price rules. */
    public function prices(Request $request)
    {
        $this->ensureOwner($request);
        $venue = $this->venue($request)->load('fields');
        $data = $request->validate([
            'prices' => 'required|array',
            'prices.*.field_id' => ['required', 'integer', Rule::in($venue->fields->pluck('id')->all())],
            'prices.*.day' => 'required|integer|min:0|max:1000000',
            'prices.*.evening' => 'required|integer|min:0|max:1000000',
            'prices.*.weekend' => 'required|integer|min:0|max:1000000',
        ], ['prices.*.*' => __('owner.form.bad_price')]);
        DB::transaction(function () use ($data) {
            foreach ($data['prices'] as $p) {
                PriceRule::where('field_id', $p['field_id'])->delete();
                $base = ['field_id' => $p['field_id'], 'weekdays' => [1, 2, 3, 4, 5]];
                PriceRule::create([...$base, 'from_time' => '00:00', 'to_time' => '18:00', 'price_per_hour' => $p['day'], 'priority' => 1]);
                PriceRule::create([...$base, 'from_time' => '18:00', 'to_time' => '24:00', 'price_per_hour' => $p['evening'], 'priority' => 1]);
                PriceRule::create(['field_id' => $p['field_id'], 'weekdays' => [0, 6], 'from_time' => '00:00', 'to_time' => '24:00', 'price_per_hour' => $p['weekend'], 'priority' => 2]);
            }
        });

        return back()->with('success', __('owner.flash.prices_saved'));
    }

    /** Opening hours per weekday; a day without times is closed. Closing may be 24:00. */
    public function hours(Request $request)
    {
        $this->ensureOwner($request);
        $venue = $this->venue($request);
        $time = ['nullable', 'regex:/^(([01]\d|2[0-3]):(00|30)|24:00)$/'];
        $data = $request->validate([
            'hours' => 'required|array|size:7',
            'hours.*.weekday' => 'required|integer|between:0,6|distinct',
            'hours.*.open' => $time,
            'hours.*.close' => $time,
        ]);
        foreach ($data['hours'] as $i => $h) {
            $open = $h['open'] ?? null;
            $close = $h['close'] ?? null;
            if (($open === null) !== ($close === null) || ($open !== null && OpeningHour::toHours($close) <= OpeningHour::toHours($open)) || $open === '24:00') {
                throw ValidationException::withMessages(["hours.$i.close" => __('owner.settings.hours_bad')]);
            }
        }
        DB::transaction(function () use ($venue, $data) {
            foreach ($data['hours'] as $h) {
                if (empty($h['open'])) {
                    OpeningHour::where('venue_id', $venue->id)->where('weekday', $h['weekday'])->delete();
                } else {
                    OpeningHour::updateOrCreate(['venue_id' => $venue->id, 'weekday' => $h['weekday']], ['opens_at' => $h['open'], 'closes_at' => $h['close']]);
                }
            }
        });

        return back()->with('success', __('owner.flash.saved'));
    }

    public function rules(Request $request)
    {
        $this->ensureOwner($request);
        $venue = $this->venue($request);
        $data = $request->validate(self::RULES);
        $data['auto_accept_regulars'] = (bool) $data['auto_accept_regulars'];
        foreach (['free_cancel_hours', 'min_notice_min', 'request_expire_min'] as $k) {
            $data[$k] = (int) $data[$k];
        }
        $venue->forceFill(['rules' => [...($venue->rules ?? []), ...$data]])->save();

        return back()->with('success', __('owner.flash.saved'));
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
            throw ValidationException::withMessages(['to' => __('owner.settings.closure_bad')]);
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
