<script setup lang="ts">
import Bracket, { type BracketData, type Match } from '@/components/club/Bracket.vue';
import Icon from '@/components/Icon.vue';
import OwnerSheet from '@/components/owner/OwnerSheet.vue';
import TournamentForm, { type TournamentSettings } from '@/components/owner/TournamentForm.vue';
import { useOwner } from '@/composables/useOwner';
import { amd } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

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
}
const props = defineProps<{ tournament: TournamentSettings & { link: string; drawn: boolean; list: Entry[]; bracket: BracketData | null; chat: number | null } }>();
const owner = useOwner();
const canEdit = owner.value.role === 'owner';
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
const shown = computed(() => t.value.list.filter(test[filter.value]));
const count = (f: Filter) => t.value.list.filter(test[f]).length;
const accepted = computed(() => count('accepted'));
const entry = (id: number, data: Record<string, string>) => router.patch(route('owner.tournaments.entry', id), data, opts);
const remind = () => router.post(route('owner.tournaments.remind', t.value.id), {}, opts);
const placeName = (n: number) => (n === 1 ? '🥇' : n === 2 ? '🥈' : n === 3 ? '🥉' : `#${n}`);

// ---------- the draw and the bracket ----------
const draw = () => router.post(route('owner.tournaments.draw', t.value.id), {}, opts);
const picked = ref<Match | null>(null);
const score = ref('');
function pick(m: Match) {
    picked.value = m;
    score.value = m.score ?? '';
}
function setMatch(data: Record<string, string | number | boolean | null>) {
    if (picked.value) router.patch(route('owner.tournaments.match', picked.value.id), data, { ...opts, onSuccess: () => (picked.value = null) });
}
const when = computed(() => `${t.value.date.slice(8)}.${t.value.date.slice(5, 7)} · ${t.value.time}`);

