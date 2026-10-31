<script setup lang="ts">
import Bracket, { type BracketData, type Match } from '@/components/club/Bracket.vue';
import TournamentPhase, { type Phase } from '@/components/club/TournamentPhase.vue';
import Icon from '@/components/Icon.vue';
import OwnerSheet from '@/components/owner/OwnerSheet.vue';
import TournamentForm, { type TournamentSettings } from '@/components/owner/TournamentForm.vue';
import { ask } from '@/composables/useConfirm';
import { useMay, useOwner } from '@/composables/useOwner';
import { amd } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { trans } from '@/lib/i18n';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

// One tournament: its link and settings; the registered players (filters: waiting / accepted / declined / paid /
// not paid) with accept / decline, the fee (card or cash); "remind the unpaid"; the draw and
// the bracket (tap a match: the winner, the score, playing now — live for everybody, players only look); news to the
// players (everybody / accepted / waiting: the waiting ones get a notification only, they are not in the chat); the chat.
interface Entry {
    id: number;
    name: string;
    phone: string;
    tel: string;
    level: number | null;
    option: string;
    price: number;
    status: 'applied' | 'accepted' | 'declined';
    place: number | null;
    account: boolean;
    paid: { amount: number; method: string } | null;
    /** the balance of the player's card here; null: no card (no "from the card") */
    card: number | null;
    cardCode: string | null;
    /** the player's account (null: registered by phone only) */
    user: number | null;
}
interface Division {
    id: number;
    division: string | null;
    min_rating: number | null;
    max_rating: number | null;
    max_players: number | null;
    phase: Phase;
    accepted: number;
    waiting: number;
}
const props = defineProps<{
    tournament: TournamentSettings & {
        phase: Phase;
        link: string;
        drawn: boolean;
        list: Entry[];
        bracket: BracketData | null;
        chat: number | null;
        unpaid: number;
        divisions: Division[];
        siblings: { id: number; division: string | null }[];
    };
}>();
const owner = useOwner();
// the owner changes the settings and cancels (or staff the owner let make tournaments); staff run it too (players,
// fees, the draw, results, news)
const canEdit = useMay()('tournaments', 'create');
const has = (f: string) => (owner.value.features ?? []).includes(f);
const t = computed(() => props.tournament);
const editing = ref(false);
const opts = { preserveScroll: true };

// ---------- players ----------
type Filter = 'all' | 'applied' | 'accepted' | 'declined' | 'paid' | 'unpaid';
const filter = ref<Filter>('all');
const owes = (e: Entry) => e.status === 'accepted' && !e.paid && e.price > 0;
const test: Record<Filter, (e: Entry) => boolean> = {
    all: (e) => e.status !== 'declined',
    applied: (e) => e.status === 'applied',
    accepted: (e) => e.status === 'accepted',
    declined: (e) => e.status === 'declined',
    paid: (e) => !!e.paid,
    unpaid: owes,
};
const filtered = computed(() => t.value.list.filter(test[filter.value]));
// 10 players a page
const PER = 10;
const page = ref(0);
const pages = computed(() => Math.max(1, Math.ceil(filtered.value.length / PER)));
const shown = computed(() => filtered.value.slice(page.value * PER, (page.value + 1) * PER));
watch(filter, () => (page.value = 0));
watch(pages, (n) => page.value >= n && (page.value = n - 1));
const count = (f: Filter) => t.value.list.filter(test[f]).length;
const accepted = computed(() => count('accepted'));
const entry = (id: number, data: Record<string, string>) =>
    router.patch(route('owner.tournaments.entry', id), data, { preserveScroll: true, preserveState: true });
const started = computed(() => ['live', 'done', 'cancelled'].includes(t.value.phase));
const over = computed(() => t.value.phase === 'done' || t.value.phase === 'cancelled');
// fees are taken once registration is closed (closed / draw made): a tournament cancelled for too few players has nothing to give back
const payNow = computed(() => ['closed', 'drawn', 'live'].includes(t.value.phase));
const range = (min: number | null, max: number | null) =>
    min == null && max == null ? trans('owner.tournament.any_rating') : min == null ? `≤ ${max}` : max == null ? `${min}+` : `${min}–${max}`;

