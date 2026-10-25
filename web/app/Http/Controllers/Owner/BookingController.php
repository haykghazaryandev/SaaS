<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\DecideBooking;
use App\Domain\Booking\Actions\OwnerBooking;
use App\Domain\Venues\Availability;
use App\Domain\Venues\PriceCalculator;
use App\Models\Booking;
use App\Models\Field;
use App\Models\User;
use App\Models\Venue;
use App\Support\LocalTime;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Bookings made, changed and answered by the place (owner or staff). Every booking must belong to the current place. */
class BookingController extends OwnerController
{
    /** JSON for the booking sheet. */
    public function show(Request $request, Booking $booking)
    {
        $this->ensureSameVenue($request, $booking);

        return response()->json(BookingPresenter::detail($booking));
    }

    /** JSON for the booking form: auto price, whether the time is free, and the player behind a phone number. */
    public function quote(Request $request, Availability $availability, PriceCalculator $prices)
    {
        $venue = $this->venue($request);
        $data = $request->validate([
            ...$this->timeRules($venue),
            'phone' => 'nullable|string|max:20',
            'ignore' => 'nullable|integer',
        ]);
        $field = Field::with('priceRules', 'venue.openingHours')->findOrFail($data['field_id']);
        $start = $this->start($data);
        $ignore = isset($data['ignore']) && Booking::where('id', $data['ignore'])->where('venue_id', $venue->id)->exists() ? (int) $data['ignore'] : null;
        $problem = $availability->problem($field, $start, (int) $data['minutes'], forPlayer: false, ignoreBookingId: $ignore);
        $phone = Phone::normalize($data['phone'] ?? null);
        $player = $phone ? User::where('phone', $phone)->first(['id', 'name', 'no_show_count']) : null;

        return response()->json([
            'price' => $prices->price($field, $start, (int) $data['minutes']),
            'problem' => $problem,
            'message' => $problem ? __('booking.error.'.$problem) : null,
            'player' => $player ? ['name' => $player->name, 'no_shows' => (int) $player->no_show_count] : null,
            'phoneValid' => ! filled($data['phone'] ?? null) || $phone !== null,
            // the start times of the chosen day for this field and length, like the booking page: free (green) and taken
            ...$this->dayStarts($availability, $field, LocalTime::day($data['date']), (int) $data['minutes'], $ignore),
        ]);
    }

    private function dayStarts(Availability $availability, Field $field, CarbonImmutable $day, int $minutes, ?int $ignore): array
    {
        $busy = $availability->busy($field, $day, $ignore);

        return [
            'starts' => $availability->freeStarts($field, $day, $minutes, false, $busy),
            'taken' => $availability->takenStarts($field, $day, $minutes, false, $busy),
        ];
    }

    public function store(Request $request, OwnerBooking $owner)
    {
        $venue = $this->venue($request);
        $data = $request->validate([
            ...$this->timeRules($venue),
            ...$this->contactRules(),
            'weeks' => 'nullable|integer|min:1|max:26',
        ], $this->messages());
        $this->checkPhone($data);
        $field = Field::findOrFail($data['field_id']);
        $start = $this->start($data);
        $minutes = (int) $data['minutes'];
        $payload = [
            'name' => trim((string) ($data['name'] ?? '')),
            'phone' => $data['phone'] ?? null,
            'team_name' => $data['team_name'] ?? null,
            'note_owner' => $data['note_owner'] ?? null,
            'price' => isset($data['price']) ? (int) $data['price'] : null,
        ];
        $weeks = (int) ($data['weeks'] ?? 1);

        if ($weeks > 1) {
            $r = $this->attempt(fn () => $owner->createWeekly($request->user(), $field, $start, $minutes, $weeks, $payload));
            if (! $r['created']) {
                throw ValidationException::withMessages(['booking' => __('owner.flash.weekly_none')]);
            }
            $msg = __('owner.flash.created_n', ['n' => count($r['created'])]);
            if ($r['skipped']) {
                $msg .= ' · '.__('owner.flash.skipped', ['dates' => collect($r['skipped'])->map(fn ($d) => $d->locale(app()->getLocale())->isoFormat('D MMM'))->join(', ')]);
            }

            return back()->with('success', $msg)->with('skipped', collect($r['skipped'])->map->toDateString()->all());
        }
        $this->attempt(fn () => $owner->create($request->user(), $field, $start, $minutes, $payload));

        return back()->with('success', __('owner.flash.created'));
    }

