<?php

namespace App\Domain\Club;

use App\Domain\Booking\BookingException;
use App\Models\Training;
use App\Models\TrainingVisit;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Trainings of a place: the schedule players see, and visits the staff mark (with the payment). */
class Trainings
{
    public function __construct(private Money $money) {}

    public function save(Training $training, array $data): Training
    {
        if (! $training->exists && ! $training->venue?->hasFeature('trainings')) {
            throw new BookingException('feature_off');
        }
        $training->fill([
            'title' => trim($data['title']), 'coach' => $data['coach'] ?? null, 'weekdays' => array_values(array_map('intval', $data['weekdays'])),
            'starts_at' => $data['starts_at'], 'minutes' => (int) $data['minutes'], 'price' => (int) $data['price'],
            'capacity' => $data['capacity'] ?? null, 'note' => $data['note'] ?? null, 'active' => $data['active'] ?? true,
        ])->save();

        return $training;
    }

    /** The player came today (or on $date): one visit per day, paid by card or cash ($method null = not paid now). */
    public function visit(Training $training, User $player, User $by, ?string $method, ?CarbonImmutable $date = null): TrainingVisit
    {
        $date ??= LocalTime::now()->startOfDay();
        $training->loadMissing('venue');
        if (TrainingVisit::where('training_id', $training->id)->where('user_id', $player->id)->whereDate('date', $date->toDateString())->exists()) {
            throw new BookingException('already_visited');
        }
        if ($training->capacity && $training->visits()->whereDate('date', $date->toDateString())->count() >= $training->capacity) {
            throw new BookingException('training_full');
        }

        return DB::transaction(function () use ($training, $player, $by, $method, $date) {
            $payment = $method ? $this->money->charge($training->venue, $by, 'training', $training->price, $method, $player, null, $training->title) : null;
            $visit = TrainingVisit::create(['training_id' => $training->id, 'user_id' => $player->id, 'date' => $date->toDateString(),
                'payment_id' => $payment?->id, 'created_by' => $by->id]);
            $payment?->update(['ref_id' => $visit->id]);

            return $visit;
        });
    }

    /** "Mon, Wed, Fri · 19:00–20:30" pieces for the pages. */
    public static function present(Training $t): array
    {
        $from = substr($t->starts_at, 0, 5);
        [$h, $m] = array_map('intval', explode(':', $from));
        $end = $h * 60 + $m + $t->minutes;

        return [
            'id' => $t->id, 'title' => $t->title, 'coach' => $t->coach, 'weekdays' => $t->weekdays, 'start' => $from,
            'end' => sprintf('%02d:%02d', intdiv($end, 60) % 24, $end % 60), 'minutes' => $t->minutes, 'price' => $t->price,
            'capacity' => $t->capacity, 'note' => $t->note, 'active' => $t->active,
        ];
    }
}
