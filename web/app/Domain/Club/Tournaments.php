<?php

namespace App\Domain\Club;

use App\Domain\Booking\BookingException;
use App\Models\Payment;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tournaments: the place opens one with up to 3 price options and puts its link (/t/{code}) in the Instagram bio.
 * People register with name, surname, phone and level (no account needed); the place accepts them, takes the fee
 * and writes the results, which stay as the history.
 */
class Tournaments
{
    public function __construct(private Money $money) {}

    public function save(Tournament $t, array $data): Tournament
    {
        if (! $t->exists && ! $t->venue?->hasFeature('tournaments')) {
            throw new BookingException('feature_off');
        }
        $options = array_values(array_filter(array_map(fn ($o) => ['label' => trim((string) ($o['label'] ?? '')), 'price' => max(0, (int) ($o['price'] ?? 0))],
            $data['options'] ?? []), fn ($o) => $o['label'] !== ''));
        if (! $options || count($options) > 3) {
            throw new BookingException('bad_options');
        }
        $t->fill(['title' => trim($data['title']), 'sport' => $data['sport'], 'starts_at' => $data['starts_at'],
            'description' => $data['description'] ?? null, 'options' => $options, 'status' => $data['status'] ?? ($t->status ?: 'open')]);
        if (! $t->code) {
            $t->code = Str::lower(Str::random(8));
        }
        $t->save();

        return $t;
    }

    public function register(Tournament $t, array $data, ?User $user = null): TournamentEntry
    {
        if ($t->status !== 'open' || $t->starts_at->isPast()) {
            throw new BookingException('tournament_closed');
        }
        $phone = Phone::normalize($data['phone'] ?? null);
        if (! $phone) {
            throw new BookingException('bad_phone');
        }
        if (! isset($t->options[(int) $data['option']])) {
            throw new BookingException('bad_options');
        }
        if (TournamentEntry::where('tournament_id', $t->id)->where('phone', $phone)->exists()) {
            throw new BookingException('already_registered');
        }

        return TournamentEntry::create(['tournament_id' => $t->id, 'user_id' => $user?->id ?? User::where('phone', $phone)->value('id'),
            'first_name' => trim($data['first_name']), 'last_name' => trim($data['last_name']), 'phone' => $phone,
            'level' => isset($data['level']) && $data['level'] !== '' ? (int) $data['level'] : null, 'option' => (int) $data['option']]);
    }

    public function decide(TournamentEntry $e, string $status): TournamentEntry
    {
        if (! in_array($status, ['applied', 'accepted', 'declined'], true)) {
            throw new BookingException('bad_options');
        }
        $e->update(['status' => $status]);

        return $e;
    }

    public function result(TournamentEntry $e, ?int $place): TournamentEntry
    {
        $e->update(['place' => $place && $place > 0 ? $place : null]);

        return $e;
    }

    /** The fee of the chosen option, by card (the player's card at this place) or cash. */
    public function pay(TournamentEntry $e, string $method, User $by): Payment
    {
        $e->loadMissing('tournament.venue');
        if ($e->payment_id) {
            throw new BookingException('already_paid');
        }

        return DB::transaction(function () use ($e, $method, $by) {
            $t = $e->tournament;
            $player = $e->user_id ? User::find($e->user_id) : null;
            $payment = $this->money->charge($t->venue, $by, 'tournament', (int) ($t->options[$e->option]['price'] ?? 0), $method, $player, $e->id, $t->title);
            $e->update(['payment_id' => $payment->id]);

            return $payment;
        });
    }
}