// every step that changes something for a player asks first
async function decline(e: Entry) {
    if (await ask({ title: trans('owner.tournament.ask_decline', { name: e.name }), ok: trans('common.decline'), danger: true }))
        entry(e.id, { status: 'declined' });
}
async function pay(e: Entry, how: 'card' | 'cash') {
    const text =
        how === 'card'
            ? trans('owner.tournament.ask_card_text', { balance: amd(e.card ?? 0), after: amd((e.card ?? 0) - e.price) })
            : trans('owner.tournament.ask_cash_text');
    const title = trans(how === 'card' ? 'owner.tournament.ask_card' : 'owner.tournament.ask_cash', { amount: amd(e.price), name: e.name });
    if (await ask({ title, text, ok: trans('owner.tournament.ask_take') })) entry(e.id, { pay: how });
}
async function removeEntry(e: Entry) {
    const notes = [
        e.paid
            ? trans(e.paid.method === 'card' ? 'owner.tournament.remove_refund_card' : 'owner.tournament.remove_refund_cash', {
                  amount: amd(e.paid.amount),
              })
            : '',
        t.value.drawn && e.status === 'accepted' ? trans('owner.tournament.remove_redraw') : '',
    ].filter(Boolean);
    if (
        await ask({ title: trans('owner.tournament.ask_remove', { name: e.name }), text: notes.join('\n'), ok: trans('common.remove'), danger: true })
    )
        router.delete(route('owner.tournaments.remove', e.id), { preserveScroll: true, preserveState: true });
}
// ---------- cancel (before the start): a reason the players get; paid fees go back ----------
const cancelling = ref(false);
const reason = ref('');
const REASONS = ['few', 'closed'] as const;
const paidCount = computed(() => t.value.list.filter((e) => e.paid).length);
const canCancel = computed(() => canEdit && !['live', 'done', 'cancelled'].includes(t.value.phase));
async function cancelNow() {
    const isAll = t.value.divisions.length > 0;
    if (!reason.value.trim()) return;
    if (await ask({ title: trans(isAll ? 'owner.tournament.ask_cancel_all' : 'owner.tournament.ask_cancel', { title: t.value.title }), text: reason.value, ok: trans('owner.tournament.cancel_btn'), danger: true }))
        router.post(route('owner.tournaments.cancel', t.value.id), { reason: reason.value }, { preserveScroll: true, onSuccess: () => (cancelling.value = false) });
}
// tap a player's name: call, message (the place's chat with them), their page, their card (or make one and top it up)
const who = ref<Entry | null>(null);
const here = () => window.location.pathname;
const message = (e: Entry) => router.post(route('owner.players.chat', e.user!));
const newCard = (e: Entry) => router.post(route('owner.cards.store'), { user_id: e.user, back: here() });
async function remind() {
    if (await ask({ title: trans('owner.tournament.ask_remind', { n: count('unpaid') }), ok: trans('owner.tournament.send') }))
        router.post(route('owner.tournaments.remind', t.value.id), {}, opts);
}
const placeName = (n: number) => (n === 1 ? '🥇' : n === 2 ? '🥈' : n === 3 ? '🥉' : `#${n}`);

