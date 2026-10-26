<?php

namespace App\Domain\Club;

use App\Domain\Booking\BookingException;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueCard;
use Illuminate\Support\Facades\DB;

/**
 * The place takes money: for a booking, a training, a shop sale, a tournament fee.
 * Paid from the player's card (the balance goes down) or in cash. The player's card discount comes off first
 * (not for bookings: their price already has it), and the place's cashback (rules.cashback_pct) goes onto the card.
 */
class Money
{
    public function __construct(private Cards $cards) {}

    /**
     * @param  string  $method  card | cash
     * @param  bool  $discount  take the card discount off $amount
     */
    public function charge(Venue $venue, User $by, string $kind, int $amount, string $method, ?User $player = null, ?int $refId = null, ?string $note = null, bool $discount = true, bool $notify = true): Payment
    {
        if (! in_array($kind, Payment::KINDS, true) || ! in_array($method, ['card', 'cash'], true) || $amount < 0) {
            throw new BookingException('bad_amount');
        }

        return DB::transaction(function () use ($venue, $by, $kind, $amount, $method, $player, $refId, $note, $discount, $notify) {
            $card = $player ? VenueCard::where('venue_id', $venue->id)->where('user_id', $player->id)->lockForUpdate()->first() : null;
            if ($method === 'card' && ! $card) {
                throw new BookingException('no_card');
            }
            $off = $card && $discount ? (int) round($amount * $card->discount_pct / 100) : 0;
            $final = $amount - $off;
            if ($method === 'card' && $card->balance < $final) {
                throw new BookingException('low_balance');
            }
            $payment = Payment::create(['venue_id' => $venue->id, 'user_id' => $player?->id, 'card_id' => $card?->id,
                'kind' => $kind, 'method' => $method, 'amount' => $final, 'discount' => $off, 'ref_id' => $refId, 'note' => $note, 'created_by' => $by->id]);
            if ($method === 'card') {
                $this->cards->entry($card, -$final, 'pay', $by, $note, $payment);
            }
            $back = $card ? (int) floor($final * (int) $venue->rule('cashback_pct') / 100) : 0;
            if ($back > 0) {
                $this->cards->entry($card, $back, 'cashback', $by, null, $payment);
            }
            if ($notify && $method === 'card') {
                self::tellPaid($venue, $player, $final, $card->fresh()->balance);
            }

            return $payment;
        });
    }

    /** "−4 500 ֏ from your Pingpong.land card · balance 3 000 ֏" (after the payment is saved). */
    public static function tellPaid(Venue $venue, ?User $player, int $amount, int $balance): void
    {
        if (! $player || $amount <= 0) {
            return;
        }
        DB::afterCommit(fn () => $player->notify(new \App\Notifications\Notice('card_paid',
            ['venue' => $venue->name, 'amount' => Cards::amd($amount), 'balance' => Cards::amd($balance)], Cards::link($venue))));
    }
}
