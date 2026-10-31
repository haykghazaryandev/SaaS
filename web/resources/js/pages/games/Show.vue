<script setup lang="ts">
import { useSportTheme } from '@/lib/sport';
import Avatar from '@/components/Avatar.vue';
import Icon from '@/components/Icon.vue';
import GameProgress from '@/components/games/GameProgress.vue';
import GameStatusPill from '@/components/games/GameStatusPill.vue';
import PositionPicker from '@/components/games/PositionPicker.vue';
import StatusPill from '@/components/player/StatusPill.vue';
import { hasRoute, sizeOf, todayYerevan, whenText, whereText } from '@/composables/useGames';
import { dateRel, dur, wdName } from '@/composables/usePlayer';
import { amd, hhmm } from '@/composables/useShared';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import type { BookPlace, GameBooking, GameCard, GamePerson, GamePlayerRow } from '@/types/games';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { trans } from '@/lib/i18n';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// The game page: details, players, and what I can do (accept, join, leave, invite, book a field, cancel …).
const props = defineProps<{
    game: GameCard & { booking: GameBooking | null; previousId: number | null; invitedCount: number };
    players: { in: GamePlayerRow[]; invited: GamePlayerRow[]; invitedCount: number; declined: GamePlayerRow[] };
    can: { respond: boolean; join: boolean; joinLogin: boolean; leave: boolean; remove: boolean; invite: boolean; cancel: boolean; book: boolean; playAgain: boolean; position: boolean };
    conversationId: number | null;
    friends: GamePerson[];
    places: BookPlace[];
    days: { date: string; k: number; wd: number; day: number; weekend: boolean }[];
    positions: string[];
    profilePosition: string | null;
}>();

const g = computed(() => props.game);
const busy = ref(false);
const chatUrl = computed(() => (props.conversationId && hasRoute('messages.show') ? route('messages.show', props.conversationId) : null));
const spotsLeft = computed(() => g.value.total - g.value.in);
const holding = computed(() => g.value.booking && ['pending', 'accepted'].includes(g.value.booking.status));
const inviter = computed(() => g.value.invitedBy?.name ?? g.value.creator.name);

// ---------- positions (optional): chosen when joining / accepting (my profile position first), changed in a sheet ----------
const isPingpong = computed(() => (props.game as { sport?: string }).sport === 'pingpong');
useSportTheme(() => (props.game as { sport?: string }).sport);
const known = (p: string | null | undefined) => (p && props.positions.includes(p) ? p : null);
const joinPos = ref<string | null>(known(props.profilePosition));
const posData = (): Record<string, string> => (joinPos.value ? { position: joinPos.value } : {});
const myRow = computed(() => props.players.in.find((p) => p.me) ?? null);
const posFull = (p: string) => trans(`profile.positions.${p}`);
const posShort = (p: string) => trans(`games.position.short.${p}`);
// "Positions: 1 GK · 2 DEF · 0 MID · 1 FWD": shown once somebody picked one, so the organiser sees what is missing
const posCounts = computed(() => props.positions.map((p) => ({ p, n: props.players.in.filter((x) => x.position === p).length })));
const posUnset = computed(() => props.players.in.filter((x) => !x.position).length);
const showPosSummary = computed(() => posUnset.value < props.players.in.length);
const posEditing = ref(false);
const posPick = ref<string | null>(null);
function openPos() {
    posPick.value = known(myRow.value?.position) ?? known(props.profilePosition);
    posEditing.value = true;
}
function savePos() {
    busy.value = true;
    router.patch(route('games.position', g.value.id), { position: posPick.value }, {
        preserveScroll: true,
        onSuccess: () => (posEditing.value = false),
        onFinish: () => (busy.value = false),
    });
}

