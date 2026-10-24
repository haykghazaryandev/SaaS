<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import CourtSvg from '@/components/player/CourtSvg.vue';
import { amd, hhmm } from '@/composables/useShared';
import { SIZE_COLOR, nextLabel, pitchColor, wdName } from '@/composables/usePlayer';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import type { DayInfo, VenueCard } from '@/types/player';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

// Home: finder + size tiles + places with the next free start times of their two fields.
// A free-time chip opens the place page with the field, day, duration and time chosen; a field with nothing free
// that day shows "Next free: Thu 19:00" instead.
interface Filters {
    date: string;
    time: string | null;
    size: 'small' | 'big' | null;
    district: string | null;
}
const props = defineProps<{
    filters: Filters;
    days: DayInfo[];
    districts: string[];
    venues: VenueCard[];
    tiles: Record<'small' | 'big', { places: number; tonight: number; from: number }>;
    stats: { venues: number; fields: number; tonight: number };
}>();

const f = reactive({ size: props.filters.size ?? '', district: props.filters.district ?? '', time: props.filters.time ?? '' });
const loading = ref(false);
const startTimes = Array.from({ length: 16 }, (_, i) => `${String(8 + i).padStart(2, '0')}:00`);

function go(change: Partial<Filters>, scroll = false) {
    const next = { ...props.filters, ...change };
    const q: Record<string, string> = {};
    if (next.date && next.date !== props.days[0]?.date) q.date = next.date;
    if (next.time) q.time = next.time;
    if (next.size) q.size = next.size;
    if (next.district) q.district = next.district;
    f.size = next.size ?? '';
    f.time = next.time ?? '';
    f.district = next.district ?? '';
    router.get(route('home'), q, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        only: ['filters', 'venues'],
        onStart: () => (loading.value = true),
        onFinish: () => {
            loading.value = false;
            if (scroll) document.getElementById('venues')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
    });
}
function find() {
    go({ size: (f.size || null) as Filters['size'], district: f.district || null, time: f.time || null }, true);
}
function toggleSize(z: 'small' | 'big') {
    go({ size: props.filters.size === z ? null : z });
}

type CardField = VenueCard['fields'][number];
const placeUrl = (v: VenueCard, f?: CardField, start?: number, date?: string) => {
    const d = date ?? props.filters.date;
    return route('places.show', {
        venue: v.slug,
        date: d !== props.days[0]?.date ? d : undefined,
        field: f?.id,
        minutes: f && start !== undefined ? f.defaultDuration : undefined,
        size: !f && props.filters.size ? props.filters.size : undefined,
        start: start !== undefined ? hhmm(start) : undefined,
    });
};
const isToday = () => props.filters.date === props.days[0]?.date;
const big = (v: VenueCard) => v.allFields.find((x) => x.size === 'big');
const small = (v: VenueCard) => v.allFields.find((x) => x.size === 'small');
</script>

