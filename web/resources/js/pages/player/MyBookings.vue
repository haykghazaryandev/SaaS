<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import CourtSvg from '@/components/player/CourtSvg.vue';
import StatusPill from '@/components/player/StatusPill.vue';
import TelegramConnect from '@/components/TelegramConnect.vue';
import { amd } from '@/composables/useShared';
import { SIZE_COLOR, dateLong, dateRel, dateShort, pitchColor } from '@/composables/usePlayer';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import type { PlayerBooking } from '@/types/player';
import { Head, Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

// My bookings: upcoming / past, status, code, price, cancel (with the free-cancellation rule).
const props = defineProps<{
    tab: 'up' | 'past';
    profile: { name: string | null; phone: string; telegram: boolean; registered: boolean };
    upcoming: PlayerBooking[];
    past: PlayerBooking[];
    stats: { up: number; waiting: number; hours: number };
}>();

const tab = ref<'up' | 'past'>(props.tab);
const list = computed(() => (tab.value === 'up' ? props.upcoming : props.past));
const now = ref(Date.now());
let timer: number | undefined;
onMounted(() => (timer = window.setInterval(() => (now.value = Date.now()), 30_000)));
onBeforeUnmount(() => clearInterval(timer));

// "today" in Yerevan for "Today / Tomorrow" labels
const todayIso = computed(() => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Yerevan' }).format(new Date(now.value)));
const initials = (n: string | null) =>
    (n || '?')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0])
        .join('')
        .toUpperCase();

function setTab(t: 'up' | 'past') {
    tab.value = t;
    const u = new URL(window.location.href);
    if (t === 'past') u.searchParams.set('tab', 'past');
    else u.searchParams.delete('tab');
    window.history.replaceState(window.history.state, '', u.toString());
}
function until(b: PlayerBooking): string | null {
    const ms = new Date(b.startsAt).getTime() - now.value;
    if (ms <= 0) return null;
    const m = Math.round(ms / 60e3);
    const h = Math.floor(m / 60);
    const d = Math.floor(h / 24);
    if (d >= 1) return trans('player.my.days', { n: String(d) });
    return h ? `${h} ${trans('player.h')}${m % 60 ? ` ${m % 60} ${trans('player.min')}` : ''}` : `${m} ${trans('player.min')}`;
}
const playing = (b: PlayerBooking) => b.status === 'accepted' && new Date(b.startsAt).getTime() <= now.value && new Date(b.endsAt).getTime() > now.value;
const off = (b: PlayerBooking) => ['declined', 'cancelled', 'expired', 'no_show'].includes(b.status);
const when = (b: PlayerBooking) => {
    const rel = dateRel(b.date, todayIso.value);
    return rel === dateLong(b.date) ? rel : `${rel}, ${dateShort(b.date)}`;
};