// ---------- actions: ONE main (lime) action by state, the others outline; leave / cancel are text links ----------
type Act = { key: string; label: string; icon: string; href?: string; run?: () => void };
const actions = computed<Act[]>(() => {
    const c = props.can;
    const list: Act[] = [];
    if (c.join) list.push({ key: 'join', label: trans('games.show.join', { n: String(spotsLeft.value) }), icon: 'plus', run: () => post('games.join', posData()) });
    if (c.joinLogin) list.push({ key: 'login', label: trans('games.show.login_to_join'), icon: 'user', href: route('login') });
    if (c.book) list.push({ key: 'book', label: trans(isPingpong.value ? 'games.show.book_table' : 'games.show.book'), icon: 'calendar', run: openBook });
    if (chatUrl.value) list.push({ key: 'chat', label: trans('games.show.chat'), icon: 'chat', href: chatUrl.value });
    if (c.playAgain) list.push({ key: 'again', label: trans('games.show.play_again'), icon: 'ball', run: () => post('games.playAgain') });
    if (c.invite) list.push({ key: 'invite', label: trans('games.show.invite_more'), icon: 'users', run: () => (inviting.value = true) });
    return list;
});
const hasActions = computed(() => actions.value.length > 0 || props.can.leave || props.can.cancel);
const showRule = ref(false);

// ---------- per-row ⋯ menu (remove a player) ----------
const rowMenu = ref<number | null>(null);
function onDocClick(e: MouseEvent) {
    if (rowMenu.value !== null && !(e.target as HTMLElement).closest?.('.menu-wrap')) rowMenu.value = null;
}

function post(name: string, data: Record<string, string | number | boolean> = {}, extra: Record<string, unknown> = {}) {
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
const askLeave = () => (confirm.value = { title: 'games.show.leave_title', text: 'games.show.leave_text', yes: 'games.show.leave_yes', run: () => post('games.leave') });
const askCancel = () => (confirm.value = { title: 'games.show.cancel_title', text: 'games.show.cancel_text', yes: 'common.yes_cancel', run: () => post('games.cancel') });
const removing = ref<GamePlayerRow | null>(null);
function askRemove(p: GamePlayerRow) {
    removing.value = p;
    confirm.value = { title: 'games.show.remove_title', text: 'games.show.remove_text', yes: 'common.remove', run: () => post('games.remove', {}, { user: p.id }) };
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
const bookErr = computed(() => (form.errors as Record<string, string | undefined>).booking);
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
    posEditing.value = false;
    rowMenu.value = null;
}
onMounted(() => {
    document.addEventListener('keydown', onKey);
    document.addEventListener('click', onDocClick);
    // "→ Book" from the chat's game bar opens the booking sheet right away
    if (props.can.book && new URLSearchParams(window.location.search).get('book') === '1') openBook();
});
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKey);
    document.removeEventListener('click', onDocClick);
});
const bookWhen = (b: GameBooking) => `${dateRel(b.date, todayYerevan())} · ${b.start}–${b.end}`;
</script>

