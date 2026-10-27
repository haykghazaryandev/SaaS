<script setup lang="ts">
import Bracket, { type BracketData } from '@/components/club/Bracket.vue';
import BracketPreview, { type Player } from '@/components/club/BracketPreview.vue';
import TournamentPhase from '@/components/club/TournamentPhase.vue';
import Icon from '@/components/Icon.vue';
import { SPORT_ICON, dateLong } from '@/composables/usePlayer';
import { amd } from '@/composables/useShared';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import { useSportTheme } from '@/lib/sport';
import { useLive } from '@/lib/echo';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

// A tournament's page: the link the club puts in its Instagram bio. Registration (name, surname, phone, level, a price
// option; no account needed, logged in: filled in) within the rating range and the number of players; my answer and the
// tournament chat once accepted; after the draw the bracket, live (tournament.{code}; guests: every 30 s).
interface Opt {
    label: string;
    price: number;
}
const props = defineProps<{
    tournament: {
        code: string;
        title: string;
        sport: string;
        date: string;
        time: string;
        description: string | null;
        options: Opt[];
        open: boolean;
        /** registration | closed (by the club) | drawn (the pairs are known) | live | done */
        phase: 'registration' | 'closed' | 'drawn' | 'live' | 'done';
        venue: { slug: string; name: string };
        count: number;
        accepted: number;
        podium: { place: number; name: string }[];
        players: Player[];
        min_rating: number | null;
        max_rating: number | null;
        max_players: number | null;
        status: string;
    };
    me: { first_name: string; last_name: string; phone: string; registered: boolean } | null;
    bracket: BracketData | null;
    entry: { status: 'applied' | 'accepted' | 'declined'; place: number | null; unpaid: number } | null;
    chat: number | null;
}>();
const t = computed(() => props.tournament);
const whenText = computed(() => `${dateLong(t.value.date)} · ${t.value.time}`);
// the whole bracket, full screen (the tree on computers, half by half on phones)
const bigView = ref(false);
function openFull() {
    bigView.value = true;
    document.body.style.overflow = 'hidden';
}
function closeFull() {
    bigView.value = false;
    document.body.style.overflow = '';
}
const ranged = computed(() => t.value.min_rating != null || t.value.max_rating != null);
const full = computed(() => !!t.value.max_players && t.value.count >= t.value.max_players);
// live: the draw, a result, a match being played
const reload = () => router.reload({ only: ['bracket', 'entry', 'tournament', 'chat'] });
useLive(() => `tournament.${props.tournament.code}`, 'tournament.changed', reload);
let poll: number | undefined;
onMounted(() => {
    if (!window.Echo && props.bracket && t.value.phase !== 'done') poll = window.setInterval(reload, 30000);
});
onBeforeUnmount(() => {
    clearInterval(poll);
    document.body.style.overflow = '';
});
useSportTheme(() => props.tournament.sport);
const done = ref(!!props.me?.registered);
const form = useForm({ first_name: props.me?.first_name ?? '', last_name: props.me?.last_name ?? '', phone: props.me?.phone ?? '', level: '' as string | number, option: 0 });
const err = computed(() => (form.errors as Record<string, string>).register);
function send() {
    form.transform((d) => ({ ...d, phone: '+374' + String(d.phone).replace(/\D/g, '').slice(-8), level: d.level === '' ? null : Number(d.level) })).post(route('tournament.register', props.tournament.code), {
        preserveScroll: true,
        onSuccess: () => (done.value = true),
    });
}
</script>

