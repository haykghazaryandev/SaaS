<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import GameProgress from '@/components/games/GameProgress.vue';
import GameStatusPill from '@/components/games/GameStatusPill.vue';
import StatusPill from '@/components/player/StatusPill.vue';
import { hasRoute, todayYerevan, whenText, whereText } from '@/composables/useGames';
import { dateRel, dur, wdName } from '@/composables/usePlayer';
import { amd, hhmm } from '@/composables/useShared';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import type { BookPlace, GameBooking, GameCard, GamePerson, GamePlayerRow } from '@/types/games';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// The game page: details, players, and what I can do (accept, join, leave, invite, book a field, cancel …).
const props = defineProps<{
    game: GameCard & { booking: GameBooking | null; previousId: number | null; invitedCount: number };
    players: { in: GamePlayerRow[]; invited: GamePlayerRow[]; invitedCount: number; declined: GamePlayerRow[] };
    can: { respond: boolean; join: boolean; joinLogin: boolean; leave: boolean; remove: boolean; invite: boolean; cancel: boolean; book: boolean; playAgain: boolean };
    conversationId: number | null;
    friends: GamePerson[];
    places: BookPlace[];
    days: { date: string; k: number; wd: number; day: number; weekend: boolean }[];
}>();

const g = computed(() => props.game);
const busy = ref(false);
const chatUrl = computed(() => (props.conversationId && hasRoute('messages.show') ? route('messages.show', props.conversationId) : null));
const spotsLeft = computed(() => g.value.total - g.value.in);
const holding = computed(() => g.value.booking && ['pending', 'accepted'].includes(g.value.booking.status));
const inviter = computed(() => g.value.creator.name);
// anything for the actions card (accept / decline live in the invite banner)
const hasActions = computed(() => !!chatUrl.value || Object.entries(props.can).some(([k, v]) => k !== 'respond' && v));

function post(name: string, data: Record<string, unknown> = {}, extra: Record<string, unknown> = {}) {
    busy.value = true;
    router.post(route(name, { game: g.value.id, ...extra }), data, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            confirm.value = null;
        },
    });
}

// ---------- confirm dialog (leave, cancel, remove) ----------
type Confirm = { title: string; text: string; yes: string; run: () => void };
const confirm = ref<Confirm | null>(null);
const askLeave = () => (confirm.value = { title: 'games.show.leaveTitle', text: 'games.show.leaveText', yes: 'games.show.leaveYes', run: () => post('games.leave') });
const askCancel = () => (confirm.value = { title: 'games.show.cancelTitle', text: 'games.show.cancelText', yes: 'games.show.cancelYes', run: () => post('games.cancel') });
const removing = ref<GamePlayerRow | null>(null);
function askRemove(p: GamePlayerRow) {
    removing.value = p;
    confirm.value = { title: 'games.show.removeTitle', text: 'games.show.removeText', yes: 'games.show.removeYes', run: () => post('games.remove', {}, { user: p.id }) };
}

// ---------- invite sheet ----------
const inviting = ref(false);
const picked = ref<number[]>([]);
const q = ref('');
const shownFriends = computed(() => {
    const s = q.value.trim().toLowerCase();
    return s ? props.friends.filter((f) => f.name.toLowerCase().includes(s)) : props.friends;
});
function togglePick(id: number) {
    const i = picked.value.indexOf(id);
    if (i >= 0) picked.value.splice(i, 1);
    else picked.value.push(id);
}
function sendInvites() {
    busy.value = true;
    router.post(route('games.invite', g.value.id), { ids: picked.value }, {
        preserveScroll: true,
        onSuccess: () => {
            inviting.value = false;
            picked.value = [];
        },
        onFinish: () => (busy.value = false),
    });
}

