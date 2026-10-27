<?php

namespace App\Http\Controllers\Player;

use App\Domain\Club\Cards;
use App\Domain\Club\Tournaments;
use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\PlayerVenues;
use App\Models\Booking;
use App\Models\Product;
use App\Models\SlotAlert;
use App\Models\Tournament;
use App\Models\Venue;
use App\Models\VenueCard;
use App\Support\LocalTime;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

/** A place: its two fields, free start times for a day, price, and the booking request. */
class PlaceController extends Controller
{
    public function show(Request $request, Venue $venue, PlayerVenues $present)
    {
        abort_unless($venue->status === 'active', 404);
        $venue->load(['openingHours', 'fields' => fn ($q) => $q->where('active', true), 'fields.priceRules']);
        $venue->fields->each(fn ($f) => $f->setRelation('venue', $venue));
        // a place of a sport: that sport is the chosen one
        if ($sp = $venue->sports()[0] ?? null) {
            $request->session()->put('sport', $sp->value);
        }
        $day = $present->pickDay($request->query('date'));
        // one choice per kind of court: all ping-pong tables are "a table" (the first one stands for the group)
        $groups = $present->groups($venue->fields);
        $fields = $groups->map->first()->values();

        // the court asked for (?field= any court of the group, or ?size=), else the first kind
        $asked = (int) $request->query('field');
        $field = ($asked ? $groups->first(fn ($g) => $g->contains('id', $asked))?->first() : null)
            ?? $fields->firstWhere('size.value', $request->query('size'))
            ?? $fields->first();
        $minutes = (int) $request->query('minutes');
        $start = $request->query('start');

        $booked = null;
        if ($id = $request->session()->get('booked')) {
            $b = Booking::with(['venue', 'field'])->find($id);
            if ($b && $request->user() && $b->user_id === $request->user()->id) {
                $booked = $present->booking($b);
            }
        }

        return Inertia::render('player/Place', [
            'venue' => [
                ...$present->venueBasics($venue),
                'description' => $venue->tr('description'),
                'amenities' => array_values($venue->amenities ?? []),
                'week' => $present->weekHours($venue),
                // "Open in Maps" and "Call" in the info list
                'lat' => $venue->lat !== null ? (float) $venue->lat : null,
                'lng' => $venue->lng !== null ? (float) $venue->lng : null,
                'phone' => $venue->phone ?: null,
                'phoneLabel' => $venue->phone ? Phone::format($venue->phone) : null,
                'rules' => ['freeCancelHours' => (int) $venue->rule('free_cancel_hours'), 'minNoticeMin' => (int) $venue->rule('min_notice_min'), 'horizonDays' => (int) $venue->rule('horizon_days')],
            ],
            'fields' => $fields->map(fn ($f) => [...$present->field($f), 'count' => $groups[$f->id]->count()])->values()->all(),
            'sport' => $field?->sport()->value ?? 'football',
            'features' => $venue->featureList(),
            // the club parts the place has switched on
            'club' => $this->club($request, $venue),
            'days' => $present->days(),
            'initial' => [
                'date' => $day->toDateString(),
                'field' => $field?->id,
                'minutes' => $field && in_array($minutes, $field->durations ?? [], true) ? $minutes : $field?->default_duration,
                'start' => is_string($start) && preg_match('/^\d{2}:\d{2}$/', $start) ? $start : null,
            ],
            'slots' => $present->slots($venue, $day) + ['mine' => $this->mine($request, $venue, $day, $groups)],
            'booked' => $booked,
            // why a new request can't be sent right now (told before tapping "Book", not after): too many waiting, or a pause
            'limit' => $this->limit($request, $venue),
            // the player's "notify me if it frees up" times at this place (a bell on those taken times)
            'alerts' => $request->user() ? SlotAlert::where('user_id', $request->user()->id)->where('venue_id', $venue->id)
                ->where('starts_at', '>', now())->orderBy('starts_at')->get()
                ->map(function (SlotAlert $a) {
                    $t = $a->starts_at->toImmutable()->setTimezone(LocalTime::tz());

                    return ['id' => $a->id, 'field' => $a->field_id, 'date' => $t->toDateString(), 'start' => $t->format('H:i'), 'minutes' => $a->minutes, 'notified' => $a->notified_at !== null];
                })->values()->all() : [],
        ]);
    }