// ---------- the draw and the bracket ----------
async function draw() {
    const title = trans(t.value.drawn ? 'owner.tournament.ask_redraw' : 'owner.tournament.ask_draw', { n: accepted.value });
    if (
        await ask({
            title,
            text: trans('owner.tournament.ask_draw_text'),
            ok: trans(t.value.drawn ? 'owner.tournament.redraw' : 'owner.tournament.draw'),
        })
    )
        router.post(route('owner.tournaments.draw', t.value.id), {}, { preserveScroll: true, preserveState: true });
}
// a match: a number next to each name, the higher one wins. The page keeps its place (round tab, page, scroll,
// full screen): preserveState.
const picked = ref<Match | null>(null);
const sc = ref<{ a: number | ''; b: number | '' }>({ a: '', b: '' });
const scoreErr = ref('');
function pick(m: Match) {
    picked.value = m;
    scoreErr.value = '';
    const p = /^\s*(\d+)\s*\D+\s*(\d+)\s*$/.exec(m.score ?? '');
    sc.value = p ? { a: +p[1], b: +p[2] } : { a: '', b: '' };
}
const keep = { preserveScroll: true, preserveState: true };
function setMatch(data: Record<string, string | number | boolean | null>) {
    if (!picked.value) return;
    router.patch(route('owner.tournaments.match', picked.value.id), data, {
        ...keep,
        onSuccess: () => (picked.value = null),
        onError: (e) => (scoreErr.value = Object.values(e)[0] ?? ''),
    });
}
const canSave = computed(() => sc.value.a !== '' && sc.value.b !== '' && sc.value.a !== sc.value.b);
async function saveScore() {
    const m = picked.value;
    if (!canSave.value || !m?.a || !m.b) return;
    const [a, b] = [Number(sc.value.a), Number(sc.value.b)];
    const won = a > b ? m.a.name : m.b.name;
    if (
        await ask({
            title: trans('owner.tournament.ask_score', { a: m.a.name, b: m.b.name, score: `${a}:${b}` }),
            text: trans('owner.tournament.ask_score_text', { name: won }),
            ok: trans('common.save'),
        })
    )
        setMatch({ score_a: a, score_b: b });
}
// a player didn't come: the other one goes on ("W/O")
async function walkover(absent: { id: number; name: string }) {
    const m = picked.value;
    if (!m?.a || !m.b) return;
    const other = absent.id === m.a.id ? m.b.name : m.a.name;
    if (
        await ask({
            title: trans('owner.tournament.ask_walkover', { name: absent.name }),
            text: trans('owner.tournament.ask_walkover_text', { name: other }),
            ok: trans('owner.tournament.walkover'),
            danger: true,
        })
    )
        setMatch({ walkover: absent.id });
}
const when = computed(() => `${t.value.date.slice(8)}.${t.value.date.slice(5, 7)} · ${t.value.time}`);

// ---------- news, chat ----------
const news = useForm({ text: '', to: 'all' as 'all' | 'accepted' | 'applied' });
async function sendNews() {
    if (await ask({ title: trans('owner.tournament.ask_news_' + news.to), text: news.text.slice(0, 200), ok: trans('owner.tournament.send') }))
        news.post(route('owner.tournaments.announce', t.value.id), { ...opts, onSuccess: () => news.reset('text') });
}
const openChat = () => router.post(route('owner.tournaments.chat', t.value.id));
const copied = ref(false);
async function copy() {
    try {
        await navigator.clipboard.writeText(t.value.link);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1800);
    } catch {
        window.prompt('', t.value.link);
    }
}
</script>

