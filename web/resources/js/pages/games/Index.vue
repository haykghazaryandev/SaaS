<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import GameCard from '@/components/games/GameCard.vue';
import { wdName } from '@/composables/usePlayer';
import { useShared } from '@/composables/useShared';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import type { GameCard as Card } from '@/types/games';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Games: open games everyone can join (with filters) and "My games" (invites, forming, upcoming, past).
type Filters = { size: string | null; level: string | null; district: string | null; day: string | null; time: string | null };
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
const SIZES = ['small', 'big', '3', '4', '5', '6', '7', '8', '9', '10', '11'];
const sizeLabel = (s: string) => (s === 'small' ? '3×3–6×6' : s === 'big' ? '7×7–11×11' : `${s}×${s}`);
const myCount = computed(() => (props.mine ? props.mine.invites.length + props.mine.forming.length + props.mine.active.length : 0));
const anyFilter = computed(() => Object.values(props.filters).some((v) => v));

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
const post = (name: string, id: number, data: Record<string, unknown> = {}) => router.post(route(name, id), data, { preserveScroll: true });
const isIn = (g: Card) => g.me === 'accepted' || g.me === 'joined';
</script>

<template>
    <PlayerLayout>
        <Head :title="$t('games.title')" />

        <section class="ghead">
            <div style="min-width: 0">
                <span class="kicker">{{ $t('games.index.kick') }}</span>
                <h1>{{ $t('games.index.h1') }}</h1>
                <p class="muted lead">{{ $t('games.index.lead') }}</p>
            </div>
            <Link v-if="user" class="btn" :href="route('games.create')"><Icon name="plus" />{{ $t('games.index.create') }}</Link>
            <Link v-else class="btn" :href="route('login')"><Icon name="user" />{{ $t('games.index.loginCreate') }}</Link>
        </section>

        <div class="tabs" role="tablist">
            <button role="tab" :aria-selected="tab === 'open'" @click="setTab('open')"><Icon name="ball" size="sm" />{{ $t('games.index.tabOpen') }}<span class="n">{{ games.length }}</span></button>
            <button role="tab" :aria-selected="tab === 'mine'" @click="setTab('mine')">
                <Icon name="users" size="sm" />{{ $t('games.index.tabMine') }}<span v-if="mine" class="n">{{ myCount }}</span>
                <span v-if="mine?.invites.length" class="dot" :aria-label="$t('games.mine.invites')" />
            </button>
        </div>

        <!-- open games -->
        <section v-show="tab === 'open'">
            <div class="filters">
                <div class="chips" role="group" :aria-label="$t('games.index.size')">
                    <button type="button" class="chip" :aria-pressed="!filters.size" @click="go({ size: null })">{{ $t('games.index.anySize') }}</button>
                    <button v-for="s in SIZES" :key="s" type="button" class="chip" :aria-pressed="filters.size === s" @click="go({ size: filters.size === s ? null : s })">
                        {{ sizeLabel(s) }}
                    </button>
                </div>
                <div class="days" role="group" :aria-label="$t('games.index.day')">
                    <button type="button" class="day anyday" :aria-pressed="!filters.day" @click="go({ day: null })">
                        <small>{{ $t('games.index.anyDay') }}</small><b>∞</b>
                    </button>
                    <button v-for="d in days" :key="d.date" type="button" :class="['day', { we: d.weekend }]" :aria-pressed="filters.day === d.date" @click="go({ day: d.date })">
                        <small>{{ d.k === 0 ? $t('player.today') : wdName(d.wd) }}</small><b>{{ d.day }}</b>
                    </button>
                </div>
                <div class="selects">
                    <select class="input" :value="filters.level ?? ''" :aria-label="$t('games.level')" @change="go({ level: ($event.target as HTMLSelectElement).value || null })">
                        <option value="">{{ $t('games.anyLevel') }}</option>
                        <option v-for="l in ['beginner', 'mid', 'pro']" :key="l" :value="l">{{ $t(`level.${l}`) }}</option>
                    </select>
                    <select class="input" :value="filters.district ?? ''" :aria-label="$t('games.district')" @change="go({ district: ($event.target as HTMLSelectElement).value || null })">
                        <option value="">{{ $t('player.home.anyDistrict') }}</option>
                        <option v-for="d in districts" :key="d" :value="d">{{ $t(`player.district.${d}`) }}</option>
                    </select>
                    <select class="input" :value="filters.time ?? ''" :aria-label="$t('games.index.time')" @change="go({ time: ($event.target as HTMLSelectElement).value || null })">
                        <option value="">{{ $t('player.home.anyTime') }}</option>
                        <option value="morning">{{ $t('player.home.morning') }}</option>
                        <option value="day">{{ $t('player.home.day') }}</option>
                        <option value="evening">{{ $t('player.home.evening') }}</option>
                    </select>
                    <button v-if="anyFilter" type="button" class="btn ghost sm" @click="go({ size: null, level: null, district: null, day: null, time: null })">
                        <Icon name="x" size="sm" />{{ $t('games.index.reset') }}
                    </button>
                </div>
            </div>

            <div :class="['grid c3', { dim: loading }]">
                <GameCard v-for="g in games" :key="g.id" :game="g">
                    <template v-if="g.me === 'invited'">
                        <button type="button" class="btn sm" @click="post('games.respond', g.id, { accept: true })">{{ $t('games.accept') }}</button>
                    </template>
                    <template v-else-if="!isIn(g) && g.status === 'open' && !g.mine">
                        <button v-if="user" type="button" class="btn sm" @click="post('games.join', g.id)"><Icon name="plus" size="sm" />{{ $t('games.join') }}</button>
                        <Link v-else class="btn sm" :href="route('login')">{{ $t('games.join') }}</Link>
                    </template>
                    <Link v-else class="btn ghost sm" :href="route('games.show', g.id)">{{ $t('games.open') }}<Icon name="arrow" size="sm" /></Link>
                </GameCard>
            </div>
            <div v-if="!games.length" class="empty">
                <p style="margin: 0 0 14px">{{ anyFilter ? $t('games.index.emptyFiltered') : $t('games.index.empty') }}</p>
                <Link v-if="user" class="btn sm" :href="route('games.create')"><Icon name="plus" size="sm" />{{ $t('games.index.createOwn') }}</Link>
            </div>
        </section>

        <!-- my games -->
        <section v-show="tab === 'mine'">
            <div v-if="!mine" class="empty">
                <p style="margin: 0 0 14px">{{ $t('games.mine.login') }}</p>
                <Link class="btn sm" :href="route('login')"><Icon name="user" size="sm" />{{ $t('nav.login') }}</Link>
            </div>
            <template v-else>
                <div class="rule card">
                    <div class="ri"><Icon name="users" /></div>
                    <div>
                        <b>{{ $t('games.rule.title') }}</b>
                        <p>{{ $t('games.rule.text') }}</p>
                        <div class="ex">
                            <span v-for="[s, n] in [[3, 2], [5, 3], [7, 5], [11, 7]]" :key="s" class="pill">{{ s }}×{{ s }} → {{ $t('games.rule.ex', { n: String(n), total: String(s * 2) }) }}</span>
                        </div>
                    </div>
                </div>

                <div v-if="mine.invites.length" class="grp">
                    <h2>{{ $t('games.mine.invites') }} <span class="cnt">{{ mine.invites.length }}</span></h2>
                    <div class="grid c3">
                        <GameCard v-for="g in mine.invites" :key="g.id" :game="g">
                            <button type="button" class="btn ghost sm" @click="post('games.respond', g.id, { accept: false })">{{ $t('games.decline') }}</button>
                            <button type="button" class="btn sm" @click="post('games.respond', g.id, { accept: true })"><Icon name="check" size="sm" />{{ $t('games.accept') }}</button>
                        </GameCard>
                    </div>
                </div>
                <div v-if="mine.forming.length" class="grp">
                    <h2>{{ $t('games.mine.forming') }} <span class="cnt">{{ mine.forming.length }}</span></h2>
                    <div class="grid c3">
                        <GameCard v-for="g in mine.forming" :key="g.id" :game="g">
                            <Link class="btn ghost sm" :href="route('games.show', g.id)">{{ $t('games.open') }}<Icon name="arrow" size="sm" /></Link>
                        </GameCard>
                    </div>
                </div>
                <div v-if="mine.active.length" class="grp">
                    <h2>{{ $t('games.mine.active') }} <span class="cnt">{{ mine.active.length }}</span></h2>
                    <div class="grid c3">
                        <GameCard v-for="g in mine.active" :key="g.id" :game="g">
                            <Link class="btn ghost sm" :href="route('games.show', g.id)">{{ $t('games.open') }}<Icon name="arrow" size="sm" /></Link>
                        </GameCard>
                    </div>
                </div>
                <div v-if="mine.past.length" class="grp">
                    <h2>{{ $t('games.mine.past') }} <span class="cnt">{{ mine.past.length }}</span></h2>
                    <div class="grid c3">
                        <GameCard v-for="g in mine.past" :key="g.id" :game="g">
                            <Link class="btn ghost sm" :href="route('games.show', g.id)">{{ $t('games.open') }}<Icon name="arrow" size="sm" /></Link>
                        </GameCard>
                    </div>
                </div>
                <div v-if="!myCount && !mine.past.length" class="empty">
                    <p style="margin: 0 0 14px">{{ $t('games.mine.empty') }}</p>
                    <Link class="btn sm" :href="route('games.create')"><Icon name="plus" size="sm" />{{ $t('games.index.create') }}</Link>
                </div>
            </template>
        </section>
    </PlayerLayout>
