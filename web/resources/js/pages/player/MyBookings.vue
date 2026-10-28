<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import Avatar from '@/components/Avatar.vue';
import StatusPill from '@/components/player/StatusPill.vue';
import TeamSheet, { type FriendRow } from '@/components/player/TeamSheet.vue';
import TelegramBanner from '@/components/player/TelegramBanner.vue';
import { ask } from '@/composables/useConfirm';
import { amd, useShared } from '@/composables/useShared';
import { dateLong, dayDiff, directionLinks, nextLabel, yerevanToday } from '@/composables/usePlayer';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import type { PlayerBooking } from '@/types/player';
import { Head, Link, router } from '@inertiajs/vue3';
import { trans } from '@/lib/i18n';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

// My bookings: the title, then upcoming bookings grouped under day headers (no past bookings), in compact cards.
// Also the games a friend added you to ("Booked by Aram", with "Leave"). The booker adds the team (TeamSheet): they get
// the reminders too. "Running late?" chips from 3 h before until the grace time after the start tell the place.
// Each card: "Directions" (a menu with Google Maps / Yandex Maps) and "Call place"; Cancel in the ⋯ menu with a
// confirmation sheet. Then the "notify me if it frees up" times. No profile block: logout lives in Profile.
interface AlertRow {
    id: number;
    venue: string;
    slug: string;
    field: number;
    size: string;
    format: string;
    date: string;
    start: string;
    minutes: number;
    notified: boolean;
}
const props = defineProps<{
    upcoming: PlayerBooking[];
    alerts: AlertRow[];
    friends: FriendRow[];
    openTeam: number | null;
}>();
const { user } = useShared();

const now = ref(Date.now());
let timer: number | undefined;
const todayIso = computed(() => yerevanToday(now.value));

// ---------- day groups: Today / Tomorrow / This week / Later ----------
function groupKey(b: PlayerBooking): string {
    const d = dayDiff(todayIso.value, b.date);
    return d <= 0 ? 'today' : d === 1 ? 'tomorrow' : d < 7 ? 'week' : 'later';
}
const groups = computed(() => {
    const out: { key: string; items: PlayerBooking[] }[] = [];
    for (const b of props.upcoming) {
        const k = groupKey(b);
        const last = out[out.length - 1];
        if (last && last.key === k) last.items.push(b);
        else out.push({ key: k, items: [b] });
    }
    return out;
});
/** the date inside a card, only where the header does not say it */
const showDate = (b: PlayerBooking) => !['today', 'tomorrow'].includes(groupKey(b));

// ---------- status and time hints ----------
const playing = (b: PlayerBooking) => b.status === 'accepted' && new Date(b.startsAt).getTime() <= now.value && new Date(b.endsAt).getTime() > now.value;
/** "in 2 h 10 min": only when less than 3 hours remain */
function soon(b: PlayerBooking): string | null {
    const ms = new Date(b.startsAt).getTime() - now.value;
    if (ms <= 0 || ms >= 3 * 3600e3) return null;
    const m = Math.max(1, Math.round(ms / 60e3));
    const h = Math.floor(m / 60);
    return h ? `${h} ${trans('common.hour_short')}${m % 60 ? ` ${m % 60} ${trans('common.minute_short')}` : ''}` : `${m} ${trans('common.minute_short')}`;
}
const yerevanTime = (ms: number) => new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Yerevan', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(ms));
/** "Free cancellation until Thu 18:00": only for confirmed bookings, within 24 h of that deadline */
function freeUntil(b: PlayerBooking): string | null {
    if (b.status !== 'accepted' || !b.canCancel || !b.freeCancelHours) return null;
    const deadline = new Date(b.startsAt).getTime() - b.freeCancelHours * 3600e3;
    const left = deadline - now.value;
    if (left <= 0 || left >= 24 * 3600e3) return null;
    return nextLabel(yerevanToday(deadline), todayIso.value, yerevanTime(deadline), true);
}
const directions = (b: PlayerBooking) => directionLinks({ ...b.venue });
const placeUrl = (b: PlayerBooking) => route('places.show', { venue: b.venue.slug, field: b.field.id, minutes: b.minutes });
const alertUrl = (a: AlertRow) => route('places.show', { venue: a.slug, date: a.date, field: a.field, minutes: a.minutes, start: a.start });

