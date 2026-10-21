<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import GameCard from '@/components/games/GameCard.vue';
import { wdName } from '@/composables/usePlayer';
import { useShared } from '@/composables/useShared';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import type { GameCard as Card } from '@/types/games';
import { Head, Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

// Games: open games everyone can join and "My games" (invites, forming, upcoming, past).
// Filters: size chips (All / 3×3–6×6 / 7×7–11×11) + a "Filters (n)" sheet with day, district and time of day.
type Filters = { size: string | null; district: string | null; day: string | null; time: string | null };
const props = defineProps<{
    tab: 'open' | 'mine';
    filters: Filters;
    days: { date: string; k: number; wd: number; day: number; weekend: boolean }[];
    districts: string[];
    games: Card[];
    mine: { invites: Card[]; forming: Card[]; active: Card[]; past: Card[] } | null;
    canCreate: boolean;
}>();

const { user } = useShared();
const tab = ref<'open' | 'mine'>(props.tab);
const loading = ref(false);
const sizeLabel = (s: string) => (s === 'small' ? '3×3–6×6' : s === 'big' ? '7×7–11×11' : `${s}×${s}`);
// an exact size from an old link (?size=5) still shows as a chip
const sizes = computed(() => ['small', 'big', ...(props.filters.size && !['small', 'big'].includes(props.filters.size) ? [props.filters.size] : [])]);
const myCount = computed(() => (props.mine ? props.mine.invites.length + props.mine.forming.length + props.mine.active.length : 0));
const moreCount = computed(() => [props.filters.day, props.filters.district, props.filters.time].filter(Boolean).length);
const anyFilter = computed(() => Object.values(props.filters).some((v) => v));
const TIMES = ['morning', 'day', 'evening'] as const;

function setTab(t: 'open' | 'mine') {
    tab.value = t;
    const u = new URL(window.location.href);
    if (t === 'mine') u.searchParams.set('tab', 'mine');
    else u.searchParams.delete('tab');
    window.history.replaceState(window.history.state, '', u.toString());
}
function go(patch: Partial<Filters>) {
    const next = { ...props.filters, ...patch };
    const q: Record<string, string> = {};
    for (const [k, v] of Object.entries(next)) if (v) q[k] = v;
    router.get(route('games.index'), q, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['games', 'filters'],
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
}

// ---------- the "Filters" sheet: a draft applied with "Show games" ----------
const sheet = ref(false);
const draft = ref<Pick<Filters, 'day' | 'district' | 'time'>>({ day: null, district: null, time: null });
function openSheet() {
    draft.value = { day: props.filters.day, district: props.filters.district, time: props.filters.time };
    sheet.value = true;
}
function apply() {
    sheet.value = false;
    go({ ...draft.value });
}
const dayText = (d: string) => {
    const x = props.days.find((y) => y.date === d);
    return !x ? d : x.k === 0 ? trans('player.today') : `${wdName(x.wd)} ${x.day}`;
};
function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') sheet.value = false;
}
onMounted(() => document.addEventListener('keydown', onKey));
onBeforeUnmount(() => document.removeEventListener('keydown', onKey));

const post = (name: string, id: number, data: Record<string, string | number | boolean> = {}) => router.post(route(name, id), data, { preserveScroll: true });
const isIn = (g: Card) => g.me === 'accepted' || g.me === 'joined';
const showRule = ref(false);
</script>

<template>
    <PlayerLayout>
        <Head :title="$t('games.title')" />

        <section class="ghead">
            <div style="min-width: 0">
                <h1 class="page-title">{{ $t('games.index.h1') }}</h1>
                <p class="muted lead">{{ $t('games.index.lead') }}</p>
            </div>
            <Link v-if="user" class="btn" :href="route('games.create')"><Icon name="plus" />{{ $t('games.index.create') }}</Link>
            <Link v-else class="btn" :href="route('login')"><Icon name="user" />{{ $t('games.index.loginCreate') }}</Link>
        </section>

        <div class="tabs" role="tablist">
            <button role="tab" :aria-selected="tab === 'open'" @click="setTab('open')">{{ $t('games.index.tabOpen') }}<span class="n">{{ games.length }}</span></button>
            <button role="tab" :aria-selected="tab === 'mine'" @click="setTab('mine')">
                {{ $t('games.index.tabMine') }}<span v-if="mine?.invites.length" class="badge-dot warn" :aria-label="$t('games.mine.invites')">{{ mine.invites.length }}</span>
                <span v-else-if="mine" class="n">{{ myCount }}</span>
            </button>
        </div>

        <!-- open games -->
        <section v-show="tab === 'open'">
            <div class="fbar">
                <div class="chips" role="group" :aria-label="$t('games.index.size')">
                    <button type="button" class="chip" :aria-pressed="!filters.size" @click="go({ size: null })">{{ $t('games.index.all') }}</button>
                    <button v-for="s in sizes" :key="s" type="button" class="chip" :aria-pressed="filters.size === s" @click="go({ size: filters.size === s ? null : s })">
                        <i :class="['field-dot', s === 'big' || Number(s) >= 7 ? 'big' : 'small']" />{{ sizeLabel(s) }}
                    </button>
                </div>
                <button type="button" :class="['chip', 'fbtn', { on: moreCount }]" :aria-haspopup="'dialog'" @click="openSheet">
                    <Icon name="filter" size="sm" />{{ $t('games.index.filters') }}<template v-if="moreCount"> ({{ moreCount }})</template>
                </button>
            </div>
            <div v-if="moreCount" class="active-f">
                <button v-if="filters.day" type="button" class="pill" @click="go({ day: null })">{{ dayText(filters.day) }} <Icon name="x" size="sm" /></button>
                <button v-if="filters.district" type="button" class="pill" @click="go({ district: null })">{{ $t(`player.district.${filters.district}`) }} <Icon name="x" size="sm" /></button>
                <button v-if="filters.time" type="button" class="pill" @click="go({ time: null })">{{ $t(`player.home.${filters.time}`) }} <Icon name="x" size="sm" /></button>
            </div>

            <div :class="['grid c3', { dim: loading }]">
                <GameCard v-for="g in games" :key="g.id" :game="g" :invite="g.me === 'invited'">
                    <template v-if="g.me === 'invited'">
                        <button type="button" class="btn outline sm" @click="post('games.respond', g.id, { accept: false })">{{ $t('games.decline') }}</button>
                        <button type="button" class="btn sm" @click="post('games.respond', g.id, { accept: true })">{{ $t('games.accept') }}</button>
                    </template>
                    <template v-else-if="!isIn(g) && g.status === 'open' && !g.mine">
                        <button v-if="user" type="button" class="btn sm" @click="post('games.join', g.id)"><Icon name="plus" size="sm" />{{ $t('games.join') }}</button>
                        <Link v-else class="btn sm" :href="route('login')">{{ $t('games.join') }}</Link>
                    </template>
                    <Link v-else class="btn outline sm" :href="route('games.show', g.id)">{{ $t('games.open') }}</Link>
                </GameCard>
            </div>
            <div v-if="!games.length" class="empty-state">
                <span class="es-ico"><Icon name="ball" /></span>
                <h3>{{ anyFilter ? $t('games.index.emptyFilteredTitle') : $t('games.index.emptyTitle') }}</h3>
                <p>{{ anyFilter ? $t('games.index.emptyFiltered') : $t('games.index.empty') }}</p>
                <button v-if="anyFilter" type="button" class="btn outline sm" @click="go({ size: null, district: null, day: null, time: null })">{{ $t('games.index.reset') }}</button>
                <Link v-else-if="user" class="btn sm" :href="route('games.create')"><Icon name="plus" size="sm" />{{ $t('games.index.createOwn') }}</Link>
            </div>
        </section>

        <!-- my games -->
        <section v-show="tab === 'mine'">
            <div v-if="!mine" class="empty-state">
                <span class="es-ico"><Icon name="user" /></span>
                <p>{{ $t('games.mine.login') }}</p>
                <Link class="btn sm" :href="route('login')">{{ $t('nav.login') }}</Link>
            </div>
            <template v-else>
                <button type="button" class="howbtn" :aria-expanded="showRule" @click="showRule = !showRule">
                    <span class="i" aria-hidden="true">i</span>{{ $t('games.rule.title') }}
                </button>
                <div v-if="showRule" class="rule">
                    <p>{{ $t('games.rule.text') }}</p>
                    <div class="ex">
                        <span v-for="[s, n] in [[3, 2], [5, 3], [7, 5], [11, 7]]" :key="s" class="pill">{{ s }}×{{ s }} → {{ $t('games.rule.ex', { n: String(n), total: String(s * 2) }) }}</span>
                    </div>
                </div>

                <div v-if="mine.invites.length" class="grp">
                    <h2>{{ $t('games.mine.invites') }} <span class="cnt">{{ mine.invites.length }}</span></h2>
                    <div class="grid c3">
                        <GameCard v-for="g in mine.invites" :key="g.id" :game="g" invite>
                            <button type="button" class="btn outline sm" @click="post('games.respond', g.id, { accept: false })">{{ $t('games.decline') }}</button>
                            <button type="button" class="btn sm" @click="post('games.respond', g.id, { accept: true })"><Icon name="check" size="sm" />{{ $t('games.accept') }}</button>
                        </GameCard>
                    </div>
                </div>
                <div v-for="k in ['forming', 'active', 'past'] as const" :key="k">
                    <div v-if="mine[k].length" class="grp">
                        <h2>{{ $t(`games.mine.${k}`) }} <span class="cnt">{{ mine[k].length }}</span></h2>
                        <div class="grid c3">
                            <GameCard v-for="g in mine[k]" :key="g.id" :game="g">
                                <Link class="btn outline sm" :href="route('games.show', g.id)">{{ $t('games.open') }}</Link>
                            </GameCard>
                        </div>
                    </div>
                </div>
                <div v-if="!myCount && !mine.past.length" class="empty-state">
                    <span class="es-ico"><Icon name="users" /></span>
                    <p>{{ $t('games.mine.empty') }}</p>
                    <Link class="btn sm" :href="route('games.create')"><Icon name="plus" size="sm" />{{ $t('games.index.create') }}</Link>
                </div>
            </template>
        </section>

        <!-- filters sheet -->
        <Teleport to="body">
            <div v-if="sheet" class="scrim" @click.self="sheet = false">
                <div class="sheet fsheet" role="dialog" aria-modal="true" aria-labelledby="fst">
                    <div class="sheet-head">
                        <h3 id="fst">{{ $t('games.index.filters') }}</h3>
                        <button type="button" class="x" :aria-label="$t('player.close')" @click="sheet = false"><Icon name="x" /></button>
                    </div>
                    <div class="field">
                        <label>{{ $t('games.index.day') }}</label>
                        <div class="days" role="group">
                            <button type="button" class="day any" :aria-pressed="!draft.day" @click="draft.day = null"><b>{{ $t('games.index.anyDay') }}</b></button>
                            <button v-for="d in days" :key="d.date" type="button" :class="['day', { we: d.weekend }]" :aria-pressed="draft.day === d.date" @click="draft.day = d.date">
                                <small>{{ d.k === 0 ? $t('player.today') : wdName(d.wd) }}</small><b>{{ d.day }}</b>
                            </button>
                        </div>
                    </div>
                    <div class="field">
                        <label>{{ $t('games.district') }}</label>
                        <div class="chips wrap" role="group">
                            <button type="button" class="chip" :aria-pressed="!draft.district" @click="draft.district = null">{{ $t('player.home.anyDistrict') }}</button>
                            <button v-for="d in districts" :key="d" type="button" class="chip" :aria-pressed="draft.district === d" @click="draft.district = d">{{ $t(`player.district.${d}`) }}</button>
                        </div>
                    </div>
                    <div class="field">
                        <label>{{ $t('games.index.time') }}</label>
                        <div class="chips wrap" role="group">
                            <button type="button" class="chip" :aria-pressed="!draft.time" @click="draft.time = null">{{ $t('games.index.anyTime') }}</button>
                            <button v-for="t in TIMES" :key="t" type="button" class="chip" :aria-pressed="draft.time === t" @click="draft.time = t">{{ $t(`player.home.${t}`) }}</button>
                        </div>
                    </div>
                    <div class="sfoot">
                        <button type="button" class="btn link" @click="draft = { day: null, district: null, time: null }">{{ $t('games.index.clear') }}</button>
                        <button type="button" class="btn" @click="apply">{{ $t('games.index.showGames') }}</button>
                    </div>
                </div>
            </div>
        </Teleport>
    </PlayerLayout>
</template>

<style scoped>
.ghead { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-top: 28px; flex-wrap: wrap; }
.ghead .lead { max-width: 620px; margin: 8px 0 0; font-size: 15px; }
@media (max-width: 600px) {
    .ghead { margin-top: 18px; gap: 12px; }
    .ghead .lead { display: none; }
    .ghead .btn { width: 100%; }
}
.tabs { margin: 20px 0 14px; }
.tabs .n { font-size: 11.5px; background: var(--surface-3); color: var(--muted); border-radius: 99px; padding: 1px 7px; }
.tabs button[aria-selected="true"] .n { background: var(--surface-3); color: var(--ink); }
.fbar { display: flex; gap: 8px; align-items: center; margin-bottom: 10px; min-width: 0; }
.fbar .chips { flex: 1; min-width: 0; }
.chip .field-dot { width: 8px; height: 8px; }
.fbtn { flex: none; }
@media (max-width: 600px) {
    .fbar { gap: 6px; }
    .fbar .chip { padding: 7px 11px; font-size: 13px; gap: 5px; }
    .fbar .chips { gap: 6px; }
}
.fbtn.on { border-color: var(--accent); color: var(--accent); }
.active-f { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
.active-f .pill { border: 0; cursor: pointer; font-size: 12.5px; padding: 4px 10px; color: var(--ink); }
.grid { margin-top: 6px; }
.grid.dim { opacity: .55; transition: opacity .15s; }
.howbtn { display: inline-flex; align-items: center; gap: 8px; background: none; border: 0; color: var(--muted); font-weight: 700; font-size: 13.5px; padding: 4px 0; }
.howbtn:hover { color: var(--ink); }
.howbtn .i { width: 18px; height: 18px; border-radius: 50%; border: 1.5px solid currentColor; display: grid; place-items: center; font-size: 11px; font-weight: 800; font-style: italic; font-family: Georgia, serif; }
.rule { margin: 8px 0 4px; padding: 14px 16px; border-radius: var(--r-md); background: var(--surface); border: 1px solid var(--line); }
.rule p { margin: 0 0 10px; color: var(--muted); font-size: 14px; }
.rule .ex { display: flex; gap: 6px; flex-wrap: wrap; }
.grp { margin-top: 22px; }
.grp h2 { font-family: var(--body); font-size: 17px; font-weight: 800; letter-spacing: 0; margin: 0 0 12px; display: flex; gap: 8px; align-items: center; }
.grp .cnt { font-size: 12px; background: var(--surface-3); color: var(--muted); border-radius: 99px; padding: 2px 8px; }
.empty-state { margin-top: 6px; }
.fsheet { display: flex; flex-direction: column; gap: 18px; width: min(520px, 100%); }
.fsheet .sheet-head { margin-bottom: 0; }
.fsheet .days { max-width: 100%; }
.chips.wrap { flex-wrap: wrap; overflow: visible; }
.day.we:not([aria-pressed="true"]) small { color: var(--st-pending); }
.sfoot { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding-top: 4px; }
.sfoot .btn:last-child { flex: 1; max-width: 260px; }
</style>