<template>
    <OwnerLayout :title="tournament.title">
        <Link v-if="tournament.parent" class="backlink" :href="route('owner.tournaments.show', tournament.parent.id)"
            ><Icon name="back" size="sm" />{{ tournament.parent.title }}</Link
        >
        <Link v-else class="backlink" :href="route('owner.tournaments')"><Icon name="back" size="sm" />{{ $t('owner.nav.tournaments') }}</Link>
        <!-- the divisions of the same tournament -->
        <nav v-if="tournament.siblings.length" class="chips sibs" :aria-label="$t('owner.tournament.divisions')">
            <Link
                v-for="d in tournament.siblings"
                :key="d.id"
                class="chip"
                :aria-pressed="d.id === tournament.id"
                :href="route('owner.tournaments.show', d.id)"
                >{{ d.division }}</Link
            >
        </nav>

        <section class="card panel">
            <div class="sec-h" style="align-items: flex-start">
                <div style="min-width: 0">
                    <h1 style="margin: 0; font-size: 22px">{{ tournament.title }}</h1>
                    <TournamentPhase :phase="tournament.phase" style="margin-top: 6px" />
                    <p class="muted" style="margin: 4px 0 0; font-size: 13.5px">
                        {{ when }} ·
                        {{ tournament.options.map((o) => `${o.label} ${amd(o.price)}`).join(' / ') }}
                    </p>
                    <p
                        v-if="tournament.min_rating != null || tournament.max_rating != null || tournament.max_players"
                        class="muted"
                        style="margin: 2px 0 0; font-size: 13px"
                    >
                        <template v-if="tournament.min_rating != null || tournament.max_rating != null">{{
                            $t('club.tournament.rating', { range: range(tournament.min_rating, tournament.max_rating) })
                        }}</template
                        ><template v-if="tournament.max_players">
                            · {{ $t('club.tournament.players', { n: accepted, max: tournament.max_players }) }}</template
                        >
                    </p>
                </div>
                <button v-if="canEdit && !editing && tournament.phase !== 'cancelled'" type="button" class="btn ghost sm" data-test="t-edit" @click="editing = true"><Icon name="edit" size="sm" /></button>
            </div>
            <div v-if="tournament.phase === 'cancelled'" class="cbanner" data-test="t-cancelled">
                <Icon name="x" size="sm" /><span><b>{{ $t('club.tournament.badge_cancelled') }}</b> · {{ tournament.cancel_reason }}</span>
            </div>
            <TournamentForm v-if="editing" :tournament="tournament" @done="editing = false" />
            <template v-else>
                <div class="linkbox">
                    <span class="mono">{{ tournament.link }}</span>
                    <button type="button" class="btn sm" @click="copy"><Icon :name="copied ? 'check' : 'share'" size="sm" />{{ copied ? $t('owner.club.copied') : $t('owner.club.copy_link') }}</button>
                </div>
                <p class="faint" style="font-size: 12.5px; margin: 6px 0 0">{{ $t('owner.club.link_hint') }}</p>
            </template>
        </section>

        <!-- a tournament with divisions: each division is its own tournament (players, fees, bracket, chat) -->
        <section v-if="tournament.divisions.length" class="card panel" style="margin-top: 14px">
            <div class="sec-h">
                <h2>{{ $t('owner.tournament.divisions') }}</h2>
            </div>
            <Link
                v-for="d in tournament.divisions"
                :key="d.id"
                :href="route('owner.tournaments.show', d.id)"
                class="drow"
                :data-test="`division-${d.id}`"
            >
                <span class="w">
                    <b>{{ d.division }}</b>
                    <small
                        >{{ $t('owner.tournament.rating_short', { range: range(d.min_rating, d.max_rating) }) }} ·
                        {{ d.max_players ? `${d.accepted}/${d.max_players}` : d.accepted }} {{ $t('owner.tournament.f_accepted').toLowerCase()
                        }}<template v-if="d.waiting"> · {{ $t('owner.tournament.n_waiting', { n: d.waiting }) }}</template></small
                    >
                </span>
                <TournamentPhase :phase="d.phase" />
                <Icon name="arrow" size="sm" />
            </Link>
        </section>

        <!-- the registered players -->
        <section v-if="!tournament.divisions.length" class="card panel" style="margin-top: 14px">
            <div class="sec-h">
                <h2>{{ $t('owner.tournament.players') }}</h2>
                <button v-if="count('unpaid') && payNow" type="button" class="btn ghost sm" data-test="t-remind" @click="remind">
                    <Icon name="bell" size="sm" />{{ $t('owner.tournament.remind_unpaid', { n: count('unpaid') }) }}
                </button>
            </div>
            <div class="chips filters" role="group">
                <button v-for="f in ['all', 'applied', 'accepted', 'declined', 'paid', 'unpaid'] as const" :key="f" type="button" class="chip" :aria-pressed="filter === f" :data-test="`f-${f}`" @click="filter = f">
                    {{ $t('owner.tournament.f_' + f) }} <span class="faint">{{ count(f) }}</span>
                </button>
            </div>
            <div v-for="e in shown" :key="e.id" :class="['erow', e.status]" :data-test="`entry-${e.id}`">
                <div class="w">
                    <button type="button" class="ename" :data-test="`who-${e.id}`" @click="who = e">
                        <span v-if="e.place" class="place">{{ placeName(e.place) }}</span>{{ e.name }}<Icon name="more" size="sm" class="faint" />
                    </button>
                    <small
                        ><a :href="`tel:${e.tel}`">{{ e.phone }}</a>{{ e.level != null ? ' · ' + $t('owner.club.level_n', { n: e.level }) : '' }} · {{ e.option }}
                        {{ e.price ? amd(e.price) : $t('club.free') }}</small
                    >
                </div>
                <span v-if="e.status === 'applied' && !started" class="row" style="gap: 6px">
                    <button type="button" class="btn sm" @click="entry(e.id, { status: 'accepted' })">{{ $t('common.accept') }}</button>
                    <button type="button" class="btn ghost sm" @click="decline(e)">{{ $t('common.decline') }}</button>
                </span>
                <span v-else-if="e.status === 'declined'" class="pill">{{ $t('owner.club.declined') }}</span>
                <template v-else-if="e.status === 'accepted'">
                    <span v-if="e.paid" class="pill green"
                        ><Icon name="check" size="sm" />{{ amd(e.paid.amount) }} · {{ $t('owner.club.m_' + e.paid.method) }}</span
                    >
                    <!-- the fee: what is owed, then the player's card (only when they have one here; not enough money: off) or cash -->
                    <span v-else-if="e.price && !over && !payNow" class="payrow later">
                        <span class="owe later">{{ $t('owner.tournament.fee_later', { a: amd(e.price) }) }}</span>
                    </span>
                    <span v-else-if="e.price && !over" class="payrow">
                        <span class="owe">{{ $t('owner.tournament.not_paid') }} · <b>{{ amd(e.price) }}</b></span>
                        <span class="pbtns">
                            <button
                                v-if="has('wallet') && e.card !== null"
                                type="button"
                                :class="['pb', 'card', { short: e.card < e.price }]"
                                :disabled="e.card < e.price"
                                :title="e.card < e.price ? $t('owner.tournament.card_short', { a: amd(e.card) }) : ''"
                                :data-test="`pay-card-${e.id}`"
                                @click="pay(e, 'card')"
                            >
                                <Icon name="wallet" size="sm" />{{ amd(e.card) }}
                            </button>
                            <button type="button" class="pb cash" :data-test="`pay-cash-${e.id}`" @click="pay(e, 'cash')">{{ $t('owner.club.cash') }}</button>
                        </span>
                    </span>
                </template>
                <!-- take the player off: only before the start -->
                <button
                    v-if="!started"
                    type="button"
                    class="btn ghost sm rm"
                    :aria-label="$t('common.remove')"
                    :data-test="`remove-${e.id}`"
                    @click="removeEntry(e)"
                >
                    <Icon name="trash" size="sm" />
                </button>
            </div>
            <p v-if="!shown.length" class="faint" style="margin: 8px 0 0">{{ $t('owner.club.no_entries') }}</p>
            <div v-if="pages > 1" class="pager">
                <button type="button" class="btn ghost sm" :disabled="page === 0" @click="page--">‹</button>
                <button v-for="i in pages" :key="i" type="button" :class="['btn', 'sm', { ghost: page !== i - 1 }]" @click="page = i - 1">{{ i }}</button>
                <button type="button" class="btn ghost sm" :disabled="page === pages - 1" @click="page++">›</button>
            </div>
        </section>

        <!-- the draw and the bracket -->
        <section v-if="!tournament.divisions.length" class="card panel" style="margin-top: 14px">
            <div class="sec-h">
                <h2>{{ $t('owner.tournament.bracket') }}</h2>
                <button v-if="!over" type="button" :class="['btn', 'sm', { ghost: tournament.drawn }]" :disabled="accepted < 2" data-test="draw" @click="draw">
                    <Icon name="trophy" size="sm" />{{ tournament.drawn ? $t('owner.tournament.redraw') : $t('owner.tournament.draw') }}
                </button>
            </div>
            <!-- the fees first: the tournament starts when everybody accepted has paid -->
            <button v-if="tournament.unpaid && payNow" type="button" class="paywarn" data-test="t-unpaid" @click="filter = 'unpaid'">
                <Icon name="wallet" size="sm" /><span>{{ $t('owner.tournament.pay_first', { n: tournament.unpaid }) }}</span>
            </button>
            <p v-else-if="tournament.unpaid && tournament.phase === 'registration'" class="faint paylater" data-test="t-pay-later">
                <Icon name="wallet" size="sm" />{{ $t('owner.tournament.pay_after_registration') }}
            </p>
            <template v-if="tournament.bracket">
                <p v-if="!over" class="faint" style="font-size: 12.5px; margin: 0 0 10px">{{ $t('owner.tournament.tap_match') }}</p>
                <Bracket :bracket="tournament.bracket" :editable="true" :title="tournament.title" :when="when" :players="accepted" @pick="pick" />
            </template>
            <p v-else class="faint" style="font-size: 13px; margin: 0">{{ $t('owner.tournament.draw_hint') }}</p>
        </section>

        <!-- news to the players, and the chat (not after the end: the chat stays readable in Messages) -->
        <section v-if="!over" class="card panel" style="margin-top: 14px">
            <div class="sec-h">
                <h2>{{ $t('owner.tournament.announce') }}</h2>
                <button v-if="!tournament.divisions.length" type="button" class="btn ghost sm" data-test="t-chat" @click="openChat">
                    <Icon name="chat" size="sm" />{{ $t('owner.tournament.open_chat') }}
                </button>
            </div>
            <form class="news" @submit.prevent="sendNews">
                <div class="chips aud" role="group">
                    <button v-for="a in ['all', 'accepted', 'applied'] as const" :key="a" type="button" class="chip" :aria-pressed="news.to === a" :data-test="`to-${a}`" @click="news.to = a">
                        {{ $t('owner.tournament.aud_' + a) }}
                    </button>
                </div>
                <textarea
                    v-model="news.text"
                    class="input"
                    rows="3"
                    maxlength="1000"
                    :placeholder="$t('owner.tournament.announce_ph')"
                    data-test="t-news"
                />
                <button class="btn" :disabled="news.processing || !news.text.trim()" data-test="t-send">
                    <Icon name="send" size="sm" />{{ $t('owner.tournament.send') }}
                </button>
                <small class="faint">{{
                    news.to === 'applied' ? $t('owner.tournament.announce_hint_applied') : $t('owner.tournament.announce_hint')
                }}</small>
                <small v-if="tournament.divisions.length" class="faint">{{ $t('owner.tournament.announce_divisions') }}</small>
            </form>
        </section>

        <!-- cancel before the start: not enough players, the place closed … -->
        <div v-if="canCancel" class="cancelrow">
            <button type="button" class="btn ghost sm hotb" data-test="t-cancel" @click="cancelling = true">
                <Icon name="x" size="sm" />{{ tournament.divisions.length ? $t('owner.tournament.cancel_all') : tournament.parent ? $t('owner.tournament.cancel_division') : $t('owner.tournament.cancel') }}
            </button>
        </div>
        <OwnerSheet v-if="cancelling" :title="tournament.divisions.length ? $t('owner.tournament.cancel_all') : $t('owner.tournament.cancel')" @close="cancelling = false">
            <div class="cform">
                <div class="chips" role="group">
                    <button v-for="r in REASONS" :key="r" type="button" class="chip" :aria-pressed="reason === $t('owner.tournament.reason_' + r)" @click="reason = $t('owner.tournament.reason_' + r)">
                        {{ $t('owner.tournament.reason_' + r) }}
                    </button>
                </div>
                <textarea v-model="reason" class="input" rows="3" maxlength="300" :placeholder="$t('owner.tournament.reason_ph')" data-test="cancel-reason" />
                <p class="faint" style="font-size: 12.5px; margin: 0">
                    {{ tournament.divisions.length ? $t('owner.tournament.cancel_hint_all') : $t('owner.tournament.cancel_hint', { n: paidCount }) }}
                </p>
                <button type="button" class="btn block danger" :disabled="reason.trim().length < 3" data-test="cancel-go" @click="cancelNow">
                    {{ $t('owner.tournament.cancel_btn') }}
                </button>
            </div>
        </OwnerSheet>

        <!-- a player: call, message, their page, their card -->
        <OwnerSheet v-if="who" :title="who.name" :sub="`${who.option} · ${who.price ? amd(who.price) : $t('club.free')}`" @close="who = null">
            <div class="who-acts">
                <a class="wa" :href="`tel:${who.tel}`"><Icon name="phone" /><span><b>{{ $t('common.call') }}</b><small>{{ who.phone }}</small></span></a>
                <button v-if="who.user" type="button" class="wa" data-test="who-message" @click="message(who)">
                    <Icon name="chat" /><span><b>{{ $t('owner.tournament.who_message') }}</b><small>{{ $t('owner.tournament.who_message_hint') }}</small></span>
                </button>
                <Link v-if="who.user && (has('wallet') || has('rating'))" class="wa" :href="route('owner.players.show', who.user)">
                    <Icon name="user" /><span><b>{{ $t('owner.tournament.who_page') }}</b><small>{{ $t('owner.tournament.who_page_hint') }}</small></span>
                </Link>
                <template v-if="who.user && has('wallet')">
                    <Link v-if="who.cardCode" class="wa" :href="route('owner.cards.show', { code: who.cardCode, back: here() })" data-test="who-card">
                        <Icon name="wallet" /><span><b>{{ $t('owner.tournament.who_card', { a: amd(who.card ?? 0) }) }}</b><small>{{ $t('owner.tournament.who_card_hint') }}</small></span>
                    </Link>
                    <button v-else type="button" class="wa" data-test="who-new-card" @click="newCard(who)">
                        <Icon name="plus" /><span><b>{{ $t('owner.tournament.who_new_card') }}</b><small>{{ $t('owner.tournament.who_new_card_hint') }}</small></span>
                    </button>
                </template>
                <p v-if="!who.user" class="faint" style="font-size: 13px; margin: 4px 0 0">{{ $t('owner.tournament.who_no_account') }}</p>
            </div>
        </OwnerSheet>

        <!-- a match: who won (and the score), or playing now -->
        <OwnerSheet v-if="picked" :title="$t('owner.tournament.who_won')" @close="picked = null">
            <!-- a number next to each name; the higher one wins -->
            <form class="who" @submit.prevent="saveScore">
                <label v-for="(s, k) in [picked.a, picked.b]" :key="s!.id" :class="['srow', { won: picked.winner === s!.id }]">
                    <span class="sname"
                        >{{ s!.name }}<small v-if="s!.rating">{{ s!.rating }}</small>
                        <button type="button" class="wo" :data-test="`wo-${k === 0 ? 'a' : 'b'}`" @click.prevent="walkover(s!)">
                            {{ $t('owner.tournament.no_show') }}
                        </button></span
                    >
                    <input
                        v-model.number="sc[k === 0 ? 'a' : 'b']"
                        class="input snum"
                        type="number"
                        inputmode="numeric"
                        min="0"
                        max="99"
                        placeholder="0"
                        :aria-label="s!.name"
                        :data-test="`score-${k === 0 ? 'a' : 'b'}`"
                    />
                </label>
                <div v-if="scoreErr" class="err">{{ scoreErr }}</div>
                <button class="btn block" :disabled="!canSave" data-test="score-save"><Icon name="check" size="sm" />{{ $t('common.save') }}</button>
                <button v-if="!picked.winner" type="button" class="btn ghost block" @click="setMatch({ live: !picked.live })">
                    <i class="ldot" />{{ picked.live ? $t('owner.tournament.live_off') : $t('owner.tournament.live_on') }}
                </button>
                <small class="faint">{{ $t('owner.tournament.score_hint') }}</small>
            </form>
        </OwnerSheet>

    </OwnerLayout>
