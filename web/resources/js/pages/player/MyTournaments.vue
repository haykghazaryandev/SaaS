<script setup lang="ts">
import TournamentPhase, { type Phase } from '@/components/club/TournamentPhase.vue';
import Icon from '@/components/Icon.vue';
import { SPORT_ICON, dateLong } from '@/composables/usePlayer';
import { amd, useShared } from '@/composables/useShared';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import { useLive } from '@/lib/echo';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// My tournaments (like My bookings): every tournament I registered for — Live · Upcoming · Finished (on Live when
// one is on, else Upcoming) — with where it is, the club's answer, the fee (paid, or how much is still to pay) and my place.
interface Item {
    code: string;
    title: string;
    sport: string;
    venue: string | null;
    when: string;
    phase: Phase;
    status: 'applied' | 'accepted' | 'declined';
    place: number | null;
    option: string | null;
    fee: number;
    paid: { method: string; amount: number } | null;
}
const props = defineProps<{ items: Item[] }>();
type Group = 'live' | 'upcoming' | 'done';
const groupOf = (i: Item): Group => (i.phase === 'live' ? 'live' : i.phase === 'done' || i.phase === 'cancelled' ? 'done' : 'upcoming');
const count = (g: Group) => props.items.filter((i) => groupOf(i) === g).length;
const group = ref<Group>((['live', 'upcoming', 'done'] as const).find((g) => count(g) > 0) ?? 'upcoming');
// finished: the newest first; the others: the soonest first
const shown = computed(() => {
    const list = props.items.filter((i) => groupOf(i) === group.value);
    return group.value === 'done' ? [...list].reverse() : list;
});
const owes = (i: Item) => i.status === 'accepted' && !i.paid && i.fee > 0;
const medal = (n: number) => (n === 1 ? '🥇' : n === 2 ? '🥈' : n === 3 ? '🥉' : `#${n}`);
const time = (iso: string) => new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Yerevan' });

// live: a tournament starts, my answer comes, a result
const { user } = useShared();
let t: number | undefined;
const reload = () => {
    clearTimeout(t);
    t = window.setTimeout(() => router.reload({ only: ['items'] }), 400);
};
useLive(() => 'tournaments', 'tournament.changed', reload);
useLive(() => (user.value ? `App.Models.User.${user.value.id}` : null), 'Illuminate\\Notifications\\Events\\BroadcastNotificationCreated', reload, true);
</script>

<template>
    <PlayerLayout>
        <Head :title="$t('club.mine.title')" />
        <div class="pg">
            <h1 class="page-title">{{ $t('club.mine.title') }}</h1>
            <p class="muted" style="margin: 8px 0 14px">{{ $t('club.mine.sub') }}</p>

            <div v-if="items.length" class="chips tfilter" role="tablist">
                <button v-for="g in ['live', 'upcoming', 'done'] as const" :key="g" type="button" role="tab" class="chip" :aria-pressed="group === g" :data-test="`mine-${g}`" @click="group = g">
                    <i v-if="g === 'live' && count('live')" class="tdot" />{{ $t('club.mine.g_' + g) }} <span class="faint">{{ count(g) }}</span>
                </button>
            </div>

            <div class="list">
                <Link v-for="i in shown" :key="i.code" :href="route('tournament.show', i.code)" :class="['card', 'mt', 'ph-' + i.phase]" :data-test="`mine-${i.code}`">
                    <div class="mthead">
                        <span class="em">{{ SPORT_ICON[i.sport] ?? '🏆' }}</span>
                        <span class="w">
                            <b>{{ i.title }}</b>
                            <small>{{ i.venue }} · {{ dateLong(i.when.slice(0, 10)) }} · {{ time(i.when) }}</small>
                        </span>
                        <TournamentPhase :phase="i.phase" />
                    </div>
                    <div class="tags">
                        <!-- my place at the end, else the club's answer -->
                        <span v-if="i.place" class="tag gold">{{ medal(i.place) }} {{ $t('club.tournament.my_place', { n: i.place }) }}</span>
                        <span v-else :class="['tag', i.status]">{{ $t('club.mine.s_' + i.status) }}</span>
                        <!-- the fee: free, paid (card / cash) or still to pay -->
                        <template v-if="i.status !== 'declined'">
                            <span v-if="!i.fee" class="tag">{{ $t('club.free') }}</span>
                            <span v-else-if="i.paid" class="tag paid"><Icon name="check" size="sm" />{{ $t('club.mine.paid', { amount: amd(i.paid.amount) }) }} · {{ $t('owner.club.m_' + i.paid.method) }}</span>
                            <span v-else-if="owes(i)" class="tag owes"><Icon name="wallet" size="sm" />{{ $t('club.mine.to_pay', { amount: amd(i.fee) }) }}</span>
                            <span v-else class="tag">{{ amd(i.fee) }}</span>
                        </template>
                        <span v-if="i.option && i.fee" class="tag faint">{{ i.option }}</span>
                    </div>
                    <p v-if="owes(i)" class="hint">{{ i.phase === 'registration' ? $t('club.tournament.fee_later', { amount: amd(i.fee) }) : $t('club.tournament.unpaid', { amount: amd(i.fee) }) }}</p>
                </Link>
                <div v-if="!shown.length" class="card pad empty">
                    {{ items.length ? $t('club.mine.none_' + group) : $t('club.mine.none') }}
                    <Link v-if="!items.length" class="btn sm" style="margin-top: 10px" :href="route('home')">{{ $t('club.mine.find') }}</Link>
                </div>
            </div>
        </div>
    </PlayerLayout>
</template>

<style scoped>
.pg { max-width: 680px; margin: 0 auto; padding-top: 18px; }
.tfilter { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
.tfilter .chip { display: inline-flex; align-items: center; gap: 6px; }
.tdot { width: 7px; height: 7px; border-radius: 50%; background: #ff6b6b; animation: tdp 1.4s infinite; }
@keyframes tdp { 50% { opacity: 0.3; } }
.list { display: grid; gap: 10px; }
.mt { display: flex; flex-direction: column; gap: 10px; padding: 14px; color: inherit; text-decoration: none; border-left: 4px solid transparent; }
.mt.ph-live { border-left-color: #ff6b6b; }
.mt.ph-drawn { border-left-color: #ff9f43; }
.mt.ph-registration { border-left-color: #5ef0a8; }
.mt.ph-done { border-left-color: #ffd166; }
.mthead { display: flex; align-items: center; gap: 10px; }
.em { font-size: 24px; }
.w { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.w b { font-size: 15.5px; }
.w small { color: var(--muted); font-size: 12.5px; }
.tags { display: flex; flex-wrap: wrap; gap: 6px; }
.tag { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 800; background: var(--surface-2); color: var(--muted); }
.tag.applied { background: var(--warn-soft); color: var(--warn); }
.tag.accepted { background: var(--free-soft); color: var(--free); }
.tag.declined { background: var(--hot-soft); color: var(--hot); }
.tag.paid { background: var(--free-soft); color: var(--free); }
.tag.owes { background: var(--hot-soft); color: var(--hot); }
.tag.gold { background: rgba(255, 209, 102, 0.12); color: #ffd166; }
.hint { margin: 0; font-size: 12.5px; color: var(--hot); }
.empty { display: flex; flex-direction: column; align-items: flex-start; color: var(--muted); }
</style>