<template>
    <PlayerLayout>
        <Head :title="$t('player.home.title')" />

        <section class="hero">
            <div class="hero-copy">
                <span class="kicker">{{ $t('player.home.kicker') }}</span>
                <h1>{{ $t('player.home.title_a') }}<br /><em>{{ $t('player.home.title_b') }}</em></h1>
                <p>{{ $t('player.home.lead') }}</p>
                <form class="finder" @submit.prevent="find">
                    <!-- a small label above each filter keeps the values short, so they fit in Armenian and Russian too -->
                    <label class="fsel">
                        <span>{{ $t('common.field') }}</span>
                        <select v-model="f.size" class="input [color-scheme:dark]">
                            <option class="bg-surface text-ink checked:text-accent" value="">{{ $t('player.home.any') }}</option>
                            <option class="bg-surface text-ink checked:text-accent" value="small">{{ $t('player.size.small') }} 5×5</option>
                            <option class="bg-surface text-ink checked:text-accent" value="big">{{ $t('player.size.big') }} 11×11</option>
                        </select>
                    </label>
                    <label class="fsel">
                        <span>{{ $t('common.district') }}</span>
                        <select v-model="f.district" class="input [color-scheme:dark]">
                            <option class="bg-surface text-ink checked:text-accent" value="">{{ $t('player.home.any') }}</option>
                            <option class="bg-surface text-ink checked:text-accent" v-for="d in districts" :key="d" :value="d">{{ $t(`common.districts.${d}`) }}</option>
                        </select>
                    </label>
                    <label class="fsel">
                        <span>{{ $t('player.place.start_time') }}</span>
                        <select v-model="f.time" class="input [color-scheme:dark]">
                            <option class="bg-surface text-ink checked:text-accent" value="">{{ $t('player.home.any') }}</option>
                            <option class="bg-surface text-ink checked:text-accent" value="morning">{{ $t('player.home.morning') }}</option>
                            <option class="bg-surface text-ink checked:text-accent" value="day">{{ $t('player.home.day') }}</option>
                            <option class="bg-surface text-ink checked:text-accent" value="evening">{{ $t('player.home.evening') }}</option>
                            <option class="bg-surface text-ink checked:text-accent" v-for="t in startTimes" :key="t" :value="t">{{ $t('player.home.start_at', { t }) }}</option>
                        </select>
                    </label>
                    <button class="btn wide" :disabled="loading"><Icon name="search" />{{ $t('common.find_a_field') }}</button>
                </form>
                <div class="stats">
                    <div v-if="stats.venues"><b>{{ stats.venues }}</b><span>{{ $t('player.home.stat_venues') }}</span></div>
                    <div v-if="stats.fields"><b>{{ stats.fields }}</b><span>{{ $t('player.home.stat_fields') }}</span></div>
                    <div v-if="stats.tonight"><b>{{ stats.tonight }}</b><span>{{ $t('player.home.stat_tonight') }}</span></div>
                </div>
            </div>
            <div class="hero-art" aria-hidden="true">
                <div class="stage">
                    <CourtSvg size="big" /><CourtSvg size="small" /><CourtSvg size="small" color="#2a7f45" /><CourtSvg size="big" color="#2b7d3f" />
                </div>
            </div>
        </section>

        <section class="section">
            <div class="sizes">
                <button
                    v-for="z in ['small', 'big'] as const"
                    :key="z"
                    type="button"
                    class="size-tile"
                    :style="{ '--tc': SIZE_COLOR[z] }"
                    :aria-pressed="filters.size === z"
                    @click="toggleSize(z)"
                >
                    <CourtSvg :size="z" class="bgc" />
                    <span class="ic">{{ z === 'small' ? '5×5' : '11×11' }}</span>
                    <span>
                        <b>{{ $t(`player.size.${z}`) }} · {{ z === 'small' ? '5×5' : '11×11' }}</b>
                        <small>{{ $t(z === 'small' ? 'player.home.small_sub' : 'player.home.big_sub') }}</small>
                    </span>
                    <span class="tags">
                        <span v-if="tiles[z].places" class="pill">{{ $t('player.home.places_count', { n: String(tiles[z].places) }) }}</span>
                        <span v-if="tiles[z].tonight" class="pill green">{{ $t('player.home.tonight', { n: String(tiles[z].tonight) }) }}</span>
                        <span class="pill">{{ $t('common.from_lower') }} {{ amd(tiles[z].from) }} {{ $t('common.per_hour') }}</span>
                    </span>
                </button>
            </div>
        </section>

        <section id="venues" class="section">
            <div class="section-head">
                <div>
                    <span class="kicker">{{ $t('player.home.venues_kicker') }}</span>
                    <h2>{{ $t('player.home.venues_title') }}</h2>
                </div>
            </div>
            <div class="filters">
                <div class="days">
                    <button
                        v-for="d in days"
                        :key="d.date"
                        type="button"
                        :class="['day', { we: d.weekend }]"
                        :aria-pressed="filters.date === d.date"
                        @click="go({ date: d.date })"
                    >
                        <small>{{ d.k === 0 ? $t('common.today') : wdName(d.wd) }}</small><b>{{ d.day }}</b>
                    </button>
                </div>
                <div class="chips">
                    <button type="button" class="chip" :aria-pressed="!filters.size" @click="go({ size: null })">{{ $t('common.all_fields') }}</button>
                    <button v-for="z in ['small', 'big'] as const" :key="z" type="button" class="chip" :aria-pressed="filters.size === z" @click="go({ size: z })">
                        <span class="sport-dot" :style="{ background: SIZE_COLOR[z] }" />{{ z === 'small' ? '5×5' : '11×11' }}
                    </button>
                </div>
            </div>

            <div :class="['grid c3', { dim: loading }]">
                <article v-for="v in venues" :key="v.id" class="card vcard">
                    <Link class="art" :href="placeUrl(v)" :prefetch="['hover', 'click']" :aria-label="v.name">
                        <div class="court-art"><CourtSvg :size="filters.size || 'big'" :color="filters.size !== 'small' ? pitchColor(big(v)?.surface) : undefined" /></div>
                        <div class="lights" />
                        <div class="badges">
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
                                <span class="fz" :style="{ color: SIZE_COLOR[fl.size] }">{{ fl.format }}</span>
                                <template v-if="fl.starts.length">
                                    <Link v-for="h in fl.starts" :key="h" class="slot-mini" :href="placeUrl(v, fl, h)" :prefetch="['hover', 'click']" :aria-label="`${fl.format} ${hhmm(h)}`">{{ hhmm(h) }}</Link>
                                    <Link v-if="fl.more" class="slot-more" :href="placeUrl(v, fl)" :prefetch="['hover', 'click']">{{ $t('player.home.more', { n: String(fl.more) }) }}</Link>
                                </template>
                                <Link v-else-if="fl.next" class="next-free" :href="placeUrl(v, fl, fl.next.start, fl.next.date)" :prefetch="['hover', 'click']">
                                    {{ $t('common.next_free_t', { t: nextLabel(fl.next.date, days[0]?.date ?? '', hhmm(fl.next.start), true) }) }}<Icon name="arrow" size="sm" />
                                </Link>
                                <span v-else class="muted" style="font-size: 12.5px">
                                    {{ filters.time ? $t('player.home.no_free_time') : isToday() ? $t('player.home.no_free_today') : $t('player.home.no_free') }}
                                </span>
                            </div>
                        </div>
                    </div>
                </article>
                <div v-if="!venues.length" class="empty" style="grid-column: 1 / -1">{{ $t('player.home.none') }}</div>
            </div>
        </section>

        <!-- later: open games and tournaments strips go here -->

        <section class="section">
            <div class="section-head"><h2>{{ $t('player.home.how_title') }}</h2></div>
            <div class="how">
                <div v-for="i in 4" :key="i" class="card">
                    <div class="n">0{{ i }}</div>
                    <h3 style="margin: 8px 0 6px">{{ $t(`player.home.step${i}_title`) }}</h3>
                    <div class="muted" style="font-size: 14px">{{ $t(`player.home.step${i}_text`) }}</div>
                </div>
            </div>
        </section>
    </PlayerLayout>
