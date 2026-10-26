<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import CourtSvg from '@/components/player/CourtSvg.vue';
import { amd, hhmm } from '@/composables/useShared';
import { SIZE_COLOR, courtShort, dateLong, nextLabel, pitchColor, wdName } from '@/composables/usePlayer';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import { useSportTheme } from '@/lib/sport';
import GameCard from '@/components/games/GameCard.vue';
import type { GameCard as GameCardT } from '@/types/games';
import type { DayInfo, VenueCard } from '@/types/player';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

// Home: first "What do you play?" (⚽ Football, 🏓 Ping-pong … only the sports the admin shows).
// Then that sport's page: Book (places with their next free times; a time opens the place with it chosen),
// its tournaments, and open games. No search box on the way in: the sport is the first choice.
interface Filters {
    sport: string | null;
    date?: string;
    time?: string | null;
    size?: 'small' | 'big' | null;
    district?: string | null;
}
interface SportTab {
    key: string;
    icon: string;
    places: number;
}
const props = defineProps<{
    pick: boolean;
    sports: SportTab[];
    filters: Filters;
    days?: DayInfo[];
    districts?: string[];
    venues?: VenueCard[];
    tournaments?: { code: string; title: string; venue: string; when: string; from: number }[];
    openGames?: number | null;
    games?: GameCardT[];
    tab?: 'book' | 'tournaments' | 'games';
}>();
// the sport page's sections: fixed tabs under the title; one shows below (the tab is kept in the address)
const tab = ref<'book' | 'tournaments' | 'games'>(props.tab ?? 'book');
function pickTab(t: 'book' | 'tournaments' | 'games') {
    tab.value = t;
    const u = new URL(window.location.href);
    if (t === 'book') u.searchParams.delete('tab');
    else u.searchParams.set('tab', t);
    window.history.replaceState(window.history.state, '', u.toString());
}

const sport = computed(() => props.filters.sport || props.sports[0]?.key || 'football');
const isFootball = computed(() => sport.value === 'football');
useSportTheme(() => (props.pick ? null : sport.value));
const f = reactive({ district: props.filters.district ?? '', time: props.filters.time ?? '' });
const loading = ref(false);