// ---------- ⋯ menu and cancel ----------
/** open menu: `more-<id>` (the ⋯ menu) or `dir-<id>` (Directions) */
const menuFor = ref<string | null>(null);
const toggle = (key: string) => (menuFor.value = menuFor.value === key ? null : key);
const cancelling = ref<PlayerBooking | null>(null);
const busy = ref(false);
function askCancel(b: PlayerBooking) {
    menuFor.value = null;
    cancelling.value = b;
}
function cancel() {
    const b = cancelling.value;
    if (!b) return;
    busy.value = true;
    router.post(
        route('bookings.cancel', b.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
                cancelling.value = null;
            },
        },
    );
}
// ---------- the team ----------
const teamFor = ref<number | null>(props.openTeam);   // ?team=<id> right after booking opens it
const teamBooking = computed(() => props.upcoming.find((b) => b.id === teamFor.value && b.role === 'booker') ?? null);
const teamPeople = (b: PlayerBooking) => (b.team ?? []).filter((p) => p.status === 'joined').map((p) => p.user);
const waitingAnswers = (b: PlayerBooking) => (b.team ?? []).filter((p) => p.status === 'invited').length;
function answer(b: PlayerBooking, accept: boolean) {
    router.post(route('bookings.team.respond', b.id), { accept }, { preserveScroll: true });
}
async function leave(b: PlayerBooking) {
    menuFor.value = null;
    if (await ask({ title: trans('player.team.leave_ask'), danger: true })) router.post(route('bookings.leave', b.id), {}, { preserveScroll: true });
}

// ---------- running late ----------
const lateOpen = ref<number | null>(null);
function sayLate(b: PlayerBooking, minutes: number) {
    router.post(route('bookings.late', b.id), { minutes }, { preserveScroll: true, onSuccess: () => (lateOpen.value = null) });
}

function removeAlert(a: AlertRow) {
    router.delete(route('alerts.destroy', a.id), { preserveScroll: true });
}
function onDoc(e: MouseEvent) {
    if (menuFor.value != null && !(e.target as HTMLElement).closest('.menu-wrap')) menuFor.value = null;
}
function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') {
        cancelling.value = null;
        menuFor.value = null;
    }
}
onMounted(() => {
    timer = window.setInterval(() => (now.value = Date.now()), 30_000);
    document.addEventListener('keydown', onKey);
    document.addEventListener('click', onDoc);
});
onBeforeUnmount(() => {
    clearInterval(timer);
    document.removeEventListener('keydown', onKey);
    document.removeEventListener('click', onDoc);
});
const canLinkTelegram = computed(() => {
    try {
        return route().has('telegram.link');
    } catch {
        return false;
    }
});
</script>