// ---------- cancel ----------
const cancelling = ref<PlayerBooking | null>(null);
const busy = ref(false);
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
function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') cancelling.value = null;
}
onMounted(() => document.addEventListener('keydown', onKey));
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
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
        <Head :title="$t('player.my.title')" />

        <section class="card prof">
            <div class="bigav">{{ initials(profile.name) }}</div>
            <div style="min-width: 0">
                <span class="kicker">{{ $t('player.my.title') }}</span>
                <h1>{{ profile.name || profile.phone }}</h1>
                <div class="row wrapflex" style="gap: 8px; margin-top: 6px">
                    <span class="ph">{{ profile.phone }}</span>
                    <span v-if="profile.telegram" class="pill blue"><Icon name="send" size="sm" />{{ $t('player.my.tgOn') }}</span>
                    <span v-else class="pill">{{ $t('player.my.tgOff') }}</span>
                    <Link v-if="!profile.registered" :href="route('welcome')" class="pill lime"><Icon name="user" size="sm" />{{ $t('player.my.finish') }}</Link>
                </div>
                <Link :href="route('logout')" method="post" as="button" class="signout"><Icon name="logout" size="sm" />{{ $t('player.my.signout') }}</Link>
            </div>
            <div class="pstats">
                <div><span>{{ $t('player.my.sUp') }}</span><b>{{ stats.up }}</b></div>
                <div><span>{{ $t('player.my.sWait') }}</span><b :style="{ color: stats.waiting ? 'var(--warn)' : 'inherit' }">{{ stats.waiting }}</b></div>
                <div><span>{{ $t('player.my.sHours') }}</span><b>{{ stats.hours }}</b></div>
            </div>
        </section>

        <TelegramConnect v-if="!profile.telegram && canLinkTelegram" class="tg-card" />

        <div class="tabs" role="tablist">
            <button role="tab" :aria-selected="tab === 'up'" @click="setTab('up')"><Icon name="calendar" size="sm" />{{ $t('player.my.tUp') }}<span class="n">{{ upcoming.length }}</span></button>
            <button role="tab" :aria-selected="tab === 'past'" @click="setTab('past')"><Icon name="clock" size="sm" />{{ $t('player.my.tPast') }}<span class="n">{{ past.length }}</span></button>
            <!-- later: games, tournaments -->
        </div>

        <section>
            <article v-for="b in list" :key="b.id" :class="['card bk', { off: off(b) }]">
                <Link class="thumb" :href="route('places.show', { venue: b.venue.slug, field: b.field.id })" :aria-label="b.venue.name">
                    <div class="court-art"><CourtSvg :size="b.field.size" :color="pitchColor(b.field.surface)" /></div>
                    <div class="lights" />
                </Link>
                <div style="min-width: 0">
                    <div class="row wrapflex" style="gap: 6px; margin-bottom: 6px">
                        <span v-if="playing(b)" class="pill lime"><Icon name="ball" size="sm" />{{ $t('player.my.now') }}</span>
                        <StatusPill v-else :status="b.status" />
                        <span v-if="b.canCancel && until(b)" class="pill blue"><Icon name="clock" size="sm" />{{ $t('player.my.in', { t: until(b) ?? '' }) }}</span>
                    </div>
                    <h3><Link :href="route('places.show', { venue: b.venue.slug })">{{ b.venue.name }}</Link></h3>
                    <div class="meta">
                        <span><span class="sport-dot" :style="{ background: SIZE_COLOR[b.field.size] }" />{{ $t(`player.size.${b.field.size}`) }} {{ b.field.format }}</span>
                        <span><Icon name="pin" size="sm" />{{ $t(`player.district.${b.venue.district}`) }}</span>
                    </div>
                    <div class="when">
                        {{ when(b) }} · <span style="white-space: nowrap">{{ b.start }}–{{ b.end }}</span>
                    </div>
                    <div v-if="b.teamName || b.note" class="extra">
                        <template v-if="b.teamName">{{ $t('player.my.team', { n: b.teamName }) }}</template>
                        <template v-if="b.teamName && b.note"> · </template>
                        <template v-if="b.note">“{{ b.note }}”</template>
                    </div>
                    <div v-if="b.status === 'declined' && b.declineReason" class="extra hot">{{ $t('player.my.reason', { r: b.declineReason }) }}</div>
                    <div v-if="b.status === 'cancelled' && b.cancelledBy && b.cancelledBy !== 'player'" class="extra">
                        {{ b.cancelledBy === 'owner' ? $t('player.my.byPlace') : $t('player.my.bySystem') }}<template v-if="b.cancelReason"> · {{ $t('player.my.reason', { r: b.cancelReason }) }}</template>
                    </div>
                    <div v-if="b.status === 'pending' && b.canCancel" class="waitnote"><Icon name="send" size="sm" />{{ $t('player.my.waitNote') }}</div>
                    <div v-if="b.status === 'accepted' && b.canCancel" class="rule"><Icon name="check" size="sm" />{{ $t('player.my.freeRule', { h: b.freeCancelHours }) }}</div>
                </div>
                <div class="side">
                    <div class="pr">
                        <div class="price" style="font-size: 18px">{{ amd(b.price) }}</div>
                        <div class="row" style="gap: 6px; justify-content: flex-end; margin-top: 4px">
                            <span class="faint" style="font-size: 12px">{{ b.status === 'accepted' ? $t('player.my.code') : $t('player.my.req') }}</span><span class="code">{{ b.code }}</span>
                        </div>
                    </div>
                    <div class="acts">
                        <button v-if="b.canCancel" type="button" class="btn ghost sm danger" @click="cancelling = b"><Icon name="x" size="sm" />{{ $t('player.my.cancel') }}</button>
                        <Link class="btn ghost sm" :href="route('places.show', { venue: b.venue.slug, field: b.field.id, minutes: b.minutes })"><Icon name="calendar" size="sm" />{{ $t('player.my.again') }}</Link>
                    </div>
                </div>
            </article>
            <div v-if="!list.length" class="empty">
                <p style="margin: 0 0 14px">{{ tab === 'up' ? $t('player.my.emptyUp') : $t('player.my.emptyPast') }}</p>
                <Link v-if="tab === 'up'" class="btn sm" :href="route('home')"><Icon name="search" size="sm" />{{ $t('player.my.find') }}</Link>
            </div>
        </section>

        <!-- cancel -->
        <div v-if="cancelling" class="scrim" @click.self="cancelling = null">
            <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="ct">
                <div class="row">
                    <h3 id="ct">{{ cancelling.status === 'pending' ? $t('player.my.cTitleReq') : $t('player.my.cTitle') }}</h3>
                    <button type="button" class="x" :aria-label="$t('player.close')" @click="cancelling = null"><Icon name="x" /></button>
                </div>
                <div class="sum-box">
                    <span class="sport-dot" :style="{ background: SIZE_COLOR[cancelling.field.size], width: '12px', height: '12px' }" />
                    <div style="flex: 1; min-width: 0">
                        <b>{{ cancelling.venue.name }} · {{ $t(`player.size.${cancelling.field.size}`) }} {{ cancelling.field.format }}</b>
                        <div class="muted" style="font-size: 13.5px">{{ dateLong(cancelling.date) }} · {{ cancelling.start }}–{{ cancelling.end }}</div>
                    </div>
                    <span class="price">{{ amd(cancelling.price) }}</span>
                </div>
                <div :class="['policy', cancelling.lateCancel ? 'late' : 'ok']">
                    <Icon :name="cancelling.lateCancel ? 'clock' : 'check'" size="sm" />
                    <span v-if="cancelling.status === 'pending'">{{ $t('player.my.cReq') }}</span>
                    <span v-else-if="cancelling.lateCancel">{{ $t('player.my.cLate', { h: cancelling.freeCancelHours }) }}</span>
                    <span v-else>{{ $t('player.my.cFree', { h: cancelling.freeCancelHours }) }}</span>
                </div>
                <div class="row">
                    <button type="button" class="btn ghost" style="flex: 1" @click="cancelling = null">{{ $t('player.my.cKeep') }}</button>
                    <button type="button" class="btn red" style="flex: 1" :disabled="busy" @click="cancel">{{ $t('player.my.cYes') }}</button>
                </div>
            </div>
        </div>
    </PlayerLayout>