// ---------- book sheet ----------
const booking = ref(false);
const form = useForm({ place: null as number | null, field_id: null as number | null, date: '', minutes: 60, start: '' });
const place = computed(() => props.places.find((p) => p.id === form.place) ?? null);
const field = computed(() => place.value?.fields.find((f) => f.id === form.field_id) ?? null);
const starts = ref<number[] | null>(null);
const slotsLoading = ref(false);
function openBook() {
    if (!form.place) {
        const pref = props.places.find((p) => p.slug === g.value.venue?.slug) ?? props.places[0];
        form.place = pref?.id ?? null;
    }
    if (!form.date) form.date = g.value.window?.date && props.days.some((d) => d.date === g.value.window?.date) ? g.value.window.date : props.days[0]?.date ?? '';
    booking.value = true;
}
watch(
    () => form.place,
    () => {
        const p = place.value;
        if (!p) return;
        const want = g.value.perSide >= 7 ? 'big' : 'small';
        const f = p.fields.find((x) => x.size === want) ?? p.fields[0];
        form.field_id = f?.id ?? null;
    },
);
watch(
    () => form.field_id,
    () => {
        if (field.value && !field.value.durations.includes(form.minutes)) form.minutes = field.value.defaultDuration;
    },
);
let ctrl: AbortController | null = null;
async function loadSlots() {
    form.start = '';
    if (!place.value || !field.value || !form.date || !booking.value) return;
    ctrl?.abort();
    ctrl = new AbortController();
    slotsLoading.value = true;
    try {
        const url = route('places.slots', { venue: place.value.slug, date: form.date, field: field.value.id, minutes: form.minutes });
        const res = await fetch(url, { headers: { Accept: 'application/json' }, signal: ctrl.signal });
        const data = await res.json();
        starts.value = (data.fields?.[0]?.starts?.[String(form.minutes)] ?? []) as number[];
    } catch (e) {
        if ((e as Error).name !== 'AbortError') starts.value = [];
    } finally {
        slotsLoading.value = false;
    }
}
watch(() => [form.place, form.field_id, form.date, form.minutes, booking.value], loadSlots);
function sendBooking() {
    form.transform((d) => ({ field_id: d.field_id, date: d.date, start: d.start, minutes: d.minutes })).post(route('games.book', g.value.id), {
        preserveScroll: true,
        onSuccess: () => (booking.value = false),
    });
}

function onKey(e: KeyboardEvent) {
    if (e.key !== 'Escape') return;
    confirm.value = null;
    inviting.value = false;
    booking.value = false;
}
onMounted(() => document.addEventListener('keydown', onKey));
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
const bookWhen = (b: GameBooking) => `${dateRel(b.date, todayYerevan())} · ${b.start}–${b.end}`;
</script>