<template>
    <PlayerLayout>
        <Head :title="$t('common.my_bookings')" />

        <h1 class="page-title mtitle">{{ $t('common.my_bookings') }}</h1>

        <TelegramBanner v-if="user && !user.telegram && canLinkTelegram" class="tgban" />

        <section v-for="g in groups" :key="g.key" class="grp">
            <h2 class="dayh">{{ $t(`player.my_bookings.group.${g.key}`) }}</h2>
            <article v-for="b in g.items" :key="b.id" class="card bk">
                <div class="bk-time">
                    <b>{{ b.start }}</b>
                    <small>{{ b.end }}</small>
                </div>
                <div class="bk-main">
                    <div class="bk-l1">
                        <h3><Link :href="route('places.show', { venue: b.venue.slug })">{{ b.venue.name }}</Link></h3>
                        <div v-if="b.canCancel || b.role === 'member'" class="menu-wrap">
                            <button
                                type="button"
                                class="menu-btn"
                                aria-haspopup="menu"
                                :aria-expanded="menuFor === `more-${b.id}`"
                                :aria-label="$t('common.more')"
                                @click="toggle(`more-${b.id}`)"
                            >
                                <Icon name="more" />
                            </button>
                            <div v-if="menuFor === `more-${b.id}`" class="menu" role="menu">
                                <Link role="menuitem" :href="placeUrl(b)"><Icon name="calendar" size="sm" />{{ $t('player.my_bookings.open_place') }}</Link>
                                <button v-if="b.role === 'booker'" role="menuitem" type="button" @click="(menuFor = null), (teamFor = b.id)">
                                    <Icon name="users" size="sm" />{{ $t('common.team') }}
                                </button>
                                <button v-if="b.canCancel" role="menuitem" type="button" class="danger" @click="askCancel(b)">
                                    <Icon name="x" size="sm" />{{ b.status === 'pending' ? $t('player.my_bookings.withdraw') : $t('player.my_bookings.cancel') }}
                                </button>
                                <button v-if="b.role === 'member'" role="menuitem" type="button" class="danger" @click="leave(b)">
                                    <Icon name="logout" size="sm" />{{ $t('player.team.leave') }}
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="bk-l2">
                        <template v-if="showDate(b)">{{ dateLong(b.date) }} · </template>
                        <span class="fz"><i :class="['field-dot', b.field.size]" />{{ b.field.format }}</span> · {{ $t(`common.districts.${b.venue.district}`) }} · {{ amd(b.price) }}
                    </div>
                    <div class="bk-l3">
                        <span v-if="playing(b)" class="status-pill confirmed">{{ $t('player.my_bookings.playing_now') }}</span>
                        <span v-else-if="b.status === 'pending'" class="status-pill pending">{{ $t('player.my_bookings.waiting') }}</span>
                        <StatusPill v-else :status="b.status" />
                        <span v-if="soon(b) && !playing(b)" class="soon"><Icon name="clock" size="sm" />{{ $t('player.my_bookings.starts_in', { t: soon(b) ?? '' }) }}</span>
                        <span v-if="b.status === 'accepted'" class="code" :title="$t('common.code')">{{ b.code }}</span>
                    </div>
                    <div v-if="b.teamName || b.note" class="extra">
                        <template v-if="b.teamName">{{ $t('player.my_bookings.team', { n: b.teamName }) }}</template>
                        <template v-if="b.teamName && b.note"> · </template>
                        <template v-if="b.note">“{{ b.note }}”</template>
                    </div>
                    <div v-if="b.status === 'declined' && b.declineReason" class="extra hot">{{ $t('player.my_bookings.reason', { r: b.declineReason }) }}</div>
                    <div v-if="b.status === 'cancelled' && b.cancelledBy && b.cancelledBy !== 'player'" class="extra">
                        {{ b.cancelledBy === 'owner' ? $t('player.my_bookings.cancelled_by_place') : $t('player.my_bookings.cancelled_by_system') }}<template v-if="b.cancelReason"> · {{ $t('player.my_bookings.reason', { r: b.cancelReason }) }}</template>
                    </div>
                    <!-- a friend's invite: accept to get the reminders -->
                    <div v-if="b.role === 'invited'" class="invite">
                        <span class="by"
                            ><Avatar :id="b.booker?.id" :name="b.booker?.name" :src="b.booker?.avatar" size="xs" />{{
                                $t('player.team.invites_you', { name: b.booker?.name ?? '' })
                            }}</span
                        >
                        <div class="row" style="gap: 8px">
                            <button type="button" class="btn sm" @click="answer(b, true)"><Icon name="check" size="sm" />{{ $t('player.team.accept') }}</button>
                            <button type="button" class="btn ghost sm" @click="answer(b, false)">{{ $t('player.team.decline') }}</button>
                        </div>
                    </div>
                    <!-- the team: avatars, "Booked by" for members, "Add your team" for the booker -->
                    <div v-if="b.role && b.role !== 'invited'" class="teamrow">
                        <span v-if="b.role === 'member' && b.booker" class="by">
                            <Avatar :id="b.booker.id" :name="b.booker.name" :src="b.booker.avatar" size="xs" />{{ $t('player.team.booked_by', { name: b.booker.name ?? '' }) }}
                        </span>
                        <span v-if="teamPeople(b).length" class="faces" :title="teamPeople(b).map((u) => u.name).join(', ')">
                            <Avatar v-for="u in teamPeople(b).slice(0, 5)" :id="u.id" :key="u.id" :name="u.name" :src="u.avatar" size="xs" />
                            <small>{{ $t('player.team.in_team', { n: String(teamPeople(b).length + 1) }) }}</small>
                        </span>
                        <button v-if="b.role === 'booker'" type="button" :class="['btn sm', teamPeople(b).length ? 'link' : 'ghost']" @click="teamFor = b.id">
                            <Icon name="users" size="sm" />{{ teamPeople(b).length ? $t('common.team') : $t('player.team.add') }}
                        </button>
                        <small v-if="b.role === 'booker' && waitingAnswers(b)" class="muted">{{ $t('player.team.waiting_count', { n: String(waitingAnswers(b)) }) }}</small>
                    </div>
                    <p v-if="b.role === 'booker' && !(b.team ?? []).length && b.status !== 'cancelled'" class="hint-team">{{ $t('player.team.nudge') }}</p>
                    <!-- running late? -->
                    <div v-if="b.lateMinutes" class="late-said"><Icon name="clock" size="sm" />{{ $t('player.late.said', { min: String(b.lateMinutes) }) }}</div>
                    <div v-if="b.canLate && b.role !== 'invited'" class="late">
                        <button v-if="lateOpen !== b.id" type="button" class="btn outline sm" @click="lateOpen = b.id"><Icon name="clock" size="sm" />{{ $t('player.late.ask') }}</button>
                        <div v-else class="chips" role="group" :aria-label="$t('player.late.ask')">
                            <button v-for="m in b.lateOptions ?? []" :key="m" type="button" class="chip" @click="sayLate(b, m)">~{{ m }} {{ $t('common.minute_short') }}</button>
                            <button type="button" class="chip" :aria-label="$t('common.close')" @click="lateOpen = null"><Icon name="x" size="sm" /></button>
                        </div>
                    </div>
                    <div v-if="freeUntil(b)" class="rule"><Icon name="check" size="sm" />{{ $t('player.my_bookings.free_until', { t: freeUntil(b) ?? '' }) }}</div>
                    <div class="acts">
                        <div v-if="directions(b).google" class="menu-wrap">
                            <button type="button" class="btn outline sm" aria-haspopup="menu" :aria-expanded="menuFor === `dir-${b.id}`" @click="toggle(`dir-${b.id}`)">
                                <Icon name="directions" size="sm" />{{ $t('common.directions') }}
                            </button>
                            <div v-if="menuFor === `dir-${b.id}`" class="menu left" role="menu">
                                <a role="menuitem" :href="directions(b).google!" target="_blank" rel="noopener" @click="menuFor = null"><Icon name="map" size="sm" />{{ $t('player.place.map_google') }}</a>
                                <a v-if="directions(b).yandex" role="menuitem" :href="directions(b).yandex!" target="_blank" rel="noopener" @click="menuFor = null"><Icon name="map" size="sm" />{{ $t('player.place.map_yandex') }}</a>
                            </div>
                        </div>
                        <a v-if="b.venue.phone" class="btn outline sm" :href="`tel:${b.venue.phone}`"><Icon name="phone" size="sm" />{{ $t('player.my_bookings.call_place') }}</a>
                    </div>
                </div>
            </article>
        </section>

        <div v-if="!upcoming.length" class="empty-state">
            <span class="es-ico"><Icon name="calendar" /></span>
            <p>{{ $t('player.my_bookings.empty_upcoming') }}</p>
            <Link class="btn sm" :href="route('home')"><Icon name="search" size="sm" />{{ $t('common.find_a_field') }}</Link>
        </div>

        <!-- "notify me if it frees up" -->
        <section v-if="alerts.length" class="grp">
            <h2 class="dayh">{{ $t('player.alert.list_title') }}</h2>
            <div class="card alerts">
                <div v-for="a in alerts" :key="a.id" class="arow">
                    <Icon name="bell" size="sm" :class="['abell', { on: a.notified }]" />
                    <div class="amain">
                        <b>{{ a.venue }}</b>
                        <span class="muted">
                            <i :class="['field-dot', a.size]" /> {{ a.format }} · {{ nextLabel(a.date, todayIso, a.start) }}
                        </span>
                    </div>
                    <Link v-if="a.notified" class="btn sm" :href="alertUrl(a)">{{ $t('player.alert.free_now') }} <Icon name="arrow" size="sm" /></Link>
                    <span v-else class="muted await">{{ $t('player.alert.waiting_short') }}</span>
                    <button type="button" class="ax" :aria-label="$t('common.remove')" :title="$t('common.remove')" @click="removeAlert(a)"><Icon name="x" size="sm" /></button>
                </div>
            </div>
        </section>

        <TeamSheet v-if="teamBooking" :booking="teamBooking" :friends="friends" @close="teamFor = null" />

        <!-- cancel -->
        <div v-if="cancelling" class="scrim" @click.self="cancelling = null">
            <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="ct">
                <div class="row">
                    <h3 id="ct">{{ cancelling.status === 'pending' ? $t('player.my_bookings.cancel_title_request') : $t('player.my_bookings.cancel_title') }}</h3>
                    <button type="button" class="x" :aria-label="$t('common.close')" @click="cancelling = null"><Icon name="x" /></button>
                </div>
                <div class="sum-box">
                    <i :class="['field-dot', cancelling.field.size]" />
                    <div style="flex: 1; min-width: 0">
                        <b>{{ cancelling.venue.name }} · {{ cancelling.field.format }}</b>
                        <div class="muted" style="font-size: 13.5px">{{ dateLong(cancelling.date) }} · {{ cancelling.start }}–{{ cancelling.end }}</div>
                    </div>
                    <span class="price">{{ amd(cancelling.price) }}</span>
                </div>
                <div :class="['policy', cancelling.lateCancel ? 'late' : 'ok']">
                    <Icon :name="cancelling.lateCancel ? 'clock' : 'check'" size="sm" />
                    <span v-if="cancelling.status === 'pending'">{{ $t('player.my_bookings.cancel_request') }}</span>
                    <span v-else-if="cancelling.lateCancel">{{ $t('player.my_bookings.cancel_late', { h: String(cancelling.freeCancelHours) }) }}</span>
                    <span v-else>{{ $t('player.my_bookings.cancel_free', { h: String(cancelling.freeCancelHours) }) }}</span>
                </div>
                <div class="row">
                    <button type="button" class="btn ghost" style="flex: 1" @click="cancelling = null">{{ $t('common.keep_it') }}</button>
                    <button type="button" class="btn red" style="flex: 1" :disabled="busy" @click="cancel">{{ $t('common.yes_cancel') }}</button>
                </div>
            </div>
        </div>
    </PlayerLayout>
