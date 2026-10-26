<?php

namespace App\Domain\Club;

use App\Domain\Booking\BookingException;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueCard;
use App\Notifications\Notice;
use Illuminate\Support\Facades\DB;

/**
 * The place takes money: for a booking, a training, a shop sale, a tournament fee.
 * Paid from the player's card (the balance goes down) or in cash. What is paid from the card earns cashback onto it:
 * the % grows with everything the card has paid at the place (rules.cashback_tiers, Cards::level), by itself.
 */
class Money
{
    public function __construct(private Cards $cards) {}

    /**
     * @param  string  $method  card | cash
     */
    public function charge(Venue $venue, User $by, string $kind, int $amount, string $method, ?User $player = null, ?int $refId = null, ?string $note = null, bool $notify = true): Payment
    {
        if (! in_array($kind, Payment::KINDS, true) || ! in_array($method, ['card', 'cash'], true) || $amount < 0) {
            throw new BookingException('bad_amount');
        }

        return DB::transaction(function () use ($venue, $by, $kind, $amount, $method, $player, $refId, $note, $notify) {
            $card = $player ? VenueCard::where('venue_id', $venue->id)->where('user_id', $player->id)->lockForUpdate()->first() : null;
            if ($method === 'card' && ! $card) {
                throw new BookingException('no_card');
            }
            $final = $amount;
            if ($method === 'card' && $card->balance < $final) {
                throw new BookingException('low_balance');
            }
            $payment = Payment::create(['venue_id' => $venue->id, 'user_id' => $player?->id, 'card_id' => $card?->id,
                'kind' => $kind, 'method' => $method, 'amount' => $final, 'ref_id' => $refId, 'note' => $note, 'created_by' => $by->id]);
            if ($method === 'card') {
                // the level reached before this payment gives its cashback; crossing a level starts the next one
                $before = Cards::level($venue, Cards::spent($card));
                $this->cards->entry($card, -$final, 'pay', $by, $note, $payment);
                $back = (int) floor($final * $before['pct'] / 100);
                if ($back > 0) {
                    $this->cards->entry($card, $back, 'cashback', $by, null, $payment);
                }
                $after = Cards::level($venue, $before['spent'] + $final);
                if ($after['pct'] > $before['pct']) {
                    DB::afterCommit(fn () => $player->notify(new Notice('card_level',
                        ['venue' => $venue->name, 'pct' => $after['pct']], Cards::link($venue))));
                }
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
        DB::afterCommit(fn () => $player->notify(new Notice('card_paid',
            ['venue' => $venue->name, 'amount' => Cards::amd($amount), 'balance' => Cards::amd($balance)], Cards::link($venue))));
    }
}