<template>
    <PlayerLayout>
        <Head :title="tournament.title" />
        <div class="pg">
            <div class="card pad tour">
                <span class="kicker">{{ SPORT_ICON[tournament.sport] }} {{ tournament.venue.name }}</span>
                <h1 class="page-title" style="margin: 6px 0">{{ tournament.title }}</h1>
                <TournamentPhase :phase="tournament.phase" style="align-self: flex-start" />
                <p class="muted" style="margin: 0"><Icon name="calendar" size="sm" /> {{ dateLong(tournament.date) }} · {{ tournament.time }} · {{ $t('club.t_registered', { n: tournament.count }) }}</p>
                <p v-if="ranged || tournament.max_players" class="chips">
                    <span v-if="ranged" class="chip">{{ $t('club.tournament.rating', { range: `${tournament.min_rating ?? '…'}–${tournament.max_rating ?? '…'}` }) }}</span>
                    <span v-if="tournament.max_players" :class="['chip', { hot: full }]">{{
                        full ? $t('club.tournament.full') : $t('club.tournament.players', { n: tournament.count, max: tournament.max_players })
                    }}</span>
                </p>
                <p v-if="tournament.description" style="white-space: pre-line">{{ tournament.description }}</p>

                <!-- my registration: the club's answer, my place, the chat -->
                <div v-if="entry" :class="['ok', entry.status]" data-test="my-entry">
                    <Icon :name="entry.status === 'declined' ? 'x' : 'check'" />{{
                        entry.place ? $t('club.tournament.my_place', { n: entry.place }) : $t('club.tournament.status_' + entry.status)
                    }}
                </div>
                <div v-else-if="done" class="ok"><Icon name="check" />{{ $t('player.tournament.registered') }}</div>
                <div v-if="entry?.unpaid" class="ok unpaid" data-test="unpaid"><Icon name="wallet" />{{ $t('club.tournament.unpaid', { amount: amd(entry.unpaid) }) }}</div>
                <Link v-if="chat" class="btn" :href="route('messages.show', chat)" data-test="t-chat"><Icon name="chat" size="sm" />{{ $t('club.tournament.chat') }}</Link>
                <!-- where the tournament is: no form once the draw is made, it started or it is over -->
                <div v-if="tournament.phase !== 'registration'" :class="['phase', tournament.phase]" data-test="phase">
                    <i v-if="tournament.phase === 'live'" class="pdot" />{{ $t('club.tournament.phase_' + tournament.phase) }}
                </div>
                <ol v-if="tournament.podium.length" class="podium" data-test="podium">
                    <li v-for="p in tournament.podium" :key="p.place + p.name" :class="'p' + p.place">
                        <span>{{ p.place === 1 ? '🥇' : p.place === 2 ? '🥈' : '🥉' }}</span><b>{{ p.name }}</b>
                    </li>
                </ol>
                <template v-if="entry || done || tournament.phase !== 'registration'" />
                <p v-else-if="full" class="muted">{{ $t('club.tournament.full') }}</p>
                <form v-else-if="tournament.open" class="tf" data-test="register-form" @submit.prevent="send">
                    <div class="two">
                        <label class="field"><span>{{ $t('club.first_name') }}</span><input v-model="form.first_name" class="input" required minlength="2" maxlength="60" autocomplete="given-name" /></label>
                        <label class="field"><span>{{ $t('club.last_name') }}</span><input v-model="form.last_name" class="input" required minlength="2" maxlength="60" autocomplete="family-name" /></label>
                    </div>
                    <label class="field"
                        ><span>{{ $t('common.phone') }}</span>
                        <div class="row" style="gap: 6px"><span class="input pre">+374</span><input v-model="form.phone" class="input mono" inputmode="tel" required placeholder="91 234 567" /></div
                    ></label>
                    <label class="field"
                        ><span>{{ $t('club.level') }}</span
                        ><input v-model="form.level" class="input" inputmode="numeric" :required="ranged" :placeholder="$t('club.level_ph')"
                    /></label>
                    <div class="field">
                        <span>{{ $t('club.option') }}</span>
                        <div class="opts">
                            <button v-for="(o, i) in tournament.options" :key="i" type="button" class="opt" :aria-pressed="form.option === i" @click="form.option = i">
                                <b>{{ o.label }}</b><small>{{ o.price ? amd(o.price) : $t('club.free') }}</small>
                            </button>
                        </div>
                    </div>
                    <div v-if="err" class="err">{{ err }}</div>
                    <button class="btn block" :disabled="form.processing">{{ $t('club.register') }}</button>
                    <p class="faint" style="font-size: 12.5px; margin: 0">{{ $t('club.register_note') }}</p>
                </form>
                <Link class="btn ghost sm" style="margin-top: 14px" :href="route('places.show', tournament.venue.slug)">{{ tournament.venue.name }}</Link>
            </div>
        </div>
        <!-- the bracket: one spotlight that follows the tournament live (Option A); the whole bracket full screen -->
        <section v-if="tournament.phase !== 'registration' || tournament.players.length" class="pg wide spot" data-test="bracket">
            <BracketPreview
                :bracket="bracket"
                :count="tournament.count"
                :max-players="tournament.max_players"
                :players="tournament.players"
                :podium="tournament.podium"
                :when="whenText"
                @open="openFull"
            />
        </section>
        <Teleport to="body">
            <div v-if="bigView && bracket" class="tfull" role="dialog" aria-modal="true">
                <div class="tfull-h">
                    <b>{{ tournament.title }}</b>
                    <button type="button" class="tfull-x" :aria-label="$t('common.close')" data-test="bracket-close" @click="closeFull"><Icon name="x" /></button>
                </div>
                <div class="tfull-b">
                    <Bracket :bracket="bracket" :players="tournament.accepted" :title="tournament.title" :when="whenText" embedded />
                </div>
            </div>
        </Teleport>
    </PlayerLayout>