</template>

<style scoped>
.prof { margin-top: 26px; position: relative; overflow: hidden; padding: 24px; display: grid; grid-template-columns: auto 1fr auto; gap: 20px; align-items: center; }
.prof::before { content: ""; position: absolute; right: -80px; top: -120px; width: 360px; height: 300px; background: radial-gradient(closest-side, rgba(200, 255, 61, 0.14), transparent); pointer-events: none; }
.bigav { width: 72px; height: 72px; border-radius: 22px; background: var(--grad); color: var(--on-accent); display: grid; place-items: center; font-family: var(--display); font-size: 26px; font-weight: 700; box-shadow: 0 10px 30px -10px rgba(200, 255, 61, 0.5); }
.prof h1 { font-size: clamp(22px, 3vw, 30px); margin-top: 2px; overflow-wrap: anywhere; }
.prof .ph { font-family: var(--mono); color: var(--muted); font-size: 14px; }
.tg-card { margin-top: 14px; border-color: rgba(92, 200, 255, 0.22); }
.pstats { display: flex; gap: 10px; position: relative; }
.pstats div { background: var(--bg-2); border: 1px solid var(--line); border-radius: 14px; padding: 10px 14px; min-width: 92px; }
.pstats b { display: block; font-family: var(--display); font-size: 19px; font-weight: 600; }
.pstats span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; font-size: 11px; color: var(--muted); font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; }
@media (max-width: 860px) { .prof { grid-template-columns: auto 1fr; } .pstats { grid-column: 1 / -1; } .pstats div { flex: 1; min-width: 0; } }
@media (max-width: 480px) { .prof { padding: 18px; gap: 14px; } .bigav { width: 56px; height: 56px; font-size: 20px; border-radius: 17px; } .pstats { gap: 6px; } .pstats div { padding: 8px 10px; } .pstats b { font-size: 16px; } .pstats span { font-size: 9.5px; } }
.signout { background: none; border: 0; color: var(--muted); font-weight: 700; font-size: 13px; display: inline-flex; gap: 6px; align-items: center; padding: 4px 0; margin-top: 6px; }
.signout:hover { color: var(--hot); }

