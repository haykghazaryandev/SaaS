<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import TournamentForm from '@/components/owner/TournamentForm.vue';
import { useOwner } from '@/composables/useOwner';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';

// Tournaments: the list (each opens its own page: players, bracket, news, chat) and "New tournament".
interface Row {
    id: number;
    title: string;
    date: string;
    time: string;
    status: 'open' | 'closed' | 'done';
    drawn: boolean;
    entries: number;
    waiting: number;
    accepted: number;
    unpaid: number;
}
defineProps<{ tournaments: Row[]; sport: string }>();
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
            <Link v-for="t in tournaments" :key="t.id" :href="route('owner.tournaments.show', t.id)" :class="['card', 'trow', t.status]" :data-test="`tournament-${t.id}`">
                <span class="ico"><Icon name="trophy" /></span>
                <span class="w">
                    <b>{{ t.title }}</b>
                    <small>{{ t.date.slice(8) }}.{{ t.date.slice(5, 7) }} · {{ t.time }} · {{ $t('owner.club.t_status_' + t.status) }}</small>
                    <span class="tags">
                        <span class="pill">{{ $t('owner.tournament.n_registered', { n: t.entries }) }}</span>
                        <span v-if="t.waiting" class="pill warn">{{ $t('owner.tournament.n_waiting', { n: t.waiting }) }}</span>
                        <span v-if="t.accepted" class="pill green">{{ $t('owner.tournament.n_accepted', { n: t.accepted }) }}</span>
                        <span v-if="t.unpaid" class="pill hot">{{ $t('owner.tournament.n_unpaid', { n: t.unpaid }) }}</span>
                        <span v-if="t.drawn" class="pill">{{ $t('owner.tournament.bracket') }}</span>
                    </span>
                </span>
                <Icon name="arrow" size="sm" class="faint" />
            </Link>
        </div>
        <p v-if="!tournaments.length && !creating" class="faint">{{ $t('owner.club.no_tournaments') }}</p>
    </OwnerLayout>
</template>

<style scoped>
.tlist { display: grid; gap: 10px; }
.trow { display: flex; align-items: center; gap: 12px; padding: 14px; color: inherit; text-decoration: none; }
.trow.done { opacity: 0.7; }
.trow .ico { width: 40px; height: 40px; border-radius: 12px; display: grid; place-items: center; background: rgba(255, 159, 67, 0.12); color: #ff9f43; flex: none; }
.trow .w { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.trow small { color: var(--muted); font-size: 12.5px; }
.tags { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 4px; }
.pill.hot { color: var(--hot); background: var(--hot-soft); }
.pill.warn { color: var(--warn); background: var(--warn-soft); }
</style>