</template>

<style scoped>
.pg { max-width: 560px; margin: 0 auto; padding-top: 18px; }
.pg.wide { max-width: 980px; margin-top: 14px; }
.tfull { position: fixed; inset: 0; z-index: 70; display: flex; flex-direction: column; background: #070908; color: #eef5ec; }
.tfull-h { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; padding-top: max(12px, env(safe-area-inset-top)); border-bottom: 1px solid rgba(255, 159, 67, 0.15); font: 700 15px var(--display); text-transform: uppercase; }
.tfull-x { width: 40px; height: 40px; border-radius: 50%; border: 1px solid rgba(255, 159, 67, 0.3); background: rgba(18, 23, 19, 0.9); color: #eef5ec; display: grid; place-items: center; cursor: pointer; }
.tfull-b { flex: 1; overflow: auto; padding: 14px; -webkit-overflow-scrolling: touch; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 4px 0; }
.chip.hot { color: var(--hot); border-color: var(--hot); }
.phase { display: flex; align-items: center; gap: 8px; padding: 12px 14px; border-radius: 12px; font-weight: 800; background: var(--surface-2); color: var(--muted); }
.phase.drawn { background: rgba(255, 159, 67, 0.12); color: #ff9f43; }
.phase.live { background: var(--hot-soft); color: var(--hot); }
.phase.done { background: var(--free-soft); color: var(--free); }
.pdot { width: 9px; height: 9px; border-radius: 50%; background: var(--hot); animation: pd 1.4s infinite; }
@keyframes pd { 50% { opacity: 0.3; } }
.podium { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
.podium li { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 12px; background: var(--surface-2); }
.podium li span { font-size: 22px; }
.podium .p1 { background: rgba(255, 209, 102, 0.12); }
.ok.applied { background: var(--warn-soft); color: var(--warn); }
.ok.unpaid { background: var(--hot-soft); color: var(--hot); font-weight: 700; }
.ok.declined { background: var(--hot-soft); color: var(--hot); }
.tour { display: flex; flex-direction: column; gap: 8px; }
.tf { display: flex; flex-direction: column; gap: 12px; margin-top: 8px; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.field > span { display: block; font-size: 12.5px; font-weight: 800; color: var(--muted); margin-bottom: 5px; }
.opts { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 8px; }
.opt { border: 1px solid var(--line-2); background: var(--surface-2); color: var(--ink); border-radius: 14px; padding: 10px; text-align: left; display: flex; flex-direction: column; gap: 2px; }
.opt[aria-pressed="true"] { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent); }
.opt small { color: var(--muted); font-weight: 700; }
.ok { display: flex; gap: 8px; align-items: center; padding: 12px 14px; border-radius: 12px; background: var(--free-soft); color: var(--free); font-weight: 800; }
.pre { flex: none; width: auto; }
</style>