</template>

<style scoped>
.mtitle { font-size: clamp(24px, 3vw, 32px); margin: 22px 0 12px; }
.tgban { margin-bottom: 10px; max-width: 820px; }

.grp { max-width: 820px; }
.dayh { font-family: var(--body); font-size: 14px; font-weight: 800; letter-spacing: 0; color: var(--muted); margin: 20px 0 8px; }
.bk { display: grid; grid-template-columns: 64px minmax(0, 1fr); gap: 14px; padding: 12px 12px 12px 14px; margin-bottom: 8px; }
.bk-time { display: flex; flex-direction: column; align-items: flex-start; padding-top: 1px; }
.bk-time b { font-size: 19px; font-weight: 800; line-height: 1.15; font-variant-numeric: tabular-nums; }
.bk-time small { color: var(--muted); font-size: 13px; font-weight: 700; font-variant-numeric: tabular-nums; }
.bk-main { min-width: 0; display: flex; flex-direction: column; gap: 4px; }
.bk-l1 { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; min-width: 0; }
.bk-l1 h3 { font-family: var(--body); font-size: 16px; font-weight: 800; letter-spacing: 0; min-width: 0; overflow-wrap: anywhere; padding-top: 2px; }
.bk-l1 h3 a:hover { color: var(--accent); }
.bk-l1 .menu-wrap { margin: -6px -4px -6px 0; }
.bk-l2 { color: var(--muted); font-size: 13.5px; }
.bk-l2 .fz { display: inline-flex; align-items: center; gap: 5px; color: var(--ink); font-weight: 700; }
.bk-l3 { display: flex; flex-wrap: wrap; gap: 6px 10px; align-items: center; margin-top: 2px; }
.soon { display: inline-flex; gap: 5px; align-items: center; font-size: 12.5px; font-weight: 800; color: var(--ink); }
.code { font-family: var(--mono); font-size: 12.5px; color: var(--muted); letter-spacing: .06em; }
.extra { font-size: 13px; color: var(--muted); overflow-wrap: anywhere; }
.extra.hot { color: var(--hot); }
.rule { display: flex; gap: 6px; align-items: center; font-size: 12.5px; font-weight: 700; color: var(--st-confirmed); }
.acts { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
.teamrow { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; margin-top: 4px; font-size: 13px; }
.teamrow .by { display: inline-flex; align-items: center; gap: 6px; color: var(--muted); font-weight: 700; }
.faces { display: inline-flex; align-items: center; }
.faces :deep(.avatar-c) { margin-right: -6px; box-shadow: 0 0 0 2px var(--surface); }
.faces small { margin-left: 12px; color: var(--muted); font-weight: 700; }
.teamrow .btn.link { padding-left: 0; }
.hint-team { margin: 0; font-size: 12.5px; color: var(--muted); }
.invite { display: flex; flex-direction: column; gap: 8px; margin-top: 4px; padding: 10px 12px; border-radius: 12px; background: var(--surface-2); }
.invite .by { display: inline-flex; align-items: center; gap: 6px; font-weight: 700; font-size: 13.5px; }
.late { margin-top: 4px; }
.late .chips { display: flex; flex-wrap: wrap; gap: 6px; }
.late-said { display: inline-flex; gap: 6px; align-items: center; font-size: 12.5px; font-weight: 700; color: var(--st-pending); }
.acts:empty { display: none; }

.alerts { padding: 4px 12px; }
.arow { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--line); }
.arow:last-child { border: 0; }
.abell { color: var(--muted); }
.abell.on { color: var(--st-confirmed); }
.amain { flex: 1; min-width: 0; display: flex; flex-direction: column; font-size: 14px; }
.amain .muted { font-size: 13px; display: inline-flex; align-items: center; gap: 5px; flex-wrap: wrap; }
.await { font-size: 12.5px; white-space: nowrap; }
.ax { flex: none; width: 30px; height: 30px; border-radius: 8px; border: 0; background: none; color: var(--muted); display: grid; place-items: center; }
.ax:hover { background: var(--surface-2); color: var(--hot); }
@media (max-width: 480px) { .await { display: none; } }

.empty-state { margin-top: 16px; max-width: 820px; }
.sum-box { background: var(--bg-2); border-radius: 14px; padding: 14px; display: flex; gap: 12px; align-items: center; margin: 14px 0; }
.policy { display: flex; gap: 10px; padding: 12px 14px; border-radius: 12px; font-size: 13.5px; margin-bottom: 16px; }
.policy .ico { margin-top: 3px; }
.policy.ok { background: var(--st-confirmed-soft); color: var(--st-confirmed); }
.policy.late { background: var(--st-pending-soft); color: var(--st-pending); }
.btn.red { background: var(--hot); color: #200606; }
.btn.red:hover { box-shadow: 0 10px 26px -10px rgba(255, 107, 107, 0.6); }
</style>
