<?php

namespace App\Domain\Club;

use App\Domain\Booking\BookingException;
use App\Models\CardEntry;
use App\Models\Payment;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueCard;
use App\Notifications\Notice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Player cards: one per player per place. The QR holds a random code, so it says nothing about the player,
 * can't be guessed, and a card of one place opens nothing at another (the code is looked up within the place).
 * The balance is prepaid money the place keeps for the player (like a gym card); every change is a card entry.
 */
class Cards
{
    public function forPlayer(Venue $venue, User $user): VenueCard
    {
        $this->ensureFeature($venue);

        $card = VenueCard::firstOrCreate(['venue_id' => $venue->id, 'user_id' => $user->id], ['code' => self::newCode()]);
        if ($card->wasRecentlyCreated) {
            // where to find it: Profile → My cards (its QR, balance, history)
            $user->notify(new Notice('card_created', ['venue' => $venue->name], self::link($venue)));
        }

        return $card;
    }

    /** The card behind a scanned code, only within this place. */
    public function byCode(Venue $venue, string $code): ?VenueCard
    {
        return VenueCard::where('venue_id', $venue->id)->where('code', $code)->first();
    }

    /** The player puts money on the card (cash at the desk): a top-up payment and +amount on the card. */
    public function topUp(VenueCard $card, int $amount, User $by, ?string $note = null): Payment
    {
        if ($amount <= 0 || $amount > 10_000_000) {
            throw new BookingException('bad_amount');
        }

        return DB::transaction(function () use ($card, $amount, $by, $note) {
            $card = VenueCard::lockForUpdate()->find($card->id);
            $payment = Payment::create(['venue_id' => $card->venue_id, 'user_id' => $card->user_id, 'card_id' => $card->id,
                'kind' => 'topup', 'method' => 'cash', 'amount' => $amount, 'note' => $note, 'created_by' => $by->id]);
            $this->entry($card, $amount, 'topup', $by, $note, $payment);
            $card->loadMissing('user', 'venue');
            $balance = $card->fresh()->balance;
            DB::afterCommit(fn () => $card->user?->notify(new Notice('card_topup', ['venue' => $card->venue->name, 'amount' => self::amd($amount), 'balance' => self::amd($balance)], self::link($card->venue))));

            return $payment;
        });
    }

    /** A correction by the place (a mistake, a gift): any sign, with a reason. No payment: no money came in. */
    public function adjust(VenueCard $card, int $amount, User $by, string $note): CardEntry
    {
        if ($amount === 0 || abs($amount) > 10_000_000) {
            throw new BookingException('bad_amount');
        }

        return DB::transaction(function () use ($card, $amount, $by, $note) {
            $card = VenueCard::lockForUpdate()->find($card->id);
            if ($card->balance + $amount < 0) {
                throw new BookingException('low_balance');
            }

            return $this->entry($card, $amount, 'adjust', $by, $note);
        });
    }

    /** A line of history and the new balance (the card row is locked by the caller). */
    public function entry(VenueCard $card, int $amount, string $kind, ?User $by, ?string $note = null, ?Payment $payment = null): CardEntry
    {
        $card->increment('balance', $amount);

        return CardEntry::create(['card_id' => $card->id, 'amount' => $amount, 'kind' => $kind, 'payment_id' => $payment?->id,
            'note' => $note, 'created_by' => $by?->id]);
    }

    // ---------- cashback: grows with what the card has paid at the place ----------

    /** The place's levels, lowest first: [['from' => 50000, 'pct' => 3], …]. Below the first level: no cashback. */
    public static function tiers(Venue $venue): array
    {
        return self::cleanTiers((array) $venue->rule('cashback_tiers'));
    }

    /** Whole numbers, sorted by "from", one level per "from", at most 8. */
    public static function cleanTiers(array $tiers): array
    {
        return collect($tiers)->filter(fn ($t) => is_array($t) && isset($t['from'], $t['pct']))
            ->map(fn ($t) => ['from' => max(0, (int) $t['from']), 'pct' => max(0, min(50, (int) $t['pct']))])
            ->sortBy('from')->unique('from')->take(8)->values()->all();
    }

    /** Everything the card's player has paid at its place, from the card or in cash (top-ups and corrections don't count). */
    public static function spent(VenueCard $card): int
    {
        return (int) Payment::where('card_id', $card->id)->where('kind', '!=', 'topup')->sum('amount');
    }

    /**
     * The card's level after $spent paid: ['pct' => 3, 'spent' => 62000, 'next' => ['from' => 150000, 'pct' => 5, 'left' => 88000] | null].
     */
    public static function level(Venue $venue, int $spent): array
    {
        $pct = 0;
        $next = null;
        foreach (self::tiers($venue) as $t) {
            if ($spent >= $t['from']) {
                $pct = $t['pct'];
            } elseif ($t['pct'] > $pct) {
                $next = $t + ['left' => $t['from'] - $spent];
                break;
            }
        }

        return ['pct' => $pct, 'spent' => $spent, 'next' => $next];
    }

    public static function levelOf(VenueCard $card): array
    {
        $card->loadMissing('venue');

        return self::level($card->venue, self::spent($card));
    }

    public function ensureFeature(Venue $venue): void
    {
        // the card (and its QR) also carries the rating, so a place with only ratings has cards too
        if (! $venue->hasFeature('wallet') && ! $venue->hasFeature('rating')) {
            throw new BookingException('feature_off');
        }
    }

    /** How long a scan lets the staff change a card's money (the player stood at the desk with their phone). */
    public const SCAN_MINUTES = 15;

    /** The staff scanned this card (or typed the code from the player's screen): money changes are open for a while. */
    public static function markScanned(VenueCard $card): void
    {
        session()->put('scanned.'.$card->code, time());
    }

    public static function scanned(VenueCard $card): bool
    {
        $at = session()->get('scanned.'.$card->code);

        return $at !== null && time() - (int) $at < self::SCAN_MINUTES * 60;
    }

    /** "My cards", opened at this place's card. */
    public static function link(Venue $venue): string
    {
        return '/me/cards?venue='.$venue->slug;
    }

    public static function amd(int $n): string
    {
        return number_format($n, 0, '.', ' ').' ֏';
    }

    public static function newCode(): string
    {
        return Str::lower(Str::random(20));
    }
}