<template>
    <PlayerLayout>
        <Head :title="`${g.format} · ${$t('games.title')}`" />
        <Link class="back" :href="route('games.index')"><Icon name="back" size="sm" />{{ $t('games.show.back') }}</Link>

        <!-- header -->
        <section :class="['card hero', `st-${g.status}`]">
            <div class="hl">
                <div class="row wrapflex" style="gap: 6px">
                    <GameStatusPill :status="g.status" />
                    <span class="pill"><Icon name="bolt" size="sm" />{{ g.level ? $t(`level.${g.level}`) : $t('games.anyLevel') }}</span>
                    <span v-if="g.mine" class="pill lime"><Icon name="star" size="sm" />{{ $t('games.card.yours') }}</span>
                </div>
                <h1><span class="fmt">{{ g.format }}</span> <span class="sub">{{ $t('games.show.totalPlayers', { n: String(g.total) }) }}</span></h1>
                <div class="facts">
                    <span><Icon name="pin" size="sm" />
                        <Link v-if="g.venue" :href="route('places.show', { venue: g.venue.slug })" class="ul">{{ g.venue.name }}</Link>
                        <template v-else>{{ whereText(g) }}</template>
                        <template v-if="g.venue && g.district && g.district !== g.venue.district"> · {{ $t(`player.district.${g.district}`) }}</template>
                    </span>
                    <span><Icon name="calendar" size="sm" />{{ whenText(g.window) }}</span>
                    <span><Icon name="user" size="sm" />{{ $t('games.show.organiser', { name: g.creator.name }) }}</span>
                </div>
                <p v-if="g.note" class="note">“{{ g.note }}”</p>
                <Link v-if="g.previousId" class="faint prev" :href="route('games.show', g.previousId)"><Icon name="clock" size="sm" />{{ $t('games.show.previous') }}</Link>
            </div>
            <div class="hr">
                <GameProgress :in-count="g.in" :total="g.total" :min-to-open="g.minToOpen" :status="g.status" />
                <p v-if="g.status === 'forming'" class="small warnt">{{ $t('games.show.formingNote', { n: String(g.minToOpen), more: String(Math.max(0, g.minToOpen - g.in)) }) }}</p>
                <p v-else-if="g.status === 'open'" class="small muted">{{ $t('games.show.openNote', { n: String(spotsLeft) }) }}</p>
                <p v-else-if="g.status === 'full'" class="small muted">{{ $t('games.show.fullNote') }}</p>
            </div>
        </section>

        <!-- invite banner -->
        <section v-if="can.respond" class="card banner">
            <div class="bi"><Icon name="send" /></div>
            <div style="flex: 1; min-width: 0">
                <b>{{ $t('games.show.invitedTitle', { name: inviter }) }}</b>
                <p class="small muted">{{ g.status === 'forming' ? $t('games.show.invitedForming', { n: String(g.minToOpen) }) : $t('games.show.invitedOpen') }}</p>
            </div>
            <div class="row" style="gap: 8px">
                <button type="button" class="btn ghost" :disabled="busy" @click="post('games.respond', { accept: false })">{{ $t('games.decline') }}</button>
                <button type="button" class="btn" :disabled="busy" @click="post('games.respond', { accept: true })"><Icon name="check" size="sm" />{{ $t('games.accept') }}</button>
            </div>
        </section>

        <div class="sgrid">
            <!-- players -->
            <section class="card blk">
                <div class="row between">
                    <h3>{{ $t('games.show.inGame') }} <span class="cnt">{{ players.in.length }}/{{ g.total }}</span></h3>
                </div>
                <ul class="plist">
                    <li v-for="p in players.in" :key="p.id">
                        <span :class="['av', { c: p.creator }]">{{ p.initials }}</span>
                        <span class="nm">
                            {{ p.name }}<template v-if="p.me"> <span class="faint">({{ $t('games.show.you') }})</span></template>
                            <small>
                                <template v-if="p.creator">{{ $t('games.show.organiserShort') }}</template>
                                <template v-if="p.creator && p.level"> · </template>
                                <template v-if="p.level">{{ $t(`level.${p.level}`) }}</template>
                            </small>
                        </span>
                        <button v-if="can.remove && !p.creator" type="button" class="btn ghost sm rm" :aria-label="$t('games.show.remove')" @click="askRemove(p)">
                            <Icon name="x" size="sm" /><span>{{ $t('games.show.remove') }}</span>
                        </button>
                    </li>
                    <li v-for="i in Math.max(0, Math.min(spotsLeft, 4))" :key="'e' + i" class="slot"><span class="av e">+</span><span class="faint">{{ $t('games.show.freeSpot') }}</span></li>
                    <li v-if="spotsLeft > 4" class="slot faint small">{{ $t('games.show.moreSpots', { n: String(spotsLeft - 4) }) }}</li>
                </ul>

                <template v-if="players.invited.length">
                    <h4>{{ $t('games.show.invited') }} <span class="cnt">{{ players.invited.length }}</span></h4>
                    <ul class="plist dim">
                        <li v-for="p in players.invited" :key="p.id">
                            <span class="av">{{ p.initials }}</span>
                            <span class="nm">{{ p.name }}<template v-if="p.me"> <span class="faint">({{ $t('games.show.you') }})</span></template><small>{{ $t('games.show.waiting') }}</small></span>
                        </li>
                    </ul>
                </template>
                <p v-else-if="players.invitedCount" class="small faint">{{ $t('games.show.invitedCount', { n: String(players.invitedCount) }) }}</p>

                <template v-if="players.declined.length">
                    <h4>{{ $t('games.show.declined') }} <span class="cnt">{{ players.declined.length }}</span></h4>
                    <ul class="plist dim">
                        <li v-for="p in players.declined" :key="p.id">
                            <span class="av">{{ p.initials }}</span><span class="nm">{{ p.name }}</span>
                        </li>
                    </ul>
                </template>
            </section>

            <!-- actions -->
            <aside class="side">
                <section v-if="hasActions || !can.respond" class="card blk">
                    <h3>{{ $t('games.show.actions') }}</h3>
                    <button v-if="can.join" type="button" class="btn block" :disabled="busy" @click="post('games.join')"><Icon name="plus" size="sm" />{{ $t('games.show.join', { n: String(spotsLeft) }) }}</button>
                    <Link v-if="can.joinLogin" class="btn block" :href="route('login')"><Icon name="user" size="sm" />{{ $t('games.show.loginToJoin') }}</Link>
                    <a v-if="chatUrl" class="btn block blue" :href="chatUrl"><Icon name="chat" size="sm" />{{ $t('games.show.chat') }}</a>
                    <p v-else-if="conversationId === null && g.me && ['accepted', 'joined'].includes(g.me) && g.status === 'forming'" class="small faint">{{ $t('games.show.chatLater') }}</p>
                    <button v-if="can.book" type="button" class="btn block" @click="openBook"><Icon name="calendar" size="sm" />{{ $t('games.show.book') }}</button>
                    <button v-if="can.invite" type="button" class="btn ghost block" @click="inviting = true"><Icon name="users" size="sm" />{{ $t('games.show.inviteMore') }}</button>
                    <button v-if="can.playAgain" type="button" class="btn block" :disabled="busy" @click="post('games.playAgain')"><Icon name="ball" size="sm" />{{ $t('games.show.playAgain') }}</button>
                    <button v-if="can.leave" type="button" class="btn ghost block danger" @click="askLeave"><Icon name="logout" size="sm" />{{ $t('games.show.leave') }}</button>
                    <button v-if="can.cancel" type="button" class="btn ghost block danger" @click="askCancel"><Icon name="x" size="sm" />{{ $t('games.show.cancel') }}</button>
                    <p v-if="!hasActions" class="small faint" style="margin: 0">
                        {{ g.status === 'cancelled' ? $t('games.show.cancelledNote') : g.me === 'removed' ? $t('social.error.removed') : $t('games.show.nothing') }}
                    </p>
                </section>

                <section v-if="g.booking" :class="['card blk bk', { off: !holding }]">
                    <div class="row between">
                        <h3>{{ $t('games.show.field') }}</h3>
                        <StatusPill :status="g.booking.status" />
                    </div>
                    <div>
                        <Link :href="route('places.show', { venue: g.booking.venue.slug })" class="bname">{{ g.booking.venue.name }}</Link>
                        <div class="muted small">{{ $t(`player.size.${g.booking.field.size}`) }} {{ g.booking.field.format }} · {{ $t(`player.district.${g.booking.venue.district}`) }}</div>
                    </div>
                    <div class="when">{{ bookWhen(g.booking) }}</div>
                    <div class="row between small">
                        <span class="price">{{ amd(g.booking.price) }}</span>
                        <span class="code">{{ g.booking.code }}</span>
                    </div>
                    <p class="small" :class="g.booking.status === 'accepted' ? 'okt' : 'muted'" style="margin: 0">
                        {{ g.booking.status === 'pending' ? $t('games.show.bookingPending') : g.booking.status === 'accepted' ? $t('games.show.bookingAccepted') : $t('games.show.bookingOff') }}
                    </p>
                </section>

                <section class="card blk howc desk">
                    <b><Icon name="users" size="sm" /> {{ $t('games.rule.title') }}</b>
                    <p class="small muted">{{ $t('games.rule.text') }}</p>
                </section>
            </aside>
        </div>

        <section class="card blk howc phone">
                    <b><Icon name="users" size="sm" /> {{ $t('games.rule.title') }}</b>
                    <p class="small muted">{{ $t('games.rule.text') }}</p>
                </section>

        <!-- confirm -->
        <div v-if="confirm" class="scrim" @click.self="confirm = null">
            <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="cft">
                <div class="row">
                    <h3 id="cft">{{ $t(confirm.title, { name: removing?.name ?? '' }) }}</h3>
                    <button type="button" class="x" :aria-label="$t('player.close')" @click="confirm = null"><Icon name="x" /></button>
                </div>
                <p class="muted">{{ $t(confirm.text, { name: removing?.name ?? '' }) }}</p>
                <div class="row">
                    <button type="button" class="btn ghost" style="flex: 1" @click="confirm = null">{{ $t('games.show.keep') }}</button>
                    <button type="button" class="btn red" style="flex: 1" :disabled="busy" @click="confirm.run()">{{ $t(confirm.yes) }}</button>
                </div>
            </div>
        </div>

        <!-- invite more friends -->
        <div v-if="inviting" class="scrim" @click.self="inviting = false">
            <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="ivt">
                <div class="row">
                    <h3 id="ivt">{{ $t('games.show.inviteMore') }}</h3>
                    <button type="button" class="x" :aria-label="$t('player.close')" @click="inviting = false"><Icon name="x" /></button>
                </div>
                <p v-if="!friends.length" class="muted">{{ $t('games.show.inviteNone') }}</p>
                <template v-else>
                    <input v-model="q" type="search" class="input" style="margin: 12px 0 8px" :placeholder="$t('games.create.search')" :aria-label="$t('games.create.search')" />
                    <ul class="flist">
                        <li v-for="f in shownFriends" :key="f.id">
                            <label :class="{ on: picked.includes(f.id) }">
                                <input type="checkbox" :checked="picked.includes(f.id)" @change="togglePick(f.id)" />
                                <span class="av">{{ f.initials }}</span>
                                <span class="nm">{{ f.name }}</span>
                                <span class="tick"><Icon name="check" size="sm" /></span>
                            </label>
                        </li>
                    </ul>
                    <button type="button" class="btn block" style="margin-top: 14px" :disabled="busy || !picked.length" @click="sendInvites">
                        <Icon name="send" size="sm" />{{ $t('games.show.inviteSend', { n: String(picked.length) }) }}
                    </button>
                </template>
            </div>
        </div>

        <!-- book a field -->
        <div v-if="booking" class="scrim" @click.self="booking = false">
            <div class="sheet wide" role="dialog" aria-modal="true" aria-labelledby="bkt">
                <div class="row">
                    <h3 id="bkt">{{ $t('games.show.book') }}</h3>
                    <button type="button" class="x" :aria-label="$t('player.close')" @click="booking = false"><Icon name="x" /></button>
                </div>
                <p class="muted small" style="margin: 8px 0 14px">{{ $t('games.show.bookNote') }}</p>
                <div class="field">
                    <label for="bp">{{ $t('games.show.place') }}</label>
                    <select id="bp" v-model="form.place" class="input">
                        <option v-for="p in places" :key="p.id" :value="p.id">{{ p.name }} · {{ $t(`player.district.${p.district}`) }}</option>
                    </select>
                </div>
                <div v-if="place" class="field" style="margin-top: 12px">
                    <label>{{ $t('games.show.fieldLabel') }}</label>
                    <div class="chips">
                        <button v-for="f in place.fields" :key="f.id" type="button" class="chip" :aria-pressed="form.field_id === f.id" @click="form.field_id = f.id">
                            {{ $t(`player.size.${f.size}`) }} {{ f.format }}
                        </button>
                    </div>
                </div>
                <div class="field" style="margin-top: 12px">
                    <label>{{ $t('games.show.day') }}</label>
                    <div class="days">
                        <button v-for="d in days" :key="d.date" type="button" :class="['day', { we: d.weekend }]" :aria-pressed="form.date === d.date" @click="form.date = d.date">
                            <small>{{ d.k === 0 ? $t('player.today') : wdName(d.wd) }}</small><b>{{ d.day }}</b>
                        </button>
                    </div>
                </div>
                <div v-if="field" class="field" style="margin-top: 12px">
                    <label>{{ $t('games.show.duration') }}</label>
                    <div class="chips">
                        <button v-for="m in field.durations" :key="m" type="button" class="chip" :aria-pressed="form.minutes === m" @click="form.minutes = m">{{ dur(m) }}</button>
                    </div>
                </div>
                <div class="field" style="margin-top: 12px">
                    <label>{{ $t('games.show.start') }}</label>
                    <div :class="['starts', { dim: slotsLoading }]">
                        <button v-for="h in starts ?? []" :key="h" type="button" :class="['st', { on: form.start === hhmm(h) }]" @click="form.start = hhmm(h)">{{ hhmm(h) }}</button>
                        <span v-if="!slotsLoading && starts && !starts.length" class="faint small">{{ $t('games.show.noSlots') }}</span>
                        <span v-if="slotsLoading && !starts" class="faint small">{{ $t('games.show.loading') }}</span>
                    </div>
                </div>
                <div v-if="form.errors.booking" class="err">{{ form.errors.booking }}</div>
                <div v-for="k in ['field_id', 'date', 'start', 'minutes'] as const" :key="k">
                    <div v-if="form.errors[k]" class="err">{{ form.errors[k] }}</div>
                </div>
                <button type="button" class="btn block" style="margin-top: 16px" :disabled="form.processing || !form.start || !form.field_id" @click="sendBooking">
                    <Icon name="send" size="sm" />{{ form.start ? $t('games.show.bookSend', { t: form.start }) : $t('games.show.bookPick') }}
                </button>
            </div>
        </div>
    </PlayerLayout>