</template>

<style scoped>
.ghead { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; margin-top: 30px; flex-wrap: wrap; }
.ghead h1 { font-size: clamp(28px, 4.4vw, 46px); margin-top: 6px; }
.ghead .lead { max-width: 620px; margin: 10px 0 0; font-size: 15.5px; }
.tabs { margin: 24px 0 18px; }
.tabs .n { font-size: 11.5px; background: var(--surface-3); color: var(--muted); border-radius: 99px; padding: 1px 7px; }
.tabs button[aria-selected="true"] .n { background: var(--accent); color: var(--on-accent); }
.tabs .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--hot); }
.filters { display: flex; flex-direction: column; gap: 10px; margin-bottom: 18px; min-width: 0; }
.filters .chips, .filters .days { max-width: 100%; }
.day.we:not([aria-pressed="true"]) small { color: var(--warn); }
.anyday b { font-size: 16px; }
.selects { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.selects .input { width: auto; min-width: 0; flex: 0 1 220px; padding-top: 9px; padding-bottom: 9px; }
@media (max-width: 600px) { .selects .input { flex: 1 1 140px; } }
.grid.dim { opacity: .55; transition: opacity .15s; }
.rule { display: flex; gap: 14px; padding: 16px 18px; margin-bottom: 8px; border-color: rgba(255, 181, 71, 0.22); }
.rule .ri { width: 40px; height: 40px; flex: none; border-radius: 12px; display: grid; place-items: center; background: var(--warn-soft); color: var(--warn); }
.rule p { margin: 4px 0 10px; color: var(--muted); font-size: 14px; }
.rule .ex { display: flex; gap: 6px; flex-wrap: wrap; }
.grp { margin-top: 26px; }
.grp h2 { font-size: 19px; margin: 0 0 12px; display: flex; gap: 8px; align-items: center; }
.grp .cnt { font-family: var(--mono); font-size: 12px; background: var(--surface-3); color: var(--muted); border-radius: 99px; padding: 2px 8px; }
</style>