    public function update(Request $request, Booking $booking, OwnerBooking $owner)
    {
        $this->ensureSameVenue($request, $booking);
        $venue = $this->venue($request);
        $data = $request->validate([...$this->timeRules($venue), ...$this->contactRules()], $this->messages());
        $this->checkPhone($data);
        $changes = [
            'field_id' => (int) $data['field_id'],
            'starts_at' => $this->start($data)->toIso8601String(),
            'minutes' => (int) $data['minutes'],
            'team_name' => (string) ($data['team_name'] ?? ''),
            'note_owner' => (string) ($data['note_owner'] ?? ''),
        ];
        if (filled($data['name'] ?? null)) {
            $changes['contact_name'] = trim($data['name']);
        }
        if (array_key_exists('phone', $data)) {
            $changes['contact_phone'] = $data['phone'];
        }
        if (isset($data['price'])) {
            $changes['price'] = (int) $data['price'];
        }
        $this->attempt(fn () => $owner->update($request->user(), $booking, $changes));

        return back()->with('success', __('owner.flash.saved'));
    }

    public function accept(Request $request, Booking $booking, DecideBooking $decide)
    {
        $this->ensureSameVenue($request, $booking);
        $this->attempt(fn () => $decide->accept($booking, $request->user()));

        return $this->decided($request, 'accepted');
    }

    public function decline(Request $request, Booking $booking, DecideBooking $decide)
    {
        $this->ensureSameVenue($request, $booking);
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        $this->attempt(fn () => $decide->decline($booking, $request->user(), filled($data['reason'] ?? null) ? trim($data['reason']) : null));

        return $this->decided($request, 'declined');
    }

    public function cancel(Request $request, Booking $booking, CancelBooking $cancel)
    {
        $this->ensureSameVenue($request, $booking);
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        $this->attempt(fn () => $cancel->handle($booking, $request->user(), 'owner', filled($data['reason'] ?? null) ? trim($data['reason']) : null));

        return back()->with('success', __('owner.flash.cancelled'));
    }

    public function noShow(Request $request, Booking $booking, OwnerBooking $owner)
    {
        $this->ensureSameVenue($request, $booking);
        $this->attempt(fn () => $owner->noShow($request->user(), $booking));

        return back()->with('success', __('owner.flash.no_show'));
    }

    /** "The team is here" (optional; then "how was the field?" goes 30 min after the game). */
    public function arrived(Request $request, Booking $booking, \App\Domain\Booking\AfterBooking\Attendance $attendance)
    {
        $this->ensureSameVenue($request, $booking);
        $this->attempt(fn () => $attendance->arrived($request->user(), $booking));

        return back()->with('success', __('owner.flash.arrived'));
    }

    // ---------- helpers ----------

    /**
     * Accept/decline answer. The owner pages send it with fetch (JSON) once the 5-second "Undo" window of the toast is over,
     * so the decision also reaches the server when the page is being closed; plain form posts get the usual redirect.
     */
    private function decided(Request $request, string $what)
    {
        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'message' => __('owner.flash.'.$what)]);
        }

        return back()->with('success', __('owner.flash.'.$what));
    }

    private function timeRules(Venue $venue): array
    {
        return [
            'field_id' => ['required', 'integer', Rule::exists('fields', 'id')->where('venue_id', $venue->id)],
            'date' => 'required|date_format:Y-m-d',
            'start' => ['required', 'regex:/^([01]\d|2[0-3]):(00|30)$/'],
            'minutes' => 'required|integer|min:30|max:720|multiple_of:30',
        ];
    }

    private function contactRules(): array
    {
        return [
            'name' => 'nullable|string|max:80|required_without:phone',
            'phone' => 'nullable|string|max:20',
            'team_name' => 'nullable|string|max:80',
            'note_owner' => 'nullable|string|max:1000',
            'price' => 'nullable|integer|min:0|max:10000000',
        ];
    }

    private function messages(): array
    {
        return [
            'name.required_without' => __('owner.form.need_name'),
            'price.*' => __('owner.form.bad_price'),
        ];
    }

    private function checkPhone(array $data): void
    {
        if (filled($data['phone'] ?? null) && ! Phone::normalize($data['phone'])) {
            throw ValidationException::withMessages(['phone' => __('owner.form.bad_phone')]);
        }
    }

    /** Local date + "HH:MM" -> local start time. */
    private function start(array $data): CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $data['start']));

        return LocalTime::at(LocalTime::day($data['date']), $h + $m / 60);
    }
}