</template>

<style scoped>
.linkbox { display: flex; gap: 10px; align-items: center; background: var(--surface-2); border-radius: 12px; padding: 8px 8px 8px 12px; margin-top: 12px; }
.linkbox .mono { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 13px; }
.filters { margin: 4px 0 8px; flex-wrap: wrap; }
.erow { display: flex; gap: 10px; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--line); flex-wrap: wrap; }
.erow.declined { opacity: 0.5; }
.erow .w { flex: 1; min-width: 180px; display: flex; flex-direction: column; }
.erow small { color: var(--muted); font-size: 12.5px; }
.erow .place { margin-right: 4px; }
.pill.hot { color: var(--hot); background: var(--hot-soft); }
.news { display: grid; gap: 8px; min-width: 0; }
.news textarea { width: 100%; box-sizing: border-box; resize: vertical; }
.news small { font-size: 12px; }
.aud { display: flex; flex-wrap: wrap; gap: 6px; }
.who { display: grid; gap: 10px; }
.srow { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 14px; border: 1px solid var(--line-2); background: var(--surface-2); }
.srow.won { border-color: color-mix(in srgb, var(--free) 55%, transparent); }
.sname { flex: 1; min-width: 0; display: flex; flex-direction: column; font-weight: 800; }
.sname small { font-family: var(--mono); font-size: 11px; color: var(--muted); font-weight: 500; }
.snum::-webkit-outer-spin-button, .snum::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.snum { -moz-appearance: textfield; appearance: textfield; width: 76px; text-align: center; font: 700 22px var(--mono); padding: 8px; }
.sibs { display: flex; gap: 6px; overflow-x: auto; margin: 0 0 12px; padding-bottom: 2px; scrollbar-width: none; }
.sibs .chip { flex: none; text-decoration: none; }
.drow { display: flex; align-items: center; gap: 10px; padding: 12px 0; border-bottom: 1px solid var(--line); color: inherit; text-decoration: none; }
.drow:last-child { border-bottom: 0; }
.drow { margin: 0 -10px; padding-inline: 10px; border-radius: 12px; transition: background 0.15s; }
.drow:hover { background: var(--surface-2); }
.drow:hover > .ico:last-child { color: var(--accent); transform: translateX(3px); }
.drow > .ico:last-child { transition: transform 0.15s; }
.drow .w { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.drow small { color: var(--muted); font-size: 12.5px; }
.rm { order: 1; margin-left: auto; padding-inline: 8px; }
.payrow { order: 2; display: flex; align-items: center; justify-content: space-between; gap: 8px; width: 100%; flex-wrap: wrap; }
.owe { color: var(--hot); font-size: 13px; font-weight: 700; }
.owe b { font-family: var(--mono); }
.owe.later { color: var(--muted); font-weight: 600; font-size: 12.5px; }
.paylater { display: flex; gap: 8px; align-items: flex-start; font-size: 12.5px; margin: 0 0 12px; }
.pbtns { display: flex; gap: 6px; }
.pb { display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 12px; border-radius: 999px; border: 1px solid var(--line-2); background: var(--surface-2); color: var(--ink); font: 700 13px var(--body); cursor: pointer; white-space: nowrap; transition: border-color 0.15s, background 0.15s; }
.pb.card { font-family: var(--mono); color: #8fd9ff; border-color: rgba(92, 200, 255, 0.4); }
.pb.card:hover:not(:disabled) { background: rgba(92, 200, 255, 0.12); }
.pb.card.short { color: var(--faint); border-color: var(--line); cursor: not-allowed; text-decoration: line-through; }
.pb.cash:hover { border-color: var(--accent); }
.ename { display: inline-flex; align-items: center; gap: 6px; border: 0; background: none; padding: 0; color: var(--ink); font: 800 15px var(--body); cursor: pointer; text-align: left; }
.ename:hover { color: var(--accent); }
.cbanner { display: flex; align-items: center; gap: 8px; margin-top: 12px; padding: 10px 12px; border-radius: 12px; background: var(--hot-soft); color: #ff8a8a; font-size: 13.5px; }
.cancelrow { display: flex; justify-content: center; margin: 18px 0 6px; }
.hotb { color: var(--hot); border-color: rgba(255, 107, 107, 0.3); }
.cform { display: grid; gap: 10px; }
.cform textarea { width: 100%; box-sizing: border-box; resize: vertical; }
.cform .chips { display: flex; flex-wrap: wrap; gap: 6px; }
.who-acts { display: grid; gap: 8px; }
.wa { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 14px; border: 1px solid var(--line-2); background: var(--surface-2); color: var(--ink); text-decoration: none; text-align: left; font: inherit; cursor: pointer; }
.wa:hover { border-color: var(--accent); }
.wa span { display: flex; flex-direction: column; min-width: 0; }
.wa small { color: var(--muted); font-size: 12.5px; }
.paywarn { display: flex; gap: 8px; align-items: center; width: 100%; text-align: left; margin: 0 0 12px; padding: 10px 12px; border-radius: 12px; border: 1px solid rgba(255, 107, 107, 0.3); background: var(--hot-soft); color: var(--hot); font: inherit; font-size: 13.5px; font-weight: 600; cursor: pointer; }
.wo { align-self: flex-start; margin-top: 4px; border: 0; background: none; padding: 0; color: var(--hot); font: 600 12px var(--font, inherit); text-decoration: underline; cursor: pointer; }
.pager { display: flex; flex-wrap: wrap; gap: 4px; justify-content: center; margin-top: 12px; }
.ldot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #ff6b6b; margin-right: 6px; }
</style>