<template>
    <PlayerLayout>
        <Head :title="`${g.format} · ${$t('common.games')}`" />
        <Link class="back" :href="route('games.index')"><Icon name="back" size="sm" />{{ $t('games.show.back') }}</Link>

        <!-- header -->
        <section :class="['card hero', `st-${g.status}`]">
            <div class="hl">
                <div class="tags">
                    <span class="fmt"><i :class="['field-dot', sizeOf(g.perSide)]" />{{ g.format }} · {{ $t('games.show.total_players', { n: String(g.total) }) }}</span>
                    <GameStatusPill :status="g.status" />
                    <span v-if="g.mine" class="mine">{{ $t('games.card.yours') }}</span>
                </div>
                <h1 class="page-title">
                    <Link v-if="g.venue" :href="route('places.show', { venue: g.venue.slug })" class="ul">{{ g.venue.name }}</Link>
                    <template v-else>{{ whereText(g) }}</template>
                </h1>
                <div class="facts">
                    <span v-if="g.window" class="when"><Icon name="calendar" size="sm" />{{ whenText(g.window) }}</span>
                    <span v-else class="tbd"><Icon name="clock" size="sm" />{{ $t('games.card.time_tbd') }}</span>
                    <span v-if="g.venue && g.district && g.district !== g.venue.district"><Icon name="pin" size="sm" />{{ $t(`common.districts.${g.district}`) }}</span>
                    <span class="org"><Avatar :id="g.creator.id" :name="g.creator.name" :src="g.creator.avatar" size="xs" />{{ $t('games.show.organiser', { name: g.creator.name }) }}</span>
                </div>
                <p v-if="g.note" class="note">“{{ g.note }}”</p>
                <Link v-if="g.previousId" class="faint prev" :href="route('games.show', g.previousId)"><Icon name="clock" size="sm" />{{ $t('games.show.previous') }}</Link>
            </div>
            <div class="hr">
                <GameProgress :in-count="g.in" :total="g.total" :min-to-open="g.minToOpen" :status="g.status" big />
                <p v-if="g.status === 'forming'" class="small muted">{{ $t('games.show.forming_note', { n: String(g.minToOpen), more: String(Math.max(0, g.minToOpen - g.in)) }) }}</p>
                <p v-else-if="g.status === 'full'" class="small muted">{{ $t('games.show.full_note') }}</p>
                <button type="button" class="howbtn" :aria-expanded="showRule" @click="showRule = !showRule"><span class="i" aria-hidden="true">i</span>{{ $t('games.rule.title') }}</button>
                <p v-if="showRule" class="small muted rule">{{ $t('games.rule.text') }}</p>
            </div>
        </section>

        <!-- invite banner -->
        <section v-if="can.respond" class="card banner">
            <Avatar :id="g.invitedBy?.id ?? g.creator.id" :name="inviter" :src="(g.invitedBy ?? g.creator).avatar" size="md" />
            <div style="flex: 1; min-width: 0">
                <b>{{ $t('games.card.invited_you', { name: inviter }) }}</b>
                <p class="small muted">{{ g.status === 'forming' ? $t('games.show.invited_forming', { n: String(g.minToOpen) }) : $t('games.show.invited_open') }}</p>
            </div>
            <PositionPicker v-if="positions.length" v-model="joinPos" class="bpos" :positions="positions" :label="$t('games.position.pick')" />
            <div class="row bact">
                <button type="button" class="btn outline" :disabled="busy" @click="post('games.respond', { accept: false })">{{ $t('common.decline') }}</button>
                <button type="button" class="btn" :disabled="busy" @click="post('games.respond', { accept: true, ...posData() })"><Icon name="check" size="sm" />{{ $t('common.accept') }}</button>
            </div>
        </section>

        <div class="sgrid">
            <!-- players -->
            <section class="card blk">
                <h3>{{ $t('games.show.in_game') }} <span class="cnt">{{ players.in.length }}/{{ g.total }}</span></h3>
                <p v-if="showPosSummary" class="possum small">
                    <span class="muted">{{ $t('games.position.summary') }}:</span>
                    <template v-for="(c, i) in posCounts" :key="c.p">
                        <span v-if="i" class="faint" aria-hidden="true"> · </span>
                        <span :class="{ faint: !c.n && c.p !== 'goalkeeper', warnt: !c.n && c.p === 'goalkeeper' }" :title="!c.n && c.p === 'goalkeeper' ? $t('games.position.no_keeper') : undefined"
                            >{{ c.n }} <abbr :title="posFull(c.p)">{{ posShort(c.p) }}</abbr></span
                        >
                    </template>
                    <span v-if="posUnset" class="faint"> · {{ $t('games.position.not_set', { n: String(posUnset) }) }}</span>
                </p>
                <ul class="plist">
                    <li v-for="p in players.in" :key="p.id">
                        <Avatar :id="p.id" :name="p.name" :src="p.avatar" size="md" />
                        <span class="nm">
                            <span class="n1">{{ p.name }}</span>
                            <!-- "you" on the small line, so the name keeps its room next to the position chip -->
                            <small v-if="p.creator || p.me">{{
                                [p.me ? $t('games.show.you_label') : '', p.creator ? $t('games.show.organiser_short') : ''].filter(Boolean).join(' · ')
                            }}</small>
                        </span>
                        <!-- my own chip opens the position sheet; the others only show theirs -->
                        <button v-if="p.me && can.position && positions.length" type="button" :class="['pill pos', { blue: p.position }]" :title="p.position ? posFull(p.position) : undefined"
                            :aria-label="p.position ? `${$t('games.position.change')}: ${posFull(p.position)}` : $t('games.position.set')" @click="openPos">
                            <template v-if="p.position">{{ posShort(p.position) }}</template><template v-else><Icon name="plus" size="sm" />{{ $t('games.position.set') }}</template>
                        </button>
                        <abbr v-else-if="p.position" class="pill blue pos" :title="posFull(p.position)"><span aria-hidden="true">{{ posShort(p.position) }}</span><span class="sr-only">{{ posFull(p.position) }}</span></abbr>
                        <div v-if="can.remove && !p.creator" class="menu-wrap">
                            <button type="button" class="menu-btn" aria-haspopup="menu" :aria-expanded="rowMenu === p.id" :aria-label="$t('games.show.more', { name: p.name })" @click.stop="rowMenu = rowMenu === p.id ? null : p.id">⋯</button>
                            <div v-if="rowMenu === p.id" class="menu" role="menu">
                                <button type="button" role="menuitem" class="danger" @click="(rowMenu = null), askRemove(p)"><Icon name="x" size="sm" />{{ $t('common.remove') }}</button>
                            </div>
                        </div>
                    </li>
                    <li v-if="spotsLeft > 0 && !['cancelled', 'played', 'archived'].includes(g.status)" class="slot">
                        <span class="plus" aria-hidden="true">+{{ spotsLeft }}</span>
                        <span class="nm">{{ $tChoice('games.show.free_spots', spotsLeft, { n: String(spotsLeft) }) }}</span>
                        <button v-if="can.invite" type="button" class="btn outline sm" @click="inviting = true">{{ $t('games.show.invite') }}</button>
                    </li>
                </ul>

                <template v-if="players.invited.length">
                    <h4>{{ $t('games.show.invited') }} <span class="cnt">{{ players.invited.length }}</span></h4>
                    <ul class="plist dim">
                        <li v-for="p in players.invited" :key="p.id">
                            <Avatar :id="p.id" :name="p.name" :src="p.avatar" size="md" />
                            <span class="nm"><span class="n1">{{ p.name }}</span><small>{{ p.me ? $t('games.show.you_label') + ' · ' : '' }}{{ $t('games.show.waiting') }}</small></span>
                        </li>
                    </ul>
                </template>
                <p v-else-if="players.invitedCount" class="small faint">{{ $t('games.show.invited_count', { n: String(players.invitedCount) }) }}</p>

                <template v-if="players.declined.length">
                    <h4>{{ $t('games.show.declined') }} <span class="cnt">{{ players.declined.length }}</span></h4>
                    <ul class="plist dim">
                        <li v-for="p in players.declined" :key="p.id">
                            <Avatar :id="p.id" :name="p.name" :src="p.avatar" size="md" /><span class="nm"><span class="n1">{{ p.name }}</span></span>
                        </li>
                    </ul>
                </template>
            </section>

            <!-- actions -->
            <aside class="side">
                <section v-if="hasActions || !can.respond" class="card blk acts">
                    <PositionPicker v-if="can.join && positions.length" v-model="joinPos" :positions="positions" :label="$t('games.position.pick')" />
                    <template v-for="(a, i) in actions" :key="a.key">
                        <Link v-if="a.href" :class="['btn block', { outline: i > 0 }]" :href="a.href"><Icon :name="a.icon" size="sm" />{{ a.label }}</Link>
                        <button v-else type="button" :class="['btn block', { outline: i > 0 }]" :disabled="busy" @click="a.run?.()"><Icon :name="a.icon" size="sm" />{{ a.label }}</button>
                    </template>
                    <p v-if="!chatUrl && g.me && ['accepted', 'joined'].includes(g.me) && g.status === 'forming'" class="small faint">{{ $t('games.show.chat_later') }}</p>
                    <p v-if="!hasActions" class="small faint" style="margin: 0">
                        {{ g.status === 'cancelled' ? $t('games.show.cancelled_note') : g.me === 'removed' ? $t('social.error.removed') : $t('games.show.nothing') }}
                    </p>
                    <div v-if="can.leave || can.cancel" class="links">
                        <button v-if="can.leave" type="button" class="btn link" @click="askLeave">{{ $t('games.show.leave') }}</button>
                        <button v-if="can.cancel" type="button" class="btn link danger" @click="askCancel">{{ $t('games.show.cancel') }}</button>
                    </div>
                </section>

                <!-- only the creator books the field; the others see who will -->
                <p v-if="!g.booking && !can.book && ['joined', 'accepted'].includes(g.me ?? '') && ['open', 'full'].includes(g.status)" class="card blk orgbook">
                    <Icon name="calendar" size="sm" />{{ $t('games.show.organiser_books', { name: g.creator.name }) }}
                </p>
                <section v-if="g.booking" :class="['card blk bk', { off: !holding }]">
                    <div class="row between">
                        <h3>{{ $t('games.show.field') }}</h3>
                        <StatusPill :status="g.booking.status" />
                    </div>
                    <div>
                        <Link :href="route('places.show', { venue: g.booking.venue.slug })" class="bname">{{ g.booking.venue.name }}</Link>
                        <div class="muted small">{{ $t(`player.size.${g.booking.field.size}`) }} {{ g.booking.field.format }} · {{ $t(`common.districts.${g.booking.venue.district}`) }}</div>
                    </div>
                    <div class="when">{{ bookWhen(g.booking) }}</div>
                    <div class="row between small">
                        <span class="price">{{ amd(g.booking.price) }}</span>
                        <span class="code">{{ g.booking.code }}</span>
                    </div>
                    <p class="small" :class="g.booking.status === 'accepted' ? 'okt' : 'muted'" style="margin: 0">
                        {{ g.booking.status === 'pending' ? $t('games.show.booking_pending') : g.booking.status === 'accepted' ? $t('games.show.booking_accepted') : $t('games.show.booking_off') }}
                    </p>
                </section>
            </aside>
        </div>

        <!-- confirm -->
        <div v-if="confirm" class="scrim" @click.self="confirm = null">
            <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="cft">
                <div class="row">
                    <h3 id="cft">{{ $t(confirm.title, { name: removing?.name ?? '' }) }}</h3>
                    <button type="button" class="x" :aria-label="$t('common.close')" @click="confirm = null"><Icon name="x" /></button>
                </div>
                <p class="muted">{{ $t(confirm.text, { name: removing?.name ?? '' }) }}</p>
                <div class="row">
                    <button type="button" class="btn outline" style="flex: 1" @click="confirm = null">{{ $t('games.show.keep') }}</button>
                    <button type="button" class="btn danger" style="flex: 1" :disabled="busy" @click="confirm.run()">{{ $t(confirm.yes) }}</button>
                </div>
            </div>
        </div>

        <!-- my position in this game -->
        <div v-if="posEditing" class="scrim" @click.self="posEditing = false">
            <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="pst">
                <div class="row">
                    <h3 id="pst">{{ $t('games.position.title') }}</h3>
                    <button type="button" class="x" :aria-label="$t('common.close')" @click="posEditing = false"><Icon name="x" /></button>
                </div>
                <p class="muted small" style="margin: 8px 0 14px">{{ $t('games.position.hint') }}</p>
                <PositionPicker v-model="posPick" :positions="positions" />
                <button type="button" class="btn block" style="margin-top: 16px" :disabled="busy" @click="savePos"><Icon name="check" size="sm" />{{ $t('common.save') }}</button>
            </div>
        </div>

        <!-- invite more friends -->
        <div v-if="inviting" class="scrim" @click.self="inviting = false">
            <div class="sheet" role="dialog" aria-modal="true" aria-labelledby="ivt">
                <div class="row">
                    <h3 id="ivt">{{ $t('games.show.invite_more') }}</h3>
                    <button type="button" class="x" :aria-label="$t('common.close')" @click="inviting = false"><Icon name="x" /></button>
                </div>
                <p v-if="!friends.length" class="muted">{{ $t('games.show.invite_none') }}</p>
                <template v-else>
                    <input v-model="q" type="search" class="input" style="margin: 12px 0 8px" :placeholder="$t('common.search_friends')" :aria-label="$t('common.search_friends')" />
                    <ul class="flist">
                        <li v-for="f in shownFriends" :key="f.id">
                            <label :class="{ on: picked.includes(f.id) }">
                                <input type="checkbox" :checked="picked.includes(f.id)" @change="togglePick(f.id)" />
                                <Avatar :id="f.id" :name="f.name" :src="f.avatar" size="sm" />
                                <span class="nm">{{ f.name }}</span>
                                <span class="tick"><Icon name="check" size="sm" /></span>
                            </label>
                        </li>
                    </ul>
                    <button type="button" class="btn block" style="margin-top: 14px" :disabled="busy || !picked.length" @click="sendInvites">
                        <Icon name="send" size="sm" />{{ $t('games.show.invite_send', { n: String(picked.length) }) }}
                    </button>
                </template>
            </div>
        </div>

        <!-- book a field -->
        <div v-if="booking" class="scrim" @click.self="booking = false">
            <div class="sheet wide" role="dialog" aria-modal="true" aria-labelledby="bkt">
                <div class="row">
                    <h3 id="bkt">{{ $t(isPingpong ? 'games.show.book_table' : 'games.show.book') }}</h3>
                    <button type="button" class="x" :aria-label="$t('common.close')" @click="booking = false"><Icon name="x" /></button>
                </div>
                <p class="muted small" style="margin: 8px 0 14px">{{ $t('games.show.book_note') }}</p>
                <div class="field">
                    <label for="bp">{{ $t('common.place') }}</label>
                    <select id="bp" v-model="form.place" class="input">
                        <option v-for="p in places" :key="p.id" :value="p.id">{{ p.name }} · {{ $t(`common.districts.${p.district}`) }}</option>
                    </select>
                </div>
                <div v-if="place" class="field" style="margin-top: 12px">
                    <label>{{ $t('common.field') }}</label>
                    <div class="chips">
                        <button v-for="f in place.fields" :key="f.id" type="button" class="chip" :aria-pressed="form.field_id === f.id" @click="form.field_id = f.id">
                            {{ $t(`player.size.${f.size}`) }} {{ f.format }}
                        </button>
                    </div>
                </div>
                <div class="field" style="margin-top: 12px">
                    <label>{{ $t('common.day') }}</label>
                    <div class="days">
                        <button v-for="d in days" :key="d.date" type="button" :class="['day', { we: d.weekend }]" :aria-pressed="form.date === d.date" @click="form.date = d.date">
                            <small>{{ d.k === 0 ? $t('common.today') : wdName(d.wd) }}</small><b>{{ d.day }}</b>
                        </button>
                    </div>
                </div>
                <div v-if="field" class="field" style="margin-top: 12px">
                    <label>{{ $t('common.duration') }}</label>
                    <div class="chips">
                        <button v-for="m in field.durations" :key="m" type="button" class="chip" :aria-pressed="form.minutes === m" @click="form.minutes = m">{{ dur(m) }}</button>
                    </div>
                </div>
                <div class="field" style="margin-top: 12px">
                    <label>{{ $t('games.show.start') }}</label>
                    <div :class="['starts', { dim: slotsLoading }]">
                        <button v-for="h in starts ?? []" :key="h" type="button" :class="['st', { on: form.start === hhmm(h) }]" @click="form.start = hhmm(h)">{{ hhmm(h) }}</button>
                        <span v-if="!slotsLoading && starts && !starts.length" class="faint small">{{ $t('games.show.no_slots') }}</span>
                        <span v-if="slotsLoading && !starts" class="faint small">{{ $t('games.show.loading') }}</span>
                    </div>
                </div>
                <div v-if="bookErr" class="err">{{ bookErr }}</div>
                <div v-for="k in ['field_id', 'date', 'start', 'minutes'] as const" :key="k">
                    <div v-if="form.errors[k]" class="err">{{ form.errors[k] }}</div>
                </div>
                <button type="button" class="btn block" style="margin-top: 16px" :disabled="form.processing || !form.start || !form.field_id" @click="sendBooking">
                    <Icon name="send" size="sm" />{{ form.start ? $t('games.show.book_send', { t: form.start }) : $t('games.show.book_pick') }}
                </button>
            </div>
        </div>
    </PlayerLayout>
