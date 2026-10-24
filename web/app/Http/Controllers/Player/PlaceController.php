<?php

namespace App\Http\Controllers\Player;

use App\Http\Controllers\Controller;
use App\Http\Presenters\PlayerVenues;
use App\Models\Booking;
use App\Models\SlotAlert;
use App\Enums\BookingStatus;
use App\Models\Venue;
use App\Support\LocalTime;
use App\Support\Phone;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** A place: its two fields, free start times for a day, price, and the booking request. */
class PlaceController extends Controller
{
    public function show(Request $request, Venue $venue, PlayerVenues $present)
    {
        abort_unless($venue->status === 'active', 404);
        $venue->load(['openingHours', 'fields' => fn ($q) => $q->where('active', true), 'fields.priceRules']);
        $day = $present->pickDay($request->query('date'));
        $fields = $venue->fields;

        // the field asked for (?field= or ?size=), else the small one
        $field = $fields->firstWhere('id', (int) $request->query('field'))
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
            'fields' => $fields->map(fn ($f) => $present->field($f))->values()->all(),
            'days' => $present->days(),
            'initial' => [
                'date' => $day->toDateString(),
                'field' => $field?->id,
                'minutes' => $field && in_array($minutes, $field->durations ?? [], true) ? $minutes : $field?->default_duration,
                'start' => is_string($start) && preg_match('/^\d{2}:\d{2}$/', $start) ? $start : null,
            ],
            'slots' => $present->slots($venue, $day) + ['mine' => $this->mine($request, $venue, $day)],
            'booked' => $booked,
            // why a new request can't be sent right now (told before tapping "Book", not after): too many waiting, or a pause
            'limit' => ($u = $request->user()) ? [
                'pending' => Booking::where('user_id', $u->id)->where('status', BookingStatus::Pending)->count(),
                'maxPending' => (int) $venue->rule('max_pending_per_phone'),
                'bannedUntil' => $u->isBookingBanned() ? $u->booking_ban_until->toImmutable()->setTimezone(LocalTime::tz())->format('d.m.Y') : null,
            ] : null,
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

        return response()->json($present->slots($venue, $day, $fieldId, $request->integer('minutes') ?: null) + ['mine' => $this->mine($request, $venue, $day)]);
    }

    /**
     * The player's own requests and bookings on this day at this place: their times are "yours" (blue), and a tap
     * shows the booking (waiting / confirmed, code, cancel) instead of "notify me if it frees up".
     */
    private function mine(Request $request, Venue $venue, \Carbon\CarbonImmutable $day): array
    {
        if (! $request->user()) {
            return [];
        }

        return Booking::where('user_id', $request->user()->id)->where('venue_id', $venue->id)->holding()
            ->where('starts_at', '<', $day->addDay()->utc())->where('ends_at', '>', $day->utc())->orderBy('starts_at')->get()
            ->map(function (Booking $b) use ($day) {
                $from = LocalTime::hourOf($b->starts_at->toImmutable()->setTimezone(LocalTime::tz()), $day);

                return ['id' => $b->id, 'code' => $b->code, 'status' => $b->status->value, 'field' => $b->field_id,
                    'start' => round($from, 2), 'end' => round($from + $b->durationMinutes() / 60, 2)];
            })->values()->all();
    }
}