</template>

<style scoped>
.hero { position: relative; margin-top: 22px; border-radius: 28px; overflow: hidden; border: 1px solid var(--line); background: #0b120d; min-height: 420px; display: grid; grid-template-columns: 1.05fr 1fr; }
.hero-copy { padding: 52px 44px; position: relative; z-index: 2; display: flex; flex-direction: column; gap: 18px; justify-content: center; min-width: 0; }
.hero h1 em { font-style: normal; background: var(--grad); -webkit-background-clip: text; background-clip: text; color: transparent; }
.hero p { color: var(--muted); font-size: 16.5px; max-width: 470px; margin: 0; }
.hero-art { position: relative; perspective: 900px; overflow: hidden; }
.hero-art .stage { position: absolute; inset: -10% -20% -10% -5%; transform: rotateX(56deg) rotateZ(-24deg); transform-style: preserve-3d; display: grid; grid-template-columns: 1fr 1fr; gap: 22px; align-content: center; }
.hero-art .stage svg { width: 100%; filter: drop-shadow(0 30px 30px rgba(0, 0, 0, 0.6)); }
.hero-art::before { content: ""; position: absolute; inset: 0; z-index: 1; background: linear-gradient(90deg, #0b120d 0%, rgba(11, 18, 13, 0) 35%), radial-gradient(400px 200px at 60% 0%, rgba(255, 255, 230, 0.12), transparent 70%); pointer-events: none; }
.finder { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; background: var(--surface); border: 1px solid var(--line-2); border-radius: 18px; padding: 8px; max-width: 640px; }
.finder .fsel { display: flex; flex-direction: column; gap: 3px; min-width: 0; background: var(--bg-2); border-radius: var(--r-sm); padding: 7px 4px 2px 12px; }
.finder .fsel span { font-size: 10.5px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: var(--faint); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.finder .input { border: 0; background-color: transparent; min-width: 0; padding: 2px 30px 7px 0; font-weight: 700; }
.finder .btn { grid-column: 1 / -1; }
.stats { display: flex; gap: 28px; margin-top: 6px; }
.stats b { font-family: var(--display); font-size: 22px; display: block; }
.stats span { color: var(--muted); font-size: 12.5px; font-weight: 700; }
@media (max-width: 900px) {
  .hero { grid-template-columns: 1fr; min-height: 0; }
  .hero-art { height: 210px; order: -1; }
  .hero-art::before { background: linear-gradient(0deg, #0b120d 0%, rgba(11, 18, 13, 0) 45%); }
  .hero-copy { padding: 8px 22px 26px; }
  .finder { grid-template-columns: 1fr 1fr; }
  .finder .wide, .finder .fsel:first-child { grid-column: 1 / -1; }
}
@media (max-width: 440px) { .finder { grid-template-columns: 1fr; } .stats { gap: 18px; } }
.sizes { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.size-tile { position: relative; overflow: hidden; border-radius: 20px; border: 1px solid var(--line); background: var(--surface); padding: 20px; min-height: 168px; display: flex; flex-direction: column; justify-content: space-between; gap: 14px; text-align: left; color: var(--ink); transition: transform .18s, border-color .18s; }
.size-tile:hover { transform: translateY(-2px); border-color: var(--line-2); }
.size-tile[aria-pressed="true"] { border-color: var(--tc); box-shadow: inset 0 0 0 1px var(--tc); }
.size-tile .ic { width: 52px; height: 46px; border-radius: 14px; display: grid; place-items: center; background: color-mix(in srgb, var(--tc) 16%, transparent); color: var(--tc); font-family: var(--mono); font-size: 13px; font-weight: 700; position: relative; }
.size-tile b { font-family: var(--display); font-weight: 600; font-size: 19px; display: block; }
.size-tile small { color: var(--muted); font-weight: 700; font-size: 13px; display: block; margin-top: 2px; }
.size-tile > span { position: relative; }
.size-tile .tags { display: flex; gap: 6px; flex-wrap: wrap; }
.size-tile svg.bgc { position: absolute; right: -50px; bottom: -40px; width: 300px; opacity: .2; transform: rotate(-12deg); pointer-events: none; }
@media (max-width: 700px) { .sizes { grid-template-columns: 1fr; } .size-tile { min-height: 140px; padding: 16px; } .size-tile svg.bgc { width: 230px; } }
.filters { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 16px; }
.filters .days { min-width: 0; max-width: 100%; }
.day.we:not([aria-pressed="true"]) small { color: var(--warn); }
.vcard .art { display: block; }
.vcard h3 a:hover { color: var(--accent); }
.slot-more { font-family: var(--mono); font-size: 12.5px; padding: 5px 7px; color: var(--muted); font-weight: 700; }
.slot-more:hover { color: var(--ink); }
.next-free { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 700; color: var(--ink); padding: 4px 9px; border-radius: 8px; border: 1px solid var(--line-2); }
.next-free:hover { border-color: var(--accent); color: var(--accent); }
.grid.dim { opacity: .55; transition: opacity .15s; }
.how { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
.how .card { padding: 20px; min-width: 0; overflow-wrap: break-word; hyphens: auto; }
.how .n { font-family: var(--mono); color: var(--accent); font-size: 13px; }
@media (max-width: 800px) { .how { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); } }
@media (max-width: 440px) { .how { grid-template-columns: 1fr; } }
</style>