// ---------- news, chat ----------
const news = useForm({ text: '', to: 'all' as 'all' | 'accepted' | 'applied' });
const sendNews = () => news.post(route('owner.tournaments.announce', t.value.id), { ...opts, onSuccess: () => news.reset('text') });
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
        <Link class="backlink" :href="route('owner.tournaments')"><Icon name="back" size="sm" />{{ $t('owner.nav.tournaments') }}</Link>

        <section class="card panel">
            <div class="sec-h" style="align-items: flex-start">
                <div style="min-width: 0">
                    <h1 style="margin: 0; font-size: 22px">{{ tournament.title }}</h1>
                    <p class="muted" style="margin: 4px 0 0; font-size: 13.5px">
                        {{ when }} · {{ $t('owner.club.t_status_' + tournament.status) }} ·
                        {{ tournament.options.map((o) => `${o.label} ${amd(o.price)}`).join(' / ') }}
                    </p>
                    <p v-if="tournament.min_rating != null || tournament.max_rating != null || tournament.max_players" class="muted" style="margin: 2px 0 0; font-size: 13px">
                        <template v-if="tournament.min_rating != null || tournament.max_rating != null"
                            >{{ $t('club.tournament.rating', { range: `${tournament.min_rating ?? '…'}–${tournament.max_rating ?? '…'}` }) }}</template
                        ><template v-if="tournament.max_players"> · {{ $t('club.tournament.players', { n: accepted, max: tournament.max_players }) }}</template>
                    </p>
                </div>
                <button v-if="canEdit && !editing" type="button" class="btn ghost sm" data-test="t-edit" @click="editing = true"><Icon name="edit" size="sm" /></button>
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

        <!-- the registered players -->
        <section class="card panel" style="margin-top: 14px">
            <div class="sec-h">
                <h2>{{ $t('owner.tournament.players') }}</h2>
                <button v-if="count('unpaid')" type="button" class="btn ghost sm" data-test="t-remind" @click="remind">
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
                    <b>
                        <span v-if="e.place" class="place">{{ placeName(e.place) }}</span>
                        {{ e.name }}
                    </b>
                    <small
                        ><a :href="`tel:${e.tel}`">{{ e.phone }}</a>{{ e.level != null ? ' · ' + $t('owner.club.level_n', { n: e.level }) : '' }} · {{ e.option }}
                        {{ e.price ? amd(e.price) : $t('club.free') }}</small
                    >
                </div>
                <span v-if="e.status === 'applied'" class="row" style="gap: 6px">
                    <button type="button" class="btn sm" @click="entry(e.id, { status: 'accepted' })">{{ $t('common.accept') }}</button>
                    <button type="button" class="btn ghost sm" @click="entry(e.id, { status: 'declined' })">{{ $t('common.decline') }}</button>
                </span>
                <span v-else-if="e.status === 'declined'" class="pill">{{ $t('owner.club.declined') }}</span>
                <template v-else>
                    <span v-if="e.paid" class="pill green"><Icon name="check" size="sm" />{{ amd(e.paid.amount) }} · {{ $t('owner.club.m_' + e.paid.method) }}</span>
                    <span v-else-if="e.price" class="row" style="gap: 6px">
                        <span class="pill hot">{{ $t('owner.tournament.not_paid') }}</span>
                        <button v-if="has('wallet')" type="button" class="btn sm" @click="entry(e.id, { pay: 'card' })">{{ $t('owner.club.from_card') }}</button>
                        <button type="button" class="btn ghost sm" @click="entry(e.id, { pay: 'cash' })">{{ $t('owner.club.cash') }}</button>
                    </span>
                </template>
            </div>
            <p v-if="!shown.length" class="faint" style="margin: 8px 0 0">{{ $t('owner.club.no_entries') }}</p>
        </section>

        <!-- the draw and the bracket -->
        <section class="card panel" style="margin-top: 14px">
            <div class="sec-h">
                <h2>{{ $t('owner.tournament.bracket') }}</h2>
                <button v-if="canEdit && tournament.status !== 'done'" type="button" :class="['btn', 'sm', { ghost: tournament.drawn }]" :disabled="accepted < 2" data-test="draw" @click="draw">
                    <Icon name="trophy" size="sm" />{{ tournament.drawn ? $t('owner.tournament.redraw') : $t('owner.tournament.draw') }}
                </button>
            </div>
            <template v-if="tournament.bracket">
                <p v-if="canEdit" class="faint" style="font-size: 12.5px; margin: 0 0 10px">{{ $t('owner.tournament.tap_match') }}</p>
                <Bracket :bracket="tournament.bracket" :editable="canEdit" :title="tournament.title" :when="when" :players="accepted" @pick="pick" />
            </template>
            <p v-else class="faint" style="font-size: 13px; margin: 0">{{ $t('owner.tournament.draw_hint') }}</p>
        </section>

        <!-- news to the players, and the chat -->
        <section v-if="canEdit" class="card panel" style="margin-top: 14px">
            <div class="sec-h">
                <h2>{{ $t('owner.tournament.announce') }}</h2>
                <button type="button" class="btn ghost sm" data-test="t-chat" @click="openChat"><Icon name="chat" size="sm" />{{ $t('owner.tournament.open_chat') }}</button>
            </div>
            <form class="news" @submit.prevent="sendNews">
                <div class="seg aud" role="group">
                    <button v-for="a in ['all', 'accepted', 'applied'] as const" :key="a" type="button" :aria-pressed="news.to === a" :data-test="`to-${a}`" @click="news.to = a">
                        {{ $t('owner.tournament.to_' + a) }}
                    </button>
                </div>
                <textarea v-model="news.text" class="input" rows="3" maxlength="1000" :placeholder="$t('owner.tournament.announce_ph')" data-test="t-news" />
                <button class="btn" :disabled="news.processing || !news.text.trim()" data-test="t-send"><Icon name="send" size="sm" />{{ $t('owner.tournament.send') }}</button>
                <small class="faint">{{ news.to === 'applied' ? $t('owner.tournament.announce_hint_applied') : $t('owner.tournament.announce_hint') }}</small>
            </form>
        </section>

        <!-- a match: who won (and the score), or playing now -->
        <OwnerSheet v-if="picked" :title="$t('owner.tournament.who_won')" @close="picked = null">
            <div class="who">
                <button
                    v-for="s in [picked.a, picked.b]"
                    :key="s!.id"
                    type="button"
                    :class="['btn', 'block', { ghost: picked.winner !== s!.id }]"
                    :data-test="`winner-${s!.id}`"
                    @click="setMatch({ winner: s!.id, score: score })"
                >
                    {{ s!.name }}
                </button>
                <input v-model="score" class="input" maxlength="20" :placeholder="$t('owner.tournament.score_ph')" data-test="t-score" />
                <button v-if="!picked.winner" type="button" class="btn ghost block" @click="setMatch({ live: !picked.live })">
                    <i class="ldot" />{{ picked.live ? $t('owner.tournament.live_off') : $t('owner.tournament.live_on') }}
                </button>
            </div>
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
.aud { display: flex; width: 100%; }
.aud button { flex: 1; justify-content: center; white-space: normal; text-align: center; }
.who { display: grid; gap: 10px; }
.ldot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #ff6b6b; margin-right: 6px; }
</style>
