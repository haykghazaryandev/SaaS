<?php

namespace App\Http\Controllers\Player;

use App\Domain\Booking\Alerts\SlotAlerts;
use App\Domain\Booking\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Field;
use App\Models\SlotAlert;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** "Notify me if it frees up" for a booked time (place page), and removing it (My bookings). */
class SlotAlertController extends Controller
{
    public function store(Request $request, SlotAlerts $alerts)
    {
        $data = $request->validate([
            'field_id' => 'required|integer',
            'date' => 'required|date_format:Y-m-d',
            'start' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'minutes' => 'required|integer|min:30|max:600',
        ]);
        $field = Field::with('venue.openingHours')->where('active', true)->findOrFail($data['field_id']);
        [$h, $m] = array_map('intval', explode(':', $data['start']));
        try {
            $alerts->watch($request->user(), $field, LocalTime::at(LocalTime::day($data['date']), $h + $m / 60), (int) $data['minutes']);
        } catch (BookingException $e) {
            throw ValidationException::withMessages(['alert' => __('alerts.error.'.$e->reason)]);
        }

        return back()->with('success', __('alerts.on', ['time' => $data['start']]));
    }

    public function destroy(Request $request, SlotAlert $alert, SlotAlerts $alerts)
    {
        abort_unless($alert->user_id === $request->user()->id, 403);
        $alerts->stop($request->user(), $alert);

        return back()->with('success', __('alerts.off'));
    }
}
