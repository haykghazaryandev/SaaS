<?php

namespace App\Http\Controllers\Player;

use App\Domain\Club\Tournaments;
use App\Domain\Venues\Availability;
use App\Enums\Sport;
use App\Http\Controllers\Controller;
use App\Http\Presenters\PlayerGames;
use App\Http\Presenters\PlayerVenues;
use App\Models\Game;
use App\Models\Tournament;
use App\Models\Venue;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Home: the finder (day, time, size, district) and the places with their next free start times. */
class HomeController extends Controller
{
    public function __invoke(Request $request, PlayerVenues $present, Availability $availability)
    {
        // owners and staff work in their place's dashboard; "Open the player site" there (?as=player) lets them play too.
        // Admins use the app as players and open their panels (admin, owner) from Profile.
        if ($request->query('as') === 'player') {
            $request->session()->put('player_mode', true);
        }
        if (($u = $request->user()) && ! $u->is_admin && ! $request->session()->get('player_mode') && $u->venues()->exists()) {
            return redirect()->route('owner.start');
        }

        // the sport first (only those the admin shows): with several, the home page is just "What do you play?"
        $sports = Sport::visible();
        $chosen = collect($sports)->first(fn (Sport $sp) => $sp->value === $request->query('sport'));
        $sport = $chosen ?? ($sports[0] ?? Sport::Football);
        $sportSizes = $sport->sizeValues();
        $size = in_array($request->query('size'), $sportSizes, true) && count($sportSizes) > 1 ? $request->query('size') : null;
        $time = $request->query('time');
        $time = in_array($time, ['morning', 'day', 'evening'], true) || (is_string($time) && preg_match('/^([01]\d|2[0-3]):[03]0$/', $time)) ? $time : null;
        $district = is_string($request->query('district')) ? $request->query('district') : null;
        $day = $present->pickDay($request->query('date'));
        $today = LocalTime::now()->startOfDay();

        $all = Venue::listed()->with(['openingHours', 'fields.priceRules'])->orderByDesc('rating_avg')->orderBy('name')->get();
        $venues = $all->filter(fn (Venue $v) => $v->hasFeature('booking') && $v->fields->contains(fn ($f) => $f->active && in_array($f->size->value, $sportSizes, true)))->values();
        // each field knows its (already loaded) place: no extra query per field for the place and its hours
        $venues->each(fn (Venue $v) => $v->fields->each(fn ($f) => $f->setRelation('venue', $v)));
        $districts = $venues->pluck('district')->unique()->sort()->values()->all();
        $shown = $district ? $venues->where('district', $district)->values() : $venues;
        $sizes = $size ? [$size] : $sportSizes;

        $cards = collect($present->homeCards($shown, $day, $sizes, $time, 4));
        if ($time) {
            $cards = $cards->filter(fn ($c) => $c['free']);
        }
        // places with free times first, best rated first (the query order)
        $cards = $cards->sortBy(fn ($c) => $c['free'] ? 0 : 1)->values();

        // size tiles: how many places still have a free evening time today, and the lowest price
        $allFields = $venues->flatMap(fn ($v) => $v->fields->where('active', true)->whereIn('size.value', $sportSizes));
        $tonight = $availability->freeStartsMany($allFields, $today);
        $tiles = collect(count($sportSizes) > 1 ? $sportSizes : [])->mapWithKeys(fn ($z) => [$z => [
            'places' => $venues->filter(fn ($v) => $v->fields->contains(fn ($f) => $f->active && $f->size->value === $z))->count(),
            'tonight' => $allFields->filter(fn ($f) => $f->size->value === $z && collect($tonight[$f->id] ?? [])->contains(fn ($h) => $h >= 18))->count(),
            'from' => (int) $allFields->filter(fn ($f) => $f->size->value === $z)->flatMap(fn ($f) => $f->priceRules->pluck('price_per_hour'))->min(),
        ]]);

        $sportTabs = collect($sports)->map(fn (Sport $sp) => [
            'key' => $sp->value, 'icon' => $sp->icon(),
            'places' => $all->filter(fn (Venue $v) => $v->hasFeature('booking') && $v->fields->contains(fn ($f) => $f->active && $f->size->sport() === $sp))->count(),
        ])->filter(fn ($x) => $x['places'] > 0)->values();
        if (! $chosen && $sportTabs->count() > 1) {
            // back on "What do you play?": no sport chosen (the menu shows no sport's games)
            $request->session()->forget('sport');

            return Inertia::render('player/Home', ['pick' => true, 'sports' => $sportTabs, 'filters' => ['sport' => null]]);
        }
        // the chosen sport goes with the player through the app (its games in the menu)
        $request->session()->put('sport', $sport->value);

        // the sport's page: book (places and free times), its tournaments, open games
        // upcoming, on now and finished this week (with where each one is), on now first, finished last
        $order = ['live' => 0, 'drawn' => 1, 'registration' => 2, 'closed' => 3, 'done' => 4];
        $tournaments = Tournament::where('sport', $sport->value)->where('starts_at', '>', now()->subDays(7))->where(fn ($q) => $q->where('status', '!=', 'done')->orWhere('updated_at', '>', now()->subDays(7)))
            ->whereIn('venue_id', $all->filter(fn (Venue $v) => $v->hasFeature('tournaments'))->pluck('id'))
            ->with('venue:id,name,slug')->orderBy('starts_at')->limit(12)->get()
            ->map(fn (Tournament $t) => ['code' => $t->code, 'title' => $t->title, 'venue' => $t->venue->name, 'phase' => Tournaments::phase($t),
                'when' => $t->starts_at->setTimezone(LocalTime::tz())->toIso8601String(), 'from' => (int) collect($t->options)->min('price')])
            ->sortBy(fn ($t) => $order[$t['phase']])->values();
        // open games of this sport anybody can join (the Games tab)
        $openGames = Game::query()->with(PlayerGames::WITH)->where('status', 'open')->where('sport', $sport->value)
            ->where(fn ($q) => $q->whereNull('when_to')->orWhere('when_to', '>', now()))->latest('opened_at')->limit(12)->get();
        $games = $openGames->count();

        return Inertia::render('player/Home', [
            'pick' => false,
            'tournaments' => $tournaments,
            'openGames' => $games,
            'games' => $openGames->map(fn (Game $g) => app(PlayerGames::class)->card($g, $request->user()))->values(),
            'tab' => in_array($request->query('tab'), ['book', 'tournaments', 'games'], true) ? $request->query('tab') : 'book',
            'filters' => ['sport' => $sport->value, 'date' => $day->toDateString(), 'time' => $time, 'size' => $size, 'district' => $district],
            // the sport picker: visible sports that have at least one place
            'sports' => $sportTabs,
            'days' => $present->days(),
            'districts' => $districts,
            'venues' => $cards->all(),
            'tiles' => $tiles,
            'stats' => ['venues' => $venues->count(), 'fields' => $allFields->count(), 'tonight' => collect($tonight)->filter(fn ($s) => collect($s)->contains(fn ($h) => $h >= 18))->count()],
        ]);
    }
}
