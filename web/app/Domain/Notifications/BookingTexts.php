<?php

namespace App\Domain\Notifications;

use App\Enums\FieldSize;
use App\Models\Booking;
use App\Models\User;
use App\Support\LocalTime;
use App\Support\Phone;
use Carbon\CarbonImmutable;

/** Telegram texts about a booking, in the current app locale (lang/{en,hy,ru}/telegram.php). */
class BookingTexts
{
    /** Message for the player: requested | accepted | declined | changed | cancelled | expired */
    public static function player(Booking $b, string $type, array $changes = []): TelegramMessage
    {
        $text = __('telegram.player.'.$type, ['venue' => e($b->venue->name), 'code' => $b->code])."\n\n".self::details($b);
        if ($type === 'declined' && $b->decline_reason) {
            $text .= "\n\n".__('telegram.reason', ['reason' => e($b->decline_reason)]);
        }
        if ($type === 'cancelled' && $b->cancel_reason) {
            $text .= "\n\n".__('telegram.reason', ['reason' => e($b->cancel_reason)]);
        }
        if ($type === 'changed' && isset($changes['starts_at'][0])) {
            $old = CarbonImmutable::parse($changes['starts_at'][0]);
            $text .= "\n\n".__('telegram.was', ['when' => self::date($old).', '.$old->setTimezone(LocalTime::tz())->format('H:i')]);
        }

        return new TelegramMessage('booking.'.$type, $text);
    }

    /** Message for owners/staff: requested (with Accept / Decline buttons) | cancelled (by the player) */
    public static function owner(Booking $b, string $type, bool $phone = true): TelegramMessage
    {
        $text = __('telegram.owner.'.$type, ['venue' => e($b->venue->name)])."\n\n".self::details($b)."\n".self::contact($b, $phone);
        if ($b->note_player) {
            $text .= "\n💬 ".e($b->note_player);
        }
        $buttons = $type === 'requested'
            ? [[['✅ '.__('common.accept'), 'accept:'.$b->id], ['✖ '.__('common.decline'), 'decline:'.$b->id]]]
            : [];

        return new TelegramMessage('venue.'.$type, $text, $buttons);
    }

    /** The owner's request message after a button was used: same text + "✅ Accepted by Aram". */
    public static function ownerDecided(Booking $b, User $by): string
    {
        $key = $b->status->value === 'accepted' ? 'accepted_by' : ($b->status->value === 'declined' ? 'declined_by' : 'status_now');

        return self::owner($b, 'requested')->text."\n\n".__('telegram.owner.'.$key, ['name' => e($by->name ?: Phone::format($by->phone)), 'status' => __('telegram.status.'.$b->status->value)]);
    }

    public static function details(Booking $b): string
    {
        $start = $b->starts_at->toImmutable()->setTimezone(LocalTime::tz());
        $end = $b->ends_at->toImmutable()->setTimezone(LocalTime::tz());

        return implode("\n", [
            '📍 <b>'.e($b->venue->name).'</b> · '.self::field($b->field->size),
            '🗓 '.self::date($start).' · '.$start->format('H:i').'–'.$end->format('H:i'),
            '💰 '.self::amd($b->price),
            '🔖 '.$b->code,
        ]);
    }

    /** $phone false: push notifications, shown on the lock screen */
    public static function contact(Booking $b, bool $phone = true): string
    {
        return '👤 '.e($b->team_name ? $b->contact_name.' ('.$b->team_name.')' : $b->contact_name).($phone && $b->contact_phone ? ' · '.Phone::format($b->contact_phone) : '');
    }

    public static function field(FieldSize $size): string
    {
        return __('telegram.field.'.$size->value);
    }

    public static function date(CarbonImmutable $t): string
    {
        return $t->setTimezone(LocalTime::tz())->locale(app()->getLocale())->isoFormat('dd, D MMMM');
    }

    public static function amd(int $n): string
    {
        return number_format($n, 0, '.', ' ').' ֏';
    }
}