    /** JSON for live updates: GET /places/{venue}/slots?date=2026-10-21&field=3&minutes=90 (field and minutes are optional). */
    public function slots(Request $request, Venue $venue, PlayerVenues $present)
    {
        abort_unless($venue->status === 'active', 404);
        $request->validate([
            'date' => 'nullable|date_format:Y-m-d',
            'field' => 'nullable|integer',
            'minutes' => 'nullable|integer|min:30|max:600',
        ]);
        $fieldId = $request->integer('field') ?: null;
        abort_if($fieldId && ! $venue->fields()->whereKey($fieldId)->exists(), 404);

        $day = $present->pickDay($request->query('date'));

        // "mine" and "limit" come with every refresh: a request withdrawn on another page shows up here at once
        return response()->json($present->slots($venue, $day, $fieldId, $request->integer('minutes') ?: null)
            + ['mine' => $this->mine($request, $venue, $day, $present->groups($venue->fields)), 'limit' => $this->limit($request, $venue)]);
    }

    /** Waiting requests and a booking pause: why "Book" can't send a new request (null for guests). */
    private function limit(Request $request, Venue $venue): ?array
    {
        $u = $request->user();

        return $u ? [
            'pending' => Booking::where('user_id', $u->id)->where('status', BookingStatus::Pending)->count(),
            'maxPending' => (int) $venue->rule('max_pending_per_phone'),
            'bannedUntil' => $u->isBookingBanned() ? $u->booking_ban_until->toImmutable()->setTimezone(LocalTime::tz())->format('d.m.Y') : null,
        ] : null;
    }

    /**
     * The player's own requests and bookings on this day at this place: their times are "yours" (blue), and a tap
     * shows the booking (waiting / confirmed, code, cancel) instead of "notify me if it frees up".
     */
    private function mine(Request $request, Venue $venue, CarbonImmutable $day, ?Collection $groups = null): array
    {
        // a booking on "Table 3" shows on the tables group the page offers
        $repOf = [];
        foreach ($groups ?? [] as $repId => $group) {
            foreach ($group as $court) {
                $repOf[$court->id] = $repId;
            }
        }
        if (! $request->user()) {
            return [];
        }

        return Booking::where('user_id', $request->user()->id)->where('venue_id', $venue->id)->holding()
            ->where('starts_at', '<', $day->addDay()->utc())->where('ends_at', '>', $day->utc())->orderBy('starts_at')->get()
            ->map(function (Booking $b) use ($day, $repOf) {
                $from = LocalTime::hourOf($b->starts_at->toImmutable()->setTimezone(LocalTime::tz()), $day);

                return ['id' => $b->id, 'code' => $b->code, 'status' => $b->status->value, 'field' => $repOf[$b->field_id] ?? $b->field_id,
                    'start' => round($from, 2), 'end' => round($from + $b->durationMinutes() / 60, 2)];
            })->values()->all();
    }

    /** Shop, open tournaments and "my card" (with its cashback level) for the place page. */
    private function club(Request $request, Venue $venue): array
    {
        $user = $request->user();
        $card = $user && ($venue->hasFeature('wallet') || $venue->hasFeature('rating'))
            ? VenueCard::where('venue_id', $venue->id)->where('user_id', $user->id)->first() : null;

        return [
            'card' => ($venue->hasFeature('wallet') || $venue->hasFeature('rating')) ? ($card ? ['balance' => $card->balance, 'cashback' => $venue->hasFeature('wallet') ? Cards::level($venue, Cards::spent($card))['pct'] : 0] : false) : null,
            'products' => $venue->hasFeature('shop') ? Product::where('venue_id', $venue->id)->where('active', true)->orderBy('sort')->orderBy('name')->get()
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'description' => $p->description, 'price' => $p->price, 'final' => $p->finalPrice(),
                    'discount_pct' => $p->discount_pct, 'in_stock' => $p->in_stock, 'photo' => $p->photoUrl()])->values()->all() : [],
            'tournaments' => $venue->hasFeature('tournaments') ? Tournament::where('venue_id', $venue->id)->where('starts_at', '>', now()->subDays(7))->where(fn ($q) => $q->where('status', '!=', 'done')->orWhere('updated_at', '>', now()->subDays(7)))
                ->orderBy('starts_at')->limit(8)->get()->map(fn ($t) => ['code' => $t->code, 'title' => $t->title, 'phase' => Tournaments::phase($t),
                    'when' => $t->starts_at->setTimezone(LocalTime::tz())->format('d.m H:i'), 'from' => (int) collect($t->options)->min('price')])->values()->all() : [],
        ];
    }
}
