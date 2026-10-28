<script setup lang="ts">
import Avatar from '@/components/Avatar.vue';
import Icon from '@/components/Icon.vue';
import { useOwner } from '@/composables/useOwner';
import { SPORT_ICON } from '@/composables/usePlayer';
import { amd } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';

// Players: everybody who played here (bookings, cards, tournaments) with visits, the last visit, the rating in
// the place's sport and the card balance. Filters: name / phone, rating from–to, with / without a card; sorting.
interface Row {
    id: number;
    name: string | null;
    phone: string | null;
    avatar: string | null;
    visits: number;
    last: string | null;
    rating: number | null;
    card: { balance: number; code: string } | null;
    noShows: number;
}
const props = defineProps<{
    filters: { q: string; min: number | null; max: number | null; card: 'yes' | 'no' | null; noshow: 'yes' | 'no' | null; sort: string };
    players: Row[];
    total: number;
    sport: string;
}>();
const owner = useOwner();
const has = (f: string) => (owner.value.features ?? []).includes(f);
const f = reactive({ q: props.filters.q, min: props.filters.min ?? '', max: props.filters.max ?? '', card: props.filters.card ?? '', noshow: props.filters.noshow ?? '', sort: props.filters.sort });
let t: number | undefined;
watch(
    () => ({ ...f }),
    (v) => {
        clearTimeout(t);
        const q = Object.fromEntries(Object.entries(v).filter(([k, x]) => x !== '' && x !== null && !(k === 'sort' && x === 'last')));
        t = window.setTimeout(() => router.get(route('owner.players'), q, { preserveState: true, preserveScroll: true, replace: true, only: ['players', 'filters'] }), 300);
    },
);
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.players')">
        <div class="phead">
            <div>
                <div class="kicker">{{ $t('owner.club.players_kicker', { n: total }) }} · {{ owner.venue.name }}</div>
                <h1>{{ $t('owner.nav.players') }}</h1>
            </div>
        </div>

        <section class="card panel">
            <div class="filters">
                <div class="search" style="flex: 1 1 220px">
                    <Icon name="search" /><input v-model="f.q" class="input" :placeholder="$t('owner.club.search_ph')" data-test="search" />
                </div>
                <template v-if="has('rating')">
                    <input v-model="f.min" class="input num" inputmode="numeric" :placeholder="$t('owner.club.rating_from')" :aria-label="$t('owner.club.rating_from')" />
                    <input v-model="f.max" class="input num" inputmode="numeric" :placeholder="$t('owner.club.rating_to')" :aria-label="$t('owner.club.rating_to')" />
                </template>
                <select v-if="has('wallet')" v-model="f.card" class="input" :aria-label="$t('owner.nav.cards')">
                    <option value="">{{ $t('owner.club.card_any') }}</option>
                    <option value="yes">{{ $t('owner.club.card_yes') }}</option>
                    <option value="no">{{ $t('owner.club.card_no') }}</option>
                </select>
                <!-- players who did not come here (only this place counts) -->
                <select v-model="f.noshow" class="input" :aria-label="$t('owner.club.noshow_filter')" data-test="noshow-filter">
                    <option value="">{{ $t('owner.club.noshow_any') }}</option>
                    <option value="yes">{{ $t('owner.club.noshow_yes') }}</option>
                    <option value="no">{{ $t('owner.club.noshow_no') }}</option>
                </select>
                <select v-model="f.sort" class="input" :aria-label="$t('owner.club.sort')">
                    <option v-for="s in ['last', 'visits', 'rating', 'name', 'noshows']" :key="s" :value="s">{{ $t('owner.club.sort_' + s) }}</option>
                </select>
            </div>
            <div class="plist">
                <Link v-for="p in players" :key="p.id" class="prow" :href="route('owner.players.show', p.id)" data-test="player-row">
                    <Avatar :id="p.id" :name="p.name" :src="p.avatar" size="sm" />
                    <span class="who"
                        ><b>{{ p.name || '—' }}</b><small>{{ p.phone }} · {{ $t('owner.club.visits_n', { n: p.visits }) }}<template v-if="p.last"> · {{ p.last }}</template></small></span
                    >
                    <span v-if="has('rating') && p.rating != null" class="pill lime">{{ SPORT_ICON[sport] }} {{ p.rating }}</span>
                    <span v-if="p.noShows" class="pill hot" :title="$t('owner.booking.no_shows_here', { n: p.noShows })">⚠ {{ p.noShows }}</span>
                    <span v-if="has('wallet') && p.card" class="bal">{{ amd(p.card.balance) }}</span>
                </Link>
                <p v-if="!players.length" class="faint" style="margin: 14px 0 4px">{{ $t('owner.schedule.none') }}</p>
            </div>
        </section>
    </OwnerLayout>
</template>

<style scoped>
.filters { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.filters .num { width: 110px; }
.filters select { width: auto; }
.plist { display: flex; flex-direction: column; margin-top: 10px; }
.prow { display: flex; align-items: center; gap: 12px; padding: 10px 4px; border-bottom: 1px solid var(--line); color: var(--ink); }
.prow:last-child { border-bottom: 0; }
.prow:hover { background: var(--surface-2); }
.prow .who { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.prow .who small { color: var(--muted); font-size: 12.5px; }
.prow .bal { font-family: var(--mono); font-size: 14px; font-weight: 700; }
</style>