.tabs { margin: 26px 0 18px; }
.tabs .n { font-size: 11.5px; background: var(--surface-3); color: var(--muted); border-radius: 99px; padding: 1px 7px; }
.tabs button[aria-selected="true"] .n { background: var(--accent); color: var(--on-accent); }

.bk { display: grid; grid-template-columns: 150px minmax(0, 1fr) auto; gap: 18px; padding: 14px; align-items: center; margin-bottom: 12px; position: relative; }
.bk .thumb { display: block; height: 110px; border-radius: 14px; overflow: hidden; background: #0c120e; position: relative; }
.bk .thumb .court-art svg { width: 92%; }
.bk h3 { font-size: 17px; }
.bk h3 a:hover { color: var(--accent); }
.bk .meta { display: flex; flex-wrap: wrap; gap: 6px 14px; color: var(--muted); font-size: 13.5px; margin-top: 6px; }
.bk .meta span { display: inline-flex; gap: 5px; align-items: center; }
.bk .when { font-family: var(--display); font-size: 15px; font-weight: 600; margin-top: 8px; }
.bk .code { white-space: nowrap; font-family: var(--mono); font-size: 12.5px; color: var(--accent); background: rgba(200, 255, 61, 0.08); border: 1px dashed rgba(200, 255, 61, 0.3); padding: 2px 8px; border-radius: 7px; letter-spacing: 0.06em; }
.bk .side { display: flex; flex-direction: column; align-items: flex-end; gap: 10px; }
.bk .pr { text-align: right; }
.bk .acts { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
.bk .btn.danger:hover { color: var(--hot); border-color: rgba(255, 107, 107, 0.4); }
.bk.off { opacity: 0.62; }
.bk.off .thumb { filter: grayscale(0.9); }
.bk .extra { font-size: 13px; color: var(--muted); margin-top: 6px; overflow-wrap: anywhere; }
.bk .extra.hot { color: var(--hot); }
.bk .waitnote, .bk .rule { display: inline-flex; gap: 6px; align-items: center; font-size: 12.5px; font-weight: 700; color: var(--blue); margin-top: 8px; }
.bk .rule { color: var(--free); display: flex; }
@media (max-width: 760px) {
  .bk { grid-template-columns: 96px minmax(0, 1fr); gap: 14px; align-items: start; }
  .bk .thumb { height: 84px; }
  .bk .side { grid-column: 1 / -1; align-items: stretch; border-top: 1px solid var(--line); padding-top: 12px; }
  .bk .pr { display: flex; justify-content: space-between; align-items: center; text-align: left; }
  .bk .pr .row { margin-top: 0 !important; }
  .bk .acts { justify-content: flex-start; }
  .bk .acts .btn:last-child { margin-left: auto; }
}
.sum-box { background: var(--bg-2); border-radius: 14px; padding: 14px; display: flex; gap: 12px; align-items: center; margin: 14px 0; }
.policy { display: flex; gap: 10px; padding: 12px 14px; border-radius: 12px; font-size: 13.5px; margin-bottom: 16px; }
.policy .ico { margin-top: 3px; }
.policy.ok { background: var(--free-soft); color: var(--free); }
.policy.late { background: var(--warn-soft); color: var(--warn); }
.btn.red { background: var(--hot); color: #200606; }
.btn.red:hover { box-shadow: 0 10px 26px -10px rgba(255, 107, 107, 0.6); }
</style>
