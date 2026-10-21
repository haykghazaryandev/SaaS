<?php

namespace App\Http\Controllers\Player;

use App\Http\Controllers\Controller;
use App\Http\Presenters\PlayerVenues;
use App\Models\Booking;
use App\Models\Venue;
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
            'slots' => $present->slots($venue, $day),
            'booked' => $booked,
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

        return response()->json($present->slots($venue, $present->pickDay($request->query('date')), $fieldId, $request->integer('minutes') ?: null));
    }
}
