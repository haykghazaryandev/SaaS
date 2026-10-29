<?php

namespace App\Models;

use App\Domain\Social\Presence;
use App\Enums\VenueRole;
use App\Support\Phone;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * One phone number = one user. Logged in by Telegram, email code, username + password or Google;
 * accounts without a phone must add one on the welcome page (RequirePhone). A user can be a player and also owner/staff of places (venue_users).
 */
class User extends Authenticatable implements FilamentUser, HasLocalePreference, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'phone', 'email', 'email_verified_at', 'name', 'username', 'locale', 'avatar_path', 'position', 'district', 'bio',
        'telegram_chat_id', 'telegram_linked_at', 'phone_verified_at', 'registered_at', 'last_seen_at',
    ];

    protected $hidden = ['remember_token', 'telegram_chat_id', 'password', 'google_id'];

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'password' => 'hashed',
            'telegram_chat_id' => 'integer',
            'telegram_linked_at' => 'datetime',
            'telegram_verified_at' => 'datetime',
            'notify_prefs' => 'array',
            'locale_chosen_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'registered_at' => 'datetime',
            'blocked_until' => 'datetime',
            'booking_ban_until' => 'datetime',
            'penalty_reset_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Mark the user as seen now (TouchLastSeen; "online" in chats and isActiveNow() read it), at most once per $every seconds (called on every request, so it must stay cheap).
     * A plain query update: no updated_at, no model events.
     */
    public function touchLastSeen(int $every = 60): void
    {
        $now = now();
        if ($this->last_seen_at !== null && $this->last_seen_at->gt($now->copy()->subSeconds($every))) {
            return;
        }
        static::query()->whereKey($this->id)->toBase()->update(['last_seen_at' => $now]);
        $this->last_seen_at = $now;
        $this->syncOriginalAttribute('last_seen_at');
    }

    /** Places this user works for (owner or staff). */
    public function venues(): BelongsToMany
    {
        return $this->belongsToMany(Venue::class, 'venue_users')->withPivot('role')->withTimestamps();
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function gamePlayers(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }

    /** Accepted friends (both directions). */
    public function friendIds(): array
    {
        return Friendship::query()->where('status', 'accepted')
            ->where(fn ($q) => $q->where('user_id', $this->id)->orWhere('friend_id', $this->id))
            ->get(['user_id', 'friend_id'])
            ->map(fn ($f) => $f->user_id === $this->id ? $f->friend_id : $f->user_id)->all();
    }

    public function isFriendOf(User|int $other): bool
    {
        $id = $other instanceof User ? $other->id : $other;

        return Friendship::between($this->id, $id)?->status === 'accepted';
    }

    /** ADMIN_PHONE is always the admin, also after the account was deleted and registered again. */
    public function syncAdmin(): void
    {
        $admin = Phone::normalize((string) config('app.admin_phone'));
        if ($admin && $this->phone === $admin && ! $this->is_admin) {
            $this->forceFill(['is_admin' => true])->save();
        }
    }

    /** Telegram message categories a person can switch off (Profile → Notifications); all on by default. */
    public const NOTIFY_CATEGORIES = ['bookings', 'games', 'friends', 'chats', 'alerts', 'requests'];

    public function wantsTelegram(string $category): bool
    {
        return (bool) (($this->notify_prefs ?? [])[$category] ?? true);
    }

    /** A sound in the app for new notices and chat messages. */
    public function wantsSound(): bool
    {
        return (bool) (($this->notify_prefs ?? [])['sound'] ?? true);
    }

    /** Using Bookball right now (a page in the last 2 minutes): no need to repeat social news by Telegram. */
    public function isActiveNow(): bool
    {
        // the live list of open apps (closing the app = offline at once); without Reverb: seen in the last 2 minutes
        $online = app(Presence::class)->onlineIds();
        if ($online !== null) {
            return in_array($this->id, $online, true);
        }

        return $this->last_seen_at !== null && $this->last_seen_at->greaterThan(now()->subMinutes(2));
    }

    /** Too many no-shows: no new booking requests until booking_ban_until (Penalties). */
    public function isBookingBanned(): bool
    {
        return $this->booking_ban_until !== null && $this->booking_ban_until->isFuture();
    }

    public function isRegistered(): bool
    {
        return $this->registered_at !== null;
    }

    public function isBlocked(): bool
    {
        return $this->status === 'blocked' || ($this->blocked_until && $this->blocked_until->isFuture());
    }

    public function hasTelegram(): bool
    {
        return $this->telegram_chat_id !== null;
    }

    public function roleAt(Venue|int $venue): ?VenueRole
    {
        $id = $venue instanceof Venue ? $venue->id : $venue;
        $role = $this->venues->firstWhere('id', $id)?->pivot->role;

        return $role ? VenueRole::from($role) : null;
    }

    public function worksAt(Venue|int $venue): bool
    {
        return $this->roleAt($venue) !== null;
    }

    /** Admin panel (/admin, Filament): only the product owner (is_admin) can open it. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_admin && ! $this->isBlocked();
    }

    public function getFilamentName(): string
    {
        return $this->name ?: Phone::format($this->phone);
    }

    /** Notifications (Telegram) are written in the user's language. */
    public function preferredLocale(): string
    {
        return in_array($this->locale, config('app.locales', ['hy', 'ru', 'en']), true) ? $this->locale : 'hy';
    }

    /** Public URL of the uploaded photo (small square), or null. */
    public function avatarUrl(): ?string
    {
        // a relative link works on every address the app is opened from (localhost, ngrok, the real domain)
        return $this->avatar_path ? '/'.ltrim($this->avatar_path, '/') : null;   // /avatars/{id}-{token}.webp (AvatarController::show)
    }

    /** Short name for lists: "Aram P." */
    public function shortName(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->name));

        return count($parts) > 1 ? $parts[0].' '.mb_substr($parts[1], 0, 1).'.' : ($parts[0] ?: '—');
    }
}
