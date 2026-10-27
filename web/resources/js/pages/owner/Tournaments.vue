<script setup lang="ts">
import Bracket, { type BracketData, type Match } from '@/components/club/Bracket.vue';
import Icon from '@/components/Icon.vue';
import OwnerSheet from '@/components/owner/OwnerSheet.vue';
import { useOwner } from '@/composables/useOwner';
import { amd } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Tournaments: open one (title, date, up to 3 prices, who may register: rating range, how many players), put its link
// in the Instagram bio; people register with name, surname, phone, level and a price option. Here: accept / decline
// (they hear about it), take the fee, make the draw (players see who they play), mark the winners on the bracket
// (live for everybody), send news to the players, talk to them in the tournament chat.
interface Opt {
    label: string;
    price: number;
}
interface T {
    id: number;
    title: string;
    date: string;
    time: string;
    description: string | null;
    options: Opt[];
    status: 'open' | 'closed' | 'done';
    link: string;
    entries?: number;
    min_rating: number | null;
    max_rating: number | null;
    max_players: number | null;
    drawn: boolean;
}
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
    paid: { amount: number; method: string } | null;
}
const props = defineProps<{ tournaments: T[]; current: (T & { list: Entry[]; bracket: BracketData | null; chat: number | null }) | null; sport: string }>();
const owner = useOwner();
const canEdit = owner.value.role === 'owner';
const has = (f: string) => (owner.value.features ?? []).includes(f);
const editing = ref<'new' | 'current' | null>(null);
const blank = () => ({
    title: '',
    date: '',
    time: '11:00',
    description: '',
    status: 'open',
    min_rating: '' as number | '',
    max_rating: '' as number | '',
    max_players: '' as number | '',
    options: [{ label: '', price: 0 }, { label: '', price: 0 }, { label: '', price: 0 }] as Opt[],
});
const form = useForm(blank());
function edit(which: 'new' | 'current') {
    form.clearErrors();
    const c = props.current;
    if (which === 'current' && c) {
        const opts = [...c.options.map((o) => ({ ...o }))];
        while (opts.length < 3) opts.push({ label: '', price: 0 });
        Object.assign(form, {
            title: c.title,
            date: c.date,
            time: c.time,
            description: c.description ?? '',
            status: c.status,
            min_rating: c.min_rating ?? '',
            max_rating: c.max_rating ?? '',
            max_players: c.max_players ?? '',
            options: opts,
        });
    } else Object.assign(form, blank());
    editing.value = which;
}
function save() {
    const done = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    if (editing.value === 'new') form.post(route('owner.tournaments.store'), done);
    else if (props.current) form.put(route('owner.tournaments.update', props.current.id), done);
}
const open = (id: number) => router.get(route('owner.tournaments'), { t: id }, { preserveScroll: true, replace: true });
const entry = (id: number, data: Record<string, string | number | null>) => router.patch(route('owner.tournaments.entry', id), data, { preserveScroll: true });
// the draw, the bracket: tap a match → who won, the score, or "playing now"
const draw = () => props.current && router.post(route('owner.tournaments.draw', props.current.id), {}, { preserveScroll: true });
const picked = ref<Match | null>(null);
const score = ref('');
function pick(m: Match) {
    picked.value = m;
    score.value = m.score ?? '';
}
function setMatch(data: Record<string, string | number | boolean | null>) {
    if (!picked.value) return;
    router.patch(route('owner.tournaments.match', picked.value.id), data, { preserveScroll: true, onSuccess: () => (picked.value = null) });
}
// news to the players: everybody registered, or only the accepted ones
const news = useForm({ text: '', to: 'all' as 'all' | 'accepted' });
const sendNews = () => props.current && news.post(route('owner.tournaments.announce', props.current.id), { preserveScroll: true, onSuccess: () => news.reset('text') });
const openChat = () => props.current && router.post(route('owner.tournaments.chat', props.current.id));
const accepted = computed(() => props.current?.list.filter((e) => e.status === 'accepted').length ?? 0);
const copied = ref(false);
async function copy(link: string) {
    try {
        await navigator.clipboard.writeText(link);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1800);
    } catch {
        window.prompt('', link);
    }
}
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.tournaments')">
        <div class="phead">
            <div>
                <div class="kicker">{{ owner.venue.name }}</div>
                <h1>{{ $t('owner.nav.tournaments') }}</h1>
            </div>
            <div class="phead-r">
                <button v-if="canEdit" type="button" class="btn" data-test="new-tournament" @click="edit('new')"><Icon name="plus" size="sm" />{{ $t('owner.club.new_tournament') }}</button>
            </div>
        </div>

        <div v-if="tournaments.length" class="chips" style="margin-bottom: 12px">
            <button v-for="t in tournaments" :key="t.id" type="button" class="chip" :aria-pressed="current?.id === t.id" @click="open(t.id)">
                {{ t.title }} <span class="faint">{{ t.date.slice(8) }}.{{ t.date.slice(5, 7) }} · {{ t.entries }}</span>
            </button>
        </div>

        <section v-if="editing" class="card panel">
            <div class="sec-h"><h2>{{ editing === 'new' ? $t('owner.club.new_tournament') : form.title }}</h2></div>
            <form class="fgrid" @submit.prevent="save">
                <div class="field" style="grid-column: 1 / -1"><label>{{ $t('owner.club.t_title') }}</label><input v-model="form.title" class="input" required maxlength="100" /></div>
                <div class="field"><label>{{ $t('owner.club.date') }}</label><input v-model="form.date" class="input" type="date" required /></div>
                <div class="field"><label>{{ $t('owner.club.start') }}</label><input v-model="form.time" class="input" type="time" required /></div>
                <div class="field" style="grid-column: 1 / -1"><label>{{ $t('owner.club.t_note') }}</label><textarea v-model="form.description" class="input" rows="2" maxlength="1000" /></div>
                <div class="field" style="grid-column: 1 / -1">
                    <label>{{ $t('owner.club.options') }}</label>
                    <div v-for="(o, i) in form.options" :key="i" class="row" style="gap: 8px; margin-bottom: 6px">
                        <input v-model="o.label" class="input" :placeholder="$t('owner.club.option_ph_' + i)" maxlength="40" style="flex: 1" />
                        <span class="money" style="width: 140px"><input v-model.number="o.price" class="input" type="number" min="0" step="500" /><span>֏</span></span>
                    </div>
                </div>
                <!-- who may register: a rating range (empty = any) and how many players -->
                <div class="field">
                    <label>{{ $t('owner.tournament.min_rating') }}</label
                    ><input v-model="form.min_rating" class="input" type="number" min="0" max="4000" :placeholder="$t('owner.tournament.any')" data-test="t-min" />
                </div>
                <div class="field">
                    <label>{{ $t('owner.tournament.max_rating') }}</label
                    ><input v-model="form.max_rating" class="input" type="number" min="0" max="4000" :placeholder="$t('owner.tournament.any')" data-test="t-max" />
                </div>
                <div class="field">
                    <label>{{ $t('owner.tournament.max_players') }}</label
                    ><input v-model="form.max_players" class="input" type="number" min="2" max="256" :placeholder="$t('owner.tournament.any')" data-test="t-players" />
                </div>
                <div v-if="editing === 'current'" class="field">
                    <label>{{ $t('common.status') }}</label>
                    <select v-model="form.status" class="input">
                        <option v-for="s in ['open', 'closed', 'done']" :key="s" :value="s">{{ $t('owner.club.t_status_' + s) }}</option>
                    </select>
                </div>
                <div v-for="(e, k) in form.errors" :key="k" class="err" style="grid-column: 1 / -1">{{ e }}</div>
                <div class="row" style="gap: 8px; grid-column: 1 / -1">
                    <button class="btn" :disabled="form.processing">{{ $t('common.save') }}</button>
                    <button type="button" class="btn ghost" @click="editing = null">{{ $t('common.cancel') }}</button>
                </div>
            </form>
        </section>

        <section v-if="current" class="card panel">
            <div class="sec-h" style="align-items: flex-start">
                <div>
                    <h2>{{ current.title }}</h2>
                    <p class="muted" style="margin: 2px 0 0; font-size: 13.5px">
                        {{ current.date.slice(8) }}.{{ current.date.slice(5, 7) }} · {{ current.time }} · {{ $t('owner.club.t_status_' + current.status) }}
                        · {{ current.options.map((o) => `${o.label} ${amd(o.price)}`).join(' / ') }}
                    </p>
                    <p v-if="current.min_rating != null || current.max_rating != null || current.max_players" class="muted" style="margin: 2px 0 0; font-size: 13px">
                        <template v-if="current.min_rating != null || current.max_rating != null"
                            >{{ $t('club.tournament.rating', { range: `${current.min_rating ?? '…'}–${current.max_rating ?? '…'}` }) }}</template
                        ><template v-if="current.max_players"> · {{ $t('club.tournament.players', { n: accepted, max: current.max_players }) }}</template>
                    </p>
                </div>
                <button v-if="canEdit" type="button" class="btn ghost sm" @click="edit('current')"><Icon name="edit" size="sm" /></button>
            </div>
            <div class="linkbox">
                <span class="mono">{{ current.link }}</span>
                <button type="button" class="btn sm" @click="copy(current.link)"><Icon :name="copied ? 'check' : 'share'" size="sm" />{{ copied ? $t('owner.club.copied') : $t('owner.club.copy_link') }}</button>
            </div>
            <p class="faint" style="font-size: 12.5px; margin: 6px 0 12px">{{ $t('owner.club.link_hint') }}</p>

            <div v-for="e in current.list" :key="e.id" :class="['erow', e.status]">
                <div class="w">
                    <b>{{ e.name }}</b>
                    <small><a :href="`tel:${e.tel}`">{{ e.phone }}</a>{{ e.level != null ? ' · ' + $t('owner.club.level_n', { n: e.level }) : '' }} · {{ e.option }} {{ amd(e.price) }}</small>
                </div>
                <span v-if="e.status === 'applied'" class="row" style="gap: 6px">
                    <button type="button" class="btn sm" @click="entry(e.id, { status: 'accepted' })">{{ $t('common.accept') }}</button>
                    <button type="button" class="btn ghost sm" @click="entry(e.id, { status: 'declined' })">{{ $t('common.decline') }}</button>
                </span>
                <span v-else-if="e.status === 'declined'" class="pill">{{ $t('owner.club.declined') }}</span>
                <template v-else>
                    <span v-if="e.paid" class="pill green">{{ amd(e.paid.amount) }} · {{ $t('owner.club.m_' + e.paid.method) }}</span>
                    <span v-else-if="e.price" class="row" style="gap: 6px">
                        <button v-if="has('wallet')" type="button" class="btn sm" @click="entry(e.id, { pay: 'card' })">{{ $t('owner.club.from_card') }}</button>
                        <button type="button" class="btn ghost sm" @click="entry(e.id, { pay: 'cash' })">{{ $t('owner.club.cash') }}</button>
                    </span>
                    <input
                        class="input place"
                        type="number"
                        min="0"
                        :value="e.place ?? ''"
                        :placeholder="$t('owner.club.place_ph')"
                        :aria-label="$t('owner.club.place_ph')"
                        @change="entry(e.id, { place: Number(($event.target as HTMLInputElement).value) || null })"
                    />
                </template>
            </div>
            <p v-if="!current.list.length" class="faint" style="margin: 4px 0">{{ $t('owner.club.no_entries') }}</p>
        </section>

        <!-- the draw and the bracket -->
        <section v-if="current" class="card panel" style="margin-top: 14px">
            <div class="sec-h">
                <h2>{{ $t('owner.tournament.bracket') }}</h2>
                <button v-if="canEdit && current.status !== 'done'" type="button" :class="['btn', 'sm', { ghost: current.drawn }]" :disabled="accepted < 2" data-test="draw" @click="draw">
                    <Icon name="trophy" size="sm" />{{ current.drawn ? $t('owner.tournament.redraw') : $t('owner.tournament.draw') }}
                </button>
            </div>
            <template v-if="current.bracket">
                <p class="faint" style="font-size: 12.5px; margin: 0 0 10px">{{ $t('owner.tournament.tap_match') }}</p>
                <Bracket :bracket="current.bracket" :editable="canEdit" @pick="pick" />
            </template>
            <p v-else class="faint" style="font-size: 13px; margin: 0">{{ $t('owner.tournament.draw_hint') }}</p>
        </section>

        <!-- news to the players (app + Telegram, and in the chat), and the chat itself -->
        <section v-if="current && canEdit" class="card panel" style="margin-top: 14px">
            <div class="sec-h">
                <h2>{{ $t('owner.tournament.announce') }}</h2>
                <button type="button" class="btn ghost sm" data-test="t-chat" @click="openChat"><Icon name="chat" size="sm" />{{ $t('owner.tournament.open_chat') }}</button>
            </div>
            <form class="news" @submit.prevent="sendNews">
                <textarea v-model="news.text" class="input" rows="3" maxlength="1000" :placeholder="$t('owner.tournament.announce_ph')" data-test="t-news" />
                <div class="row" style="gap: 8px; flex-wrap: wrap">
                    <div class="seg" role="group">
                        <button type="button" :aria-pressed="news.to === 'all'" @click="news.to = 'all'">{{ $t('owner.tournament.to_all') }}</button>
                        <button type="button" :aria-pressed="news.to === 'accepted'" @click="news.to = 'accepted'">{{ $t('owner.tournament.to_accepted') }}</button>
                    </div>
                    <button class="btn" :disabled="news.processing || !news.text.trim()" data-test="t-send"><Icon name="send" size="sm" />{{ $t('owner.tournament.send') }}</button>
                </div>
                <small class="faint">{{ $t('owner.tournament.announce_hint') }}</small>
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
        <p v-else-if="!editing" class="faint">{{ $t('owner.club.no_tournaments') }}</p>
    </OwnerLayout>
</template>

<style scoped>
.linkbox { display: flex; gap: 10px; align-items: center; background: var(--surface-2); border-radius: 12px; padding: 8px 8px 8px 12px; margin-top: 12px; }
.linkbox .mono { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 13px; }
.erow { display: flex; gap: 10px; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--line); flex-wrap: wrap; }
.erow.declined { opacity: .5; }
.erow .w { flex: 1; min-width: 180px; display: flex; flex-direction: column; }
.erow small { color: var(--muted); font-size: 12.5px; }
.erow .place { width: 74px; padding: 6px 8px; }
.news { display: grid; gap: 8px; min-width: 0; }
.news textarea { width: 100%; box-sizing: border-box; resize: vertical; }
.news small { font-size: 12px; }
.who { display: grid; gap: 10px; }
.ldot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #ff6b6b; margin-right: 6px; }
</style>