function go(change: Partial<Filters>) {
    const next = { ...props.filters, ...change };
    const q: Record<string, string> = {};
    if (next.sport) q.sport = next.sport;
    if (next.date && next.date !== props.days?.[0]?.date) q.date = next.date;
    if (next.time) q.time = next.time;
    if (next.size) q.size = next.size;
    if (next.district) q.district = next.district;
    router.get(route('home'), q, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        only: change.sport ? undefined : ['filters', 'venues'],
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
}
const sportUrl = (key: string) => route('home', { sport: key });

type CardField = VenueCard['fields'][number];
const placeUrl = (v: VenueCard, fl?: CardField, start?: number, date?: string) => {
    const d = date ?? props.filters.date;
    return route('places.show', {
        venue: v.slug,
        date: d !== props.days?.[0]?.date ? d : undefined,
        field: fl?.id,
        minutes: fl && start !== undefined ? fl.defaultDuration : undefined,
        size: !fl && props.filters.size ? props.filters.size : undefined,
        start: start !== undefined ? hhmm(start) : undefined,
    });
};
const isToday = () => props.filters.date === props.days?.[0]?.date;
const big = (v: VenueCard) => v.allFields.find((x) => x.size === 'big');
const small = (v: VenueCard) => v.allFields.find((x) => x.size === 'small');
const startTimes = Array.from({ length: 16 }, (_, i) => `${String(8 + i).padStart(2, '0')}:00`);
const tx = (k: string) => (isFootball.value ? `player.home.${k}` : `player.home.${sport.value}.${k}`);
const whenOf = (iso: string) => `${dateLong(iso.slice(0, 10))} · ${iso.slice(11, 16)}`;
</script>

<template>
    <PlayerLayout>
        <Head :title="$t('player.home.pick_title')" />

        <!-- 1. what do you play? -->
        <section v-if="pick" class="pick">
            <h1>{{ $t('player.home.pick_title') }}</h1>
            <p class="muted">{{ $t('player.home.pick_sub') }}</p>
            <div class="ptiles">
                <Link v-for="sp in sports" :key="sp.key" :href="sportUrl(sp.key)" :class="['ptile', sp.key]" :data-test="'sport-' + sp.key">
                    <div class="art"><CourtSvg :size="sp.key === 'pingpong' ? 'table' : 'big'" /></div>
                    <span class="em">{{ sp.icon }}</span>
                    <span class="nm"
                        ><b>{{ $t(`common.sports.${sp.key}`) }}</b><small>{{ $t('player.home.places_count', { n: String(sp.places) }) }}</small></span
                    >
                    <Icon name="arrow" class="go" />
                </Link>
            </div>
        </section>

        <!-- 2. the sport's page: book, tournaments, games -->
        <template v-else>
            <div class="shead">
                <!-- another sport: back to "What do you play?" -->
                <Link v-if="sports.length > 1" class="allsports" :href="route('home')"><Icon name="back" size="sm" />{{ $t('player.home.all_sports') }}</Link>
                <h1>{{ $t(tx('title_a')) }} <em>{{ $t(tx('title_b')) }}</em></h1>
            </div>

            <!-- fixed tabs: book · tournaments · games -->
            <nav class="stabs" role="tablist">
                <button type="button" role="tab" :aria-selected="tab === 'book'" data-test="tab-book" @click="pickTab('book')">
                    <Icon name="calendar" size="sm" />{{ $t('player.home.tab_book') }}
                </button>
                <button type="button" role="tab" :aria-selected="tab === 'tournaments'" data-test="tab-tournaments" @click="pickTab('tournaments')">
                    <Icon name="trophy" size="sm" />{{ $t('club.tournaments') }}<span v-if="tournaments?.length" class="n">{{ tournaments.length }}</span>
                </button>
                <button type="button" role="tab" :aria-selected="tab === 'games'" data-test="tab-games" @click="pickTab('games')">
                    <Icon name="ball" size="sm" />{{ $t('common.games') }}<span v-if="openGames" class="n">{{ openGames }}</span>
                </button>
            </nav>

            <section v-if="tab === 'tournaments'" class="section tabsec">
                <div v-if="tournaments?.length" class="tlist">
                    <Link v-for="t in tournaments" :key="t.code" class="card trow" :href="route('tournament.show', t.code)">
                        <Icon name="trophy" />
                        <span class="w"><b>{{ t.title }}</b><small>{{ t.venue }} · {{ whenOf(t.when) }}</small></span>
                        <span class="pill">{{ t.from ? $t('common.from_lower') + ' ' + amd(t.from) : $t('club.free') }}</span>
                    </Link>
                </div>
                <div v-else class="empty">{{ $t('player.home.no_tournaments') }}</div>
            </section>

            <section v-else-if="tab === 'games'" class="section tabsec">
                <div class="row between" style="margin-bottom: 12px; gap: 10px; flex-wrap: wrap">
                    <span class="muted">{{ $t('player.home.games_n', { n: String(openGames ?? 0) }) }}</span>
                    <span class="row" style="gap: 8px">
                        <Link class="btn ghost sm" :href="route('games.index', { sport })">{{ $t('player.home.all_games') }}</Link>
                        <Link class="btn sm" :href="route('games.create', { sport })"><Icon name="plus" size="sm" />{{ $t('common.create_game') }}</Link>
                    </span>
                </div>
                <div v-if="games?.length" class="glist">
                    <GameCard v-for="g in games" :key="g.id" :game="g" :invite="g.me === 'invited'" />
                </div>
                <div v-else class="empty">{{ $t('player.home.no_games') }}</div>
            </section>

            <section v-else id="venues" class="section tabsec">
                <div class="section-head">
                    <div>
                        <span class="kicker">{{ $t(tx('venues_kicker')) }}</span>
                        <h2>{{ $t('player.home.venues_title') }}</h2>
                    </div>
                </div>
                <div class="filters">
                    <div class="days">
                        <button v-for="d in days" :key="d.date" type="button" :class="['day', { we: d.weekend }]" :aria-pressed="filters.date === d.date" @click="go({ date: d.date })">
                            <small>{{ d.k === 0 ? $t('common.today') : wdName(d.wd) }}</small><b>{{ d.day }}</b>
                        </button>
                    </div>
                    <div class="chips">
                        <template v-if="isFootball">
                            <button type="button" class="chip" :aria-pressed="!filters.size" @click="go({ size: null })">{{ $t('common.all_fields') }}</button>
                            <button v-for="z in ['small', 'big'] as const" :key="z" type="button" class="chip" :aria-pressed="filters.size === z" @click="go({ size: z })">
                                <span class="sport-dot" :style="{ background: SIZE_COLOR[z] }" />{{ z === 'small' ? '5×5' : '11×11' }}
                            </button>
                        </template>
                        <select v-model="f.time" class="input sel" :aria-label="$t('player.place.start_time')" @change="go({ time: f.time || null })">
                            <option value="">{{ $t('player.home.any_time') }}</option>
                            <option value="morning">{{ $t('player.home.morning') }}</option>
                            <option value="day">{{ $t('player.home.day') }}</option>
                            <option value="evening">{{ $t('player.home.evening') }}</option>
                            <option v-for="t in startTimes" :key="t" :value="t">{{ $t('player.home.start_at', { t }) }}</option>
                        </select>
                        <select v-if="(districts?.length ?? 0) > 1" v-model="f.district" class="input sel" :aria-label="$t('common.district')" @change="go({ district: f.district || null })">
                            <option value="">{{ $t('player.home.any_district') }}</option>
                            <option v-for="d in districts" :key="d" :value="d">{{ $t(`common.districts.${d}`) }}</option>
                        </select>
                    </div>
                </div>

                <div :class="['grid c3', { dim: loading }]">
                    <article v-for="v in venues" :key="v.id" class="card vcard">
                        <Link class="art" :href="placeUrl(v)" :prefetch="['hover', 'click']" :aria-label="v.name">
                            <div class="court-art">
                                <CourtSvg v-if="isFootball" :size="filters.size || 'big'" :color="filters.size !== 'small' ? pitchColor(big(v)?.surface) : undefined" />
                                <CourtSvg v-else size="table" />
                            </div>
                            <div class="lights" />
                            <div v-if="!isFootball" class="badges">
                                <span v-for="fl in v.fields" :key="fl.id" class="pill">🏓 {{ $t(tx('tables_n'), { n: String(fl.count ?? 1) }) }}</span>
                            </div>
                            <div v-else class="badges">
                                <span v-if="small(v)" class="pill"><Icon :name="small(v)!.indoor ? 'roof' : 'sun'" size="sm" />5×5</span>
                                <span v-if="big(v)" class="pill"><Icon name="ball" size="sm" />11×11<template v-if="big(v)!.surface === 'grass'"> · {{ $t('common.surfaces.grass') }}</template></span>
                            </div>
                        </Link>
                        <div class="body">
                            <div class="row between">
                                <h3><Link :href="placeUrl(v)" :prefetch="['hover', 'click']">{{ v.name }}</Link></h3>
                                <span v-if="v.rating" class="rating"><Icon name="star" size="sm" />{{ v.rating.toFixed(1) }}</span>
                            </div>
                            <div class="meta">
                                <span><Icon name="pin" size="sm" />{{ $t(`common.districts.${v.district}`) }}</span>
                                <span v-if="v.hours"><Icon name="clock" size="sm" />{{ hhmm(v.hours[0]) }}–{{ hhmm(v.hours[1]) }}</span>
                                <span class="price">{{ $t('common.from_lower') }} {{ amd(v.from) }} <small>{{ $t('common.per_hour') }}</small></span>
                            </div>
                            <div class="slots">
                                <div v-for="fl in v.fields" :key="fl.id" class="fslots">
                                    <span v-if="isFootball" class="fz" :style="{ color: SIZE_COLOR[fl.size] }">{{ courtShort(fl) }}</span>
                                    <template v-if="fl.starts.length">
                                        <Link v-for="h in fl.starts" :key="h" class="slot-mini" :href="placeUrl(v, fl, h)" :prefetch="['hover', 'click']">{{ hhmm(h) }}</Link>
                                        <Link v-if="fl.more" class="slot-more" :href="placeUrl(v, fl)" :prefetch="['hover', 'click']">{{ $t('player.home.more_times', { n: String(fl.more) }) }}<Icon name="arrow" size="sm" /></Link>
                                    </template>
                                    <Link v-else-if="fl.next" class="next-free" :href="placeUrl(v, fl, fl.next.start, fl.next.date)" :prefetch="['hover', 'click']">
                                        {{ $t('common.next_free_t', { t: nextLabel(fl.next.date, days?.[0]?.date ?? '', hhmm(fl.next.start), true) }) }}<Icon name="arrow" size="sm" />
                                    </Link>
                                    <span v-else class="muted" style="font-size: 12.5px">
                                        {{ filters.time ? $t('player.home.no_free_time') : isToday() ? $t('player.home.no_free_today') : $t('player.home.no_free') }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </article>
                    <div v-if="!venues?.length" class="empty" style="grid-column: 1 / -1">{{ $t('player.home.none') }}</div>
                </div>
            </section>
        </template>
    </PlayerLayout>
</template>

<style scoped>
.pick { max-width: 720px; margin: 28px auto 0; text-align: center; }
.pick h1 { font-size: clamp(30px, 6vw, 46px); margin: 0 0 6px; }
.ptiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; margin-top: 22px; text-align: left; }
.ptile { position: relative; overflow: hidden; display: flex; align-items: flex-end; gap: 12px; min-height: 190px; padding: 18px; border-radius: 24px; border: 1px solid var(--line-2); background: var(--surface); color: var(--ink); transition: transform .18s, border-color .18s; }
.ptile:hover { transform: translateY(-2px); border-color: var(--accent); }
.ptile .art { position: absolute; inset: 0; opacity: .55; transform: perspective(700px) rotateX(48deg) rotateZ(-14deg) scale(1.15) translateY(-12%); pointer-events: none; }
.ptile .art :deep(svg) { width: 100%; }
.ptile::after { content: ''; position: absolute; inset: 0; background: linear-gradient(0deg, var(--surface) 25%, transparent 90%); pointer-events: none; }
.ptile > * { position: relative; z-index: 1; }
.ptile .em { font-size: 34px; line-height: 1; }
.ptile .nm { flex: 1; display: flex; flex-direction: column; }
.ptile .nm b { font-family: var(--display); font-size: 24px; }
.ptile .nm small { color: var(--muted); font-weight: 700; }
.ptile .go { color: var(--muted); }
.ptile.pingpong:hover { border-color: var(--field-table); }
.shead { margin-top: 18px; display: flex; flex-direction: column; gap: 14px; }
.shead h1 { margin: 0; font-size: clamp(26px, 5vw, 38px); }
.shead h1 em { font-style: normal; background: var(--grad); -webkit-background-clip: text; background-clip: text; color: transparent; }
.allsports { display: inline-flex; align-items: center; gap: 8px; color: var(--muted); font-weight: 700; font-size: 14px; align-self: flex-start; }
.allsports:hover { color: var(--accent); }
.stabs { position: sticky; top: 64px; z-index: 20; display: grid; grid-auto-flow: column; grid-auto-columns: minmax(0, 1fr); gap: 4px; margin-top: 16px; padding: 4px; border-radius: 16px; background: color-mix(in srgb, var(--surface) 92%, transparent); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid var(--line); }
.stabs button { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 10px 6px; border: 0; border-radius: 12px; background: none; color: var(--muted); font: inherit; font-weight: 800; font-size: 14px; min-width: 0; white-space: nowrap; }
.stabs button[aria-selected="true"] { background: var(--accent); color: var(--on-accent); }
@media (max-width: 440px) { .stabs button { font-size: 12.5px; gap: 4px; padding: 10px 2px; } .stabs button .ico { display: none; } }
.stabs .n { font-size: 11px; padding: 0 6px; border-radius: 9px; background: color-mix(in srgb, currentColor 18%, transparent); }
.tabsec { margin-top: 18px; }
.glist { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
.acts { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-top: 16px; }
.act { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-radius: 18px; border: 1px solid var(--line-2); background: var(--surface); color: var(--ink); text-align: left; font: inherit; }
.act > .ico { color: var(--accent); flex: none; }
.act span { display: flex; flex-direction: column; }
.act small { color: var(--muted); font-weight: 700; font-size: 12.5px; }
.act.book { background: color-mix(in srgb, var(--accent) 12%, var(--surface)); border-color: color-mix(in srgb, var(--accent) 45%, transparent); }
.tlist { display: grid; gap: 8px; }
.trow { display: flex; align-items: center; gap: 12px; padding: 12px 14px; color: var(--ink); }
.trow > .ico { color: var(--accent); }
.trow .w { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.trow small { color: var(--muted); font-size: 12.5px; }
.filters { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 16px; }
.filters .days { min-width: 0; max-width: 100%; }
.filters .sel { width: auto; padding: 7px 30px 7px 12px; border-radius: 999px; font-size: 13.5px; font-weight: 700; }
.day.we:not([aria-pressed="true"]) small { color: var(--warn); }
.vcard .art { display: block; }
.vcard h3 a:hover { color: var(--accent); }
.slot-more { display: inline-flex; align-items: center; gap: 4px; font-size: 12.5px; padding: 5px 9px; color: var(--muted); font-weight: 700; border-radius: 8px; border: 1px solid var(--line-2); }
.slot-more:hover { color: var(--ink); }
.next-free { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 700; color: var(--ink); padding: 4px 9px; border-radius: 8px; border: 1px solid var(--line-2); }
.next-free:hover { border-color: var(--accent); color: var(--accent); }
.grid.dim { opacity: .55; transition: opacity .15s; }
</style>
