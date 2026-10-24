<?php

namespace App\Http\Controllers\Player;

use App\Domain\Venues\Availability;
use App\Http\Controllers\Controller;
use App\Http\Presenters\PlayerVenues;
use App\Models\Venue;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Home: the finder (day, time, size, district) and the places with their next free start times. */
class HomeController extends Controller
{
    public function __invoke(Request $request, PlayerVenues $present, Availability $availability)
    {
        $size = in_array($request->query('size'), ['small', 'big'], true) ? $request->query('size') : null;
        $time = $request->query('time');
        $time = in_array($time, ['morning', 'day', 'evening'], true) || (is_string($time) && preg_match('/^([01]\d|2[0-3]):[03]0$/', $time)) ? $time : null;
        $district = is_string($request->query('district')) ? $request->query('district') : null;
        $day = $present->pickDay($request->query('date'));
        $today = LocalTime::now()->startOfDay();

        $venues = Venue::active()->with(['openingHours', 'fields.priceRules'])->orderByDesc('rating_avg')->orderBy('name')->get();
        // each field knows its (already loaded) place: no extra query per field for the place and its hours
        $venues->each(fn (Venue $v) => $v->fields->each(fn ($f) => $f->setRelation('venue', $v)));
        $districts = $venues->pluck('district')->unique()->sort()->values()->all();
        $shown = $district ? $venues->where('district', $district)->values() : $venues;
        $sizes = $size ? [$size] : ['small', 'big'];

        $cards = collect($present->homeCards($shown, $day, $sizes, $time, 4));
        if ($time) {
            $cards = $cards->filter(fn ($c) => $c['free']);
        }
        // places with free times first, best rated first (the query order)
        $cards = $cards->sortBy(fn ($c) => $c['free'] ? 0 : 1)->values();

        // size tiles: how many places still have a free evening time today, and the lowest price
        $allFields = $venues->flatMap(fn ($v) => $v->fields->where('active', true));
        $tonight = $availability->freeStartsMany($allFields, $today);
        $tiles = collect(['small', 'big'])->mapWithKeys(fn ($z) => [$z => [
            'places' => $venues->filter(fn ($v) => $v->fields->contains(fn ($f) => $f->active && $f->size->value === $z))->count(),
            'tonight' => $allFields->filter(fn ($f) => $f->size->value === $z && collect($tonight[$f->id] ?? [])->contains(fn ($h) => $h >= 18))->count(),
            'from' => (int) $allFields->filter(fn ($f) => $f->size->value === $z)->flatMap(fn ($f) => $f->priceRules->pluck('price_per_hour'))->min(),
        ]]);

        return Inertia::render('player/Home', [
            'filters' => ['date' => $day->toDateString(), 'time' => $time, 'size' => $size, 'district' => $district],
            'days' => $present->days(),
            'districts' => $districts,
            'venues' => $cards->all(),
            'tiles' => $tiles,
            'stats' => ['venues' => $venues->count(), 'fields' => $allFields->count(), 'tonight' => collect($tonight)->filter(fn ($s) => collect($s)->contains(fn ($h) => $h >= 18))->count()],
        ]);
    }
}