</template>

<style scoped>
.back { display: inline-flex; gap: 6px; align-items: center; color: var(--muted); font-weight: 700; font-size: 14px; margin-top: 22px; }
.back:hover { color: var(--ink); }
.hero { margin-top: 12px; padding: 22px; display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); gap: 22px; align-items: start; }
.hl { display: flex; flex-direction: column; gap: 10px; min-width: 0; }
.hr { display: flex; flex-direction: column; gap: 10px; background: var(--bg-2); border-radius: 16px; padding: 16px; }
.tags { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.fmt { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; background: var(--surface-2); border: 1px solid var(--line-2); font-weight: 800; font-size: 12.5px; white-space: nowrap; }
.mine { font-size: 12.5px; font-weight: 700; color: var(--muted); }
.hero h1 { overflow-wrap: anywhere; }
.facts { display: flex; flex-wrap: wrap; gap: 6px 16px; color: var(--muted); font-size: 14px; align-items: center; }
.facts span { display: inline-flex; gap: 6px; align-items: center; }
.facts .when { color: var(--ink); font-weight: 700; }
.tbd { padding: 2px 10px; border-radius: 999px; border: 1px dashed var(--line-2); font-weight: 700; font-size: 13px; }
.ul:hover { color: var(--accent); }
.note { margin: 0; color: var(--ink); font-size: 15px; overflow-wrap: anywhere; }
.prev { display: inline-flex; gap: 6px; align-items: center; font-size: 13px; font-weight: 700; }
.small { font-size: 13px; margin: 0; }
.okt { color: var(--st-confirmed); }
.howbtn { align-self: flex-start; display: inline-flex; align-items: center; gap: 8px; background: none; border: 0; color: var(--muted); font-weight: 700; font-size: 13px; padding: 2px 0; }
.howbtn:hover { color: var(--ink); }
.howbtn .i { width: 18px; height: 18px; border-radius: 50%; border: 1.5px solid currentColor; display: grid; place-items: center; font-size: 11px; font-weight: 800; font-style: italic; font-family: Georgia, serif; }
.rule { line-height: 1.5; }
@media (max-width: 760px) { .hero { grid-template-columns: minmax(0, 1fr); padding: 18px; gap: 16px; } }
.banner { margin-top: 14px; padding: 16px 18px; display: flex; align-items: center; gap: 14px; flex-wrap: wrap; border-color: rgba(255, 181, 71, 0.3); }
.banner p { margin-top: 2px; }
.bact { gap: 8px; }
@media (max-width: 600px) { .bact { width: 100%; } .bact .btn { flex: 1; } }
.sgrid { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); gap: 16px; margin-top: 16px; align-items: start; }
@media (max-width: 860px) { .sgrid { grid-template-columns: minmax(0, 1fr); } .side { order: -1; } }
.side { display: flex; flex-direction: column; gap: 16px; min-width: 0; }
.blk { padding: 18px; display: flex; flex-direction: column; gap: 12px; min-width: 0; }
.blk h3 { font-family: var(--body); font-size: 16px; font-weight: 800; letter-spacing: 0; display: flex; gap: 8px; align-items: center; }
.blk h4 { margin: 6px 0 0; font-size: 13px; color: var(--muted); font-weight: 700; display: flex; gap: 8px; align-items: center; }
.cnt { font-size: 12px; background: var(--surface-3); color: var(--muted); border-radius: 99px; padding: 2px 8px; font-weight: 700; }
.plist { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 6px; }
.plist li { display: flex; align-items: center; gap: 10px; padding: 8px 6px 8px 10px; border-radius: 12px; background: var(--bg-2); min-width: 0; min-height: 56px; }
.plist .nm { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.plist .n1 { font-weight: 700; font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.plist .nm small { color: var(--muted); font-weight: 600; font-size: 12px; }
.plist.dim li { opacity: .75; }
.plist .slot { background: none; border: 1px dashed var(--line-2); padding-right: 10px; }
.plist .slot .nm { color: var(--muted); font-weight: 700; font-size: 14px; }
.plus { width: 40px; height: 40px; border-radius: 50%; border: 1.5px dashed var(--line-2); display: grid; place-items: center; color: var(--muted); font-size: 13px; font-weight: 800; flex: none; }
.acts .links { display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; margin-top: 2px; }
.acts .links .btn { padding-left: 0; padding-right: 0; font-size: 13.5px; }
.acts .links .btn.danger { margin-left: auto; }
.bk h3 { font-size: 15px; }
.bk .bname { font-weight: 800; font-size: 16px; }
.bk .bname:hover { color: var(--accent); }
.bk .when { font-weight: 800; }
.bk .code { font-family: var(--mono); font-size: 12.5px; color: var(--accent); background: rgba(200, 255, 61, 0.08); border: 1px dashed rgba(200, 255, 61, 0.3); padding: 2px 8px; border-radius: 7px; letter-spacing: 0.06em; }
.bk.off { opacity: .65; }
.sheet.wide { width: min(560px, 100%); }
.sheet .days, .sheet .chips { max-width: 100%; }
.day.we:not([aria-pressed="true"]) small { color: var(--st-pending); }
.starts { display: flex; flex-wrap: wrap; gap: 6px; min-height: 34px; align-items: center; }
.starts.dim { opacity: .5; }
.st { font-family: var(--mono); font-size: 13px; padding: 6px 10px; border-radius: 9px; background: var(--free-soft); color: var(--free); border: 1px solid rgba(94, 240, 168, 0.2); }
.st.on { background: var(--accent); color: var(--on-accent); border-color: var(--accent); }
.err { color: var(--hot); font-size: 13px; font-weight: 700; margin-top: 10px; }
.flist { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; max-height: 50vh; overflow: auto; }
.flist label { position: relative; display: flex; align-items: center; gap: 12px; padding: 8px 10px; border-radius: 12px; cursor: pointer; border: 1px solid transparent; }
.flist label:hover { background: var(--surface-2); }
.flist label.on { background: rgba(200, 255, 61, 0.06); border-color: rgba(200, 255, 61, 0.25); }
.flist input { position: absolute; opacity: 0; pointer-events: none; }
.flist .nm { flex: 1; min-width: 0; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.flist .tick { width: 22px; height: 22px; border-radius: 50%; border: 1.5px solid var(--line-2); display: grid; place-items: center; color: transparent; flex: none; }
.flist label.on .tick { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }

.bpos { flex-basis: 100%; }
.possum { display: flex; flex-wrap: wrap; gap: 0 4px; align-items: baseline; font-weight: 700; margin-top: -4px; }
.possum abbr, abbr.pos { text-decoration: none; }
.warnt { color: var(--warn); }
.pos { flex: none; border: 0; cursor: default; }
button.pos { cursor: pointer; }
button.pos:not(.blue) { background: none; border: 1px dashed var(--line-2); }
button.pos:hover { color: var(--ink); }

.orgbook { display: flex; gap: 10px; align-items: center; color: var(--muted); font-size: 14px; margin: 0; }
</style>
