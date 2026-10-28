<script setup lang="ts">
import TournamentPhase, { type Phase } from '@/components/club/TournamentPhase.vue';
import Icon from '@/components/Icon.vue';
import TournamentForm from '@/components/owner/TournamentForm.vue';
import { useOwner } from '@/composables/useOwner';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { trans } from '@/lib/i18n';
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';

// Tournaments: the list (each opens its own page: players, bracket, news, chat) and "New tournament".
interface Row {
    id: number;
    title: string;
    date: string;
    time: string;
    status: 'open' | 'closed' | 'done';
    phase: Phase;
    drawn: boolean;
    entries: number;
    waiting: number;
    accepted: number;
    unpaid: number;
    division?: string | null;
    min_rating?: number | null;
    max_rating?: number | null;
    /** a tournament with divisions by rating: each one a tournament of its own */
    divisions?: Row[];
}
defineProps<{ tournaments: Row[]; sport: string }>();
const range = (d: Row) =>
    d.min_rating == null && d.max_rating == null
        ? trans('owner.tournament.any_rating')
        : d.min_rating == null
          ? `≤ ${d.max_rating}`
          : d.max_rating == null
            ? `${d.min_rating}+`
            : `${d.min_rating}–${d.max_rating}`;
const sum = (t: Row, k: 'entries' | 'waiting' | 'accepted' | 'unpaid') => (t.divisions?.length ? t.divisions.reduce((n, d) => n + d[k], 0) : t[k]);
const owner = useOwner();
const canEdit = owner.value.role === 'owner';
const creating = ref(false);
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.tournaments')">
        <div class="phead">
            <div>
                <div class="kicker">{{ owner.venue.name }}</div>
                <h1>{{ $t('owner.nav.tournaments') }}</h1>
            </div>
            <div class="phead-r">
                <button v-if="canEdit && !creating" type="button" class="btn" data-test="new-tournament" @click="creating = true">
                    <Icon name="plus" size="sm" />{{ $t('owner.club.new_tournament') }}
                </button>
            </div>
        </div>

        <section v-if="creating" class="card panel" style="margin-bottom: 14px">
            <div class="sec-h"><h2>{{ $t('owner.club.new_tournament') }}</h2></div>
            <TournamentForm @done="creating = false" />
        </section>

        <div class="tlist">
            <div v-for="t in tournaments" :key="t.id" :class="['card', 'tcard', 'ph-' + t.phase, { done: t.status === 'done' }]">
                <Link :href="route('owner.tournaments.show', t.id)" class="trow" :data-test="`tournament-${t.id}`">
                    <span class="ico"><Icon name="trophy" /></span>
                    <span class="w">
                        <b>{{ t.title }}</b>
                        <small
                            >{{ t.date.slice(8) }}.{{ t.date.slice(5, 7) }} · {{ t.time
                            }}<template v-if="t.divisions?.length">
                                · {{ $t('owner.tournament.n_divisions', { n: t.divisions.length }) }}</template
                            ></small
                        >
                        <span class="tags">
                            <TournamentPhase :phase="t.phase" />
                            <span class="pill">{{ $t('owner.tournament.n_registered', { n: sum(t, 'entries') }) }}</span>
                            <span v-if="sum(t, 'waiting')" class="pill warn">{{ $t('owner.tournament.n_waiting', { n: sum(t, 'waiting') }) }}</span>
                            <span v-if="sum(t, 'accepted')" class="pill green">{{
                                $t('owner.tournament.n_accepted', { n: sum(t, 'accepted') })
                            }}</span>
                            <span v-if="sum(t, 'unpaid') && t.phase !== 'done'" class="pill hot">{{
                                $t('owner.tournament.n_unpaid', { n: sum(t, 'unpaid') })
                            }}</span>
                        </span>
                    </span>
                    <Icon name="arrow" size="sm" class="faint" />
                </Link>
                <!-- the divisions: each opens its own page -->
                <div v-if="t.divisions?.length" class="dlist">
                    <Link
                        v-for="d in t.divisions"
                        :key="d.id"
                        :href="route('owner.tournaments.show', d.id)"
                        class="drow"
                        :data-test="`tournament-${d.id}`"
                    >
                        <i :class="['dot', 'ph-' + d.phase]" />
                        <span class="w"
                            ><b>{{ d.division }}</b
                            ><small
                                >{{ range(d) }} · {{ $t('owner.tournament.n_accepted', { n: d.accepted })
                                }}<template v-if="d.waiting"> · {{ $t('owner.tournament.n_waiting', { n: d.waiting }) }}</template
                                ><template v-if="d.unpaid && d.phase !== 'done'">
                                    · {{ $t('owner.tournament.n_unpaid', { n: d.unpaid }) }}</template
                                ></small
                            ></span
                        >
                        <TournamentPhase :phase="d.phase" />
                        <Icon name="arrow" size="sm" class="go" />
                    </Link>
                </div>
            </div>
        </div>
        <p v-if="!tournaments.length && !creating" class="faint">{{ $t('owner.club.no_tournaments') }}</p>
    </OwnerLayout>
</template>

<style scoped>
.tlist { display: grid; gap: 10px; }
.tcard { border-left: 4px solid transparent; padding: 0; overflow: hidden; }
.tcard.ph-live { border-left-color: #ff6b6b; }
.tcard.ph-drawn { border-left-color: #ff9f43; }
.tcard.ph-registration { border-left-color: #5ef0a8; }
.tcard.ph-done { border-left-color: #ffd166; }
.tcard.done { opacity: 0.7; }
.trow { display: flex; align-items: center; gap: 12px; padding: 14px; color: inherit; text-decoration: none; min-width: 0; }
.dlist { border-top: 1px solid var(--line); padding: 4px 14px 6px 66px; }
.drow { display: flex; align-items: center; gap: 10px; padding: 9px 0; color: inherit; text-decoration: none; border-bottom: 1px solid var(--line); min-width: 0; }
.drow:last-child { border-bottom: 0; }
.trow, .drow { cursor: pointer; transition: background 0.15s; }
.trow:hover { background: var(--surface-2); }
.drow { margin: 0 -8px; padding-inline: 8px; border-radius: 10px; }
.drow:hover { background: var(--surface-2); }
.drow .go { color: var(--faint); transition: transform 0.15s, color 0.15s; flex: none; }
.drow:hover .go, .trow:hover > .ico:last-child { color: var(--accent); transform: translateX(3px); }
.trow > .ico:last-child { transition: transform 0.15s, color 0.15s; }
.drow .w { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.drow small { color: var(--muted); font-size: 12px; }
.dot { width: 8px; height: 8px; border-radius: 50%; background: var(--muted); flex: none; }
.dot.ph-live { background: #ff6b6b; }
.dot.ph-drawn { background: #ff9f43; }
.dot.ph-registration { background: #5ef0a8; }
.dot.ph-done { background: #ffd166; }
@media (max-width: 520px) { .dlist { padding-left: 14px; } .drow :deep(.tphase) { display: none; } }
.trow .ico { width: 40px; height: 40px; border-radius: 12px; display: grid; place-items: center; background: rgba(255, 159, 67, 0.12); color: #ff9f43; flex: none; }
.trow .w { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.trow small { color: var(--muted); font-size: 12.5px; }
.tags { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 4px; }
.pill.hot { color: var(--hot); background: var(--hot-soft); }
.pill.warn { color: var(--warn); background: var(--warn-soft); }
</style>