</template>

<style scoped>
.back { display: inline-flex; gap: 6px; align-items: center; color: var(--muted); font-weight: 700; font-size: 14px; margin-top: 22px; }
.back:hover { color: var(--ink); }
.hero { margin-top: 12px; padding: 24px; display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); gap: 24px; align-items: center; position: relative; overflow: hidden; }
.hero::before { content: ""; position: absolute; right: -80px; top: -120px; width: 380px; height: 300px; background: radial-gradient(closest-side, rgba(200, 255, 61, 0.12), transparent); pointer-events: none; }
.hl { display: flex; flex-direction: column; gap: 10px; min-width: 0; position: relative; }
.hr { display: flex; flex-direction: column; gap: 8px; background: var(--bg-2); border-radius: 16px; padding: 16px; position: relative; }
.hero h1 { font-size: clamp(36px, 5vw, 56px); display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; }
.hero .fmt { background: var(--grad); -webkit-background-clip: text; background-clip: text; color: transparent; }
.st-cancelled .fmt, .st-played .fmt, .st-archived .fmt { background: none; color: var(--muted); }
.hero .sub { font-family: var(--body); font-size: 15px; color: var(--muted); font-weight: 700; letter-spacing: 0; }
.facts { display: flex; flex-wrap: wrap; gap: 6px 16px; color: var(--muted); font-size: 14px; }
.facts span { display: inline-flex; gap: 6px; align-items: center; }
.ul { text-decoration: underline; text-decoration-color: var(--line-2); text-underline-offset: 3px; }
.ul:hover { color: var(--accent); }
.note { margin: 0; color: var(--ink); font-size: 15px; overflow-wrap: anywhere; }
.prev { display: inline-flex; gap: 6px; align-items: center; font-size: 13px; font-weight: 700; }
.small { font-size: 13px; margin: 0; }
.warnt { color: var(--warn); }
.okt { color: var(--free); }
@media (max-width: 760px) { .hero { grid-template-columns: minmax(0, 1fr); padding: 18px; gap: 16px; } }
.banner { margin-top: 14px; padding: 16px 18px; display: flex; align-items: center; gap: 14px; flex-wrap: wrap; border-color: rgba(92, 200, 255, 0.3); background: linear-gradient(90deg, rgba(92, 200, 255, 0.08), var(--surface)); }
.banner .bi { width: 42px; height: 42px; border-radius: 12px; background: var(--blue-soft); color: var(--blue); display: grid; place-items: center; flex: none; }
.banner p { margin-top: 2px; }
@media (max-width: 600px) { .banner .row { width: 100%; } .banner .row .btn { flex: 1; } }
.sgrid { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); gap: 16px; margin-top: 16px; align-items: start; }
@media (max-width: 860px) { .sgrid { grid-template-columns: minmax(0, 1fr); } .side { order: -1; } }
.side { display: flex; flex-direction: column; gap: 16px; min-width: 0; }
.blk { padding: 20px; display: flex; flex-direction: column; gap: 12px; min-width: 0; }
.blk h3 { font-size: 16px; display: flex; gap: 8px; align-items: center; }
.blk h4 { margin: 8px 0 0; font-size: 13px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.06em; display: flex; gap: 8px; align-items: center; }
.cnt { font-family: var(--mono); font-size: 12px; background: var(--surface-3); color: var(--muted); border-radius: 99px; padding: 2px 8px; font-weight: 600; letter-spacing: 0; }
.plist { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 6px; }
.plist li { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 12px; background: var(--bg-2); min-width: 0; }
.plist .av { width: 34px; height: 34px; border-radius: 50%; background: var(--surface-3); display: grid; place-items: center; font-size: 12px; font-weight: 800; flex: none; }
.plist .av.c { background: var(--grad); color: var(--on-accent); }
.plist .av.e { background: none; border: 1.5px dashed var(--line-2); color: var(--faint); }
.plist .slot { background: none; border: 1px dashed var(--line); }
.plist .nm { flex: 1; min-width: 0; font-weight: 700; font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.plist .nm small { display: block; color: var(--faint); font-weight: 600; font-size: 12px; }
.plist.dim li { opacity: .75; }
.rm { padding: 5px 9px; }
.rm:hover { color: var(--hot); border-color: rgba(255, 107, 107, 0.4); }
@media (max-width: 480px) { .rm span { display: none; } }
.btn.danger:hover { color: var(--hot); border-color: rgba(255, 107, 107, 0.4); }
.btn.red { background: var(--hot); color: #200606; }
.bk .bname { font-family: var(--display); font-size: 17px; font-weight: 600; }
.bk .bname:hover { color: var(--accent); }
.bk .when { font-family: var(--display); font-weight: 600; }
.bk .code { font-family: var(--mono); font-size: 12.5px; color: var(--accent); background: rgba(200, 255, 61, 0.08); border: 1px dashed rgba(200, 255, 61, 0.3); padding: 2px 8px; border-radius: 7px; letter-spacing: 0.06em; }
.bk.off { opacity: .65; }
.howc.phone { display: none; margin-top: 16px; }
@media (max-width: 860px) { .howc.desk { display: none; } .howc.phone { display: flex; } }
.howc b { display: flex; gap: 8px; align-items: center; font-size: 14px; }
.sheet.wide { width: min(560px, 100%); }
.sheet .days, .sheet .chips { max-width: 100%; }
.day.we:not([aria-pressed="true"]) small { color: var(--warn); }
.starts { display: flex; flex-wrap: wrap; gap: 6px; min-height: 34px; align-items: center; }
.starts.dim { opacity: .5; }
.st { font-family: var(--mono); font-size: 13px; padding: 6px 10px; border-radius: 9px; background: var(--free-soft); color: var(--free); border: 1px solid rgba(94, 240, 168, 0.2); }
.st.on { background: var(--accent); color: var(--on-accent); border-color: var(--accent); }
.err { color: var(--hot); font-size: 13px; font-weight: 700; margin-top: 10px; }
.flist { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; max-height: 50vh; overflow: auto; }
.flist label { display: flex; align-items: center; gap: 12px; padding: 8px 10px; border-radius: 12px; cursor: pointer; border: 1px solid transparent; }
.flist label:hover { background: var(--surface-2); }
.flist label.on { background: rgba(200, 255, 61, 0.06); border-color: rgba(200, 255, 61, 0.25); }
.flist input { position: absolute; opacity: 0; pointer-events: none; }
.flist .av { width: 34px; height: 34px; border-radius: 50%; background: var(--surface-3); display: grid; place-items: center; font-size: 12px; font-weight: 800; flex: none; }
.flist .nm { flex: 1; min-width: 0; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.flist .tick { width: 22px; height: 22px; border-radius: 50%; border: 1.5px solid var(--line-2); display: grid; place-items: center; color: transparent; flex: none; }
.flist label.on .tick { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
</style>
