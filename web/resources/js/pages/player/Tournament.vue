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
import { trans } from '@/lib/i18n';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

// A tournament's page: the link the club puts in its Instagram bio. Registration (name, surname, phone, level, a price
// option; no account needed, logged in: filled in) within the rating range and the number of players; my answer and the
// tournament chat once accepted; after the draw the bracket, live (tournament.{code}; guests: every 30 s).
interface Opt {
    label: string;
    price: number;
}
interface Division {
    code: string;
    division: string | null;
    min_rating: number | null;
    max_rating: number | null;
    max_players: number | null;
    phase: 'registration' | 'closed' | 'drawn' | 'live' | 'done' | 'cancelled';
    current: boolean;
    count: number;
    /** my rating is in its range */
    fits: boolean;
    cancel_reason: string | null;
    /** I registered there */
    mine: boolean;
    accepted: number;
    /** my registration there: the club's answer, my place */
    my: { status: 'applied' | 'accepted' | 'declined'; place: number | null } | null;
    /** after the draw: matches on now, the stage, one live score, how far, my next opponent; over: 1st and 2nd */
    live: number;
    stage: { key: string; n?: number } | null;
    liveMatch: { a: string; b: string; score: string | null } | null;
    played: number;
    total: number;
    opponent: string | null;
    podium: { place: number; name: string }[];
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
        phase: 'registration' | 'closed' | 'drawn' | 'live' | 'done' | 'cancelled';
        cancel_reason: string | null;
        venue: { slug: string; name: string };
        count: number;
        accepted: number;
        podium: { place: number; name: string }[];
        players: Player[];
        min_rating: number | null;
        max_rating: number | null;
        max_players: number | null;
        status: string;
        /** divisions by rating: the tournament's page lists them; a division's page has the others as tabs */
        division: string | null;
        parent: { code: string; title: string } | null;
        isParent: boolean;
        divisions: Division[];
        myRating: number | null;
    };
    /** logged in: my name and the account's phone (null: the account has none, then it is asked) */
    me: { first_name: string; last_name: string; phone: string | null; registered: boolean } | null;
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
// "Choose your division" while one is open; afterwards just "Divisions"
const anyOpen = computed(() => props.tournament.divisions.some((d) => d.phase === 'registration'));
const stageText = (st: { key: string; n?: number } | null) => (st ? trans('club.bracket.' + st.key, st.n ? { n: st.n, half: st.n / 2 } : {}) : '');
const rangeOf = (d: { min_rating: number | null; max_rating: number | null }) =>
    d.min_rating == null && d.max_rating == null
        ? ''
        : d.min_rating == null
          ? `≤ ${d.max_rating}`
          : d.max_rating == null
            ? `${d.min_rating}+`
            : `${d.min_rating}–${d.max_rating}`;
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
const form = useForm({ first_name: props.me?.first_name ?? '', last_name: props.me?.last_name ?? '', phone: '', level: '' as string | number, option: 0 });
// registering needs an account: log in (Telegram) and come back here
const loginUrl = computed(() => route('login', { redirect: `/t/${props.tournament.code}` }));
const err = computed(() => (form.errors as Record<string, string>).register);
function send() {
    form.transform((d) => ({ ...d, phone: props.me?.phone ? null : '+374' + String(d.phone).replace(/\D/g, '').slice(-8), level: d.level === '' ? null : Number(d.level) })).post(route('tournament.register', props.tournament.code), {
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
                <Link v-if="tournament.parent" class="up" :href="`/t/${tournament.parent.code}`" data-test="t-parent"
                    ><Icon name="back" size="sm" />{{ tournament.parent.title }}</Link
                >
                <h1 class="page-title" style="margin: 6px 0">{{ tournament.parent ? tournament.division : tournament.title }}</h1>
                <!-- the other divisions of the same tournament -->
                <nav v-if="tournament.parent && tournament.divisions.length > 1" class="dtabs" :aria-label="$t('club.tournament.divisions')">
                    <Link
                        v-for="d in tournament.divisions"
                        :key="d.code"
                        :href="`/t/${d.code}`"
                        :class="['dtab', { on: d.current }]"
                        :aria-current="d.current ? 'page' : undefined"
                        >{{ d.division }}<small v-if="rangeOf(d)">{{ rangeOf(d) }}</small></Link
                    >
                </nav>
                <TournamentPhase :phase="tournament.phase" style="align-self: flex-start" />
                <p class="muted" style="margin: 0"><Icon name="calendar" size="sm" /> {{ dateLong(tournament.date) }} · {{ tournament.time }} · {{ $t('club.t_registered', { n: tournament.count }) }}</p>
                <p v-if="ranged || tournament.max_players" class="chips">
                    <span v-if="ranged" class="chip">{{ $t('club.tournament.rating', { range: rangeOf(tournament) }) }}</span>
                    <span v-if="tournament.max_players" :class="['chip', { hot: full }]">{{
                        full ? $t('club.tournament.full') : $t('club.tournament.players', { n: tournament.count, max: tournament.max_players })
                    }}</span>
                </p>
                <p v-if="tournament.description" style="white-space: pre-line">{{ tournament.description }}</p>

                <!-- a tournament with divisions: pick the one of your rating (each has its own players and bracket) -->
                <div v-if="tournament.isParent" class="divs" data-test="divisions">
                    <b class="divs-h">{{ anyOpen ? $t('club.tournament.pick_division') : $t('club.tournament.divisions') }}</b>
                    <small v-if="tournament.myRating != null && anyOpen" class="muted">{{ $t('club.tournament.my_rating', { n: tournament.myRating }) }}</small>
                    <Link
                        v-for="d in tournament.divisions"
                        :key="d.code"
                        :href="`/t/${d.code}`"
                        :class="['dcard', 'ph-' + d.phase, { fits: d.fits && d.phase === 'registration', mine: d.mine }]"
                        :data-test="`division-${d.code}`"
                    >
                        <span class="dtop">
                            <b>{{ d.division }}</b>
                            <TournamentPhase :phase="d.phase" />
                        </span>
                        <small class="dmeta"
                            >{{ rangeOf(d) ? $t('club.tournament.rating', { range: rangeOf(d) }) : $t('club.tournament.any_rating') }} ·
                            {{
                                d.phase === 'registration' && d.max_players
                                    ? $t('club.tournament.players', { n: d.count, max: d.max_players })
                                    : d.phase === 'registration'
                                      ? $t('club.t_registered', { n: d.count })
                                      : $t('club.bracket.players_n', { n: d.accepted })
                            }}</small
                        >
                        <!-- live: matches on now, the stage, one score, how far it is -->
                        <template v-if="d.phase === 'live'">
                            <span class="dlive"
                                ><i class="pdot" />{{ d.live ? $t('club.tournament.d_live_now', { n: d.live }) : $t('club.tournament.d_on') }}<template v-if="d.stage">
                                    · {{ stageText(d.stage) }}</template
                                ></span
                            >
                            <span v-if="d.liveMatch" class="dscore"
                                ><span>{{ d.liveMatch.a }}</span><b>{{ d.liveMatch.score || 'vs' }}</b><span>{{ d.liveMatch.b }}</span></span
                            >
                            <span class="dprog"><i :style="{ width: (d.total ? (100 * d.played) / d.total : 0) + '%' }" /></span>
                            <small class="dmeta">{{ $t('club.tournament.d_played', { n: d.played, t: d.total }) }}</small>
                        </template>
                        <!-- the draw is made: the bracket is ready (my first opponent) -->
                        <span v-else-if="d.phase === 'drawn'" class="dnote drawn"><Icon name="trophy" size="sm" />{{ $t('club.tournament.d_bracket_ready') }}</span>
                        <span v-else-if="d.phase === 'cancelled'" class="dnote cancelled"><Icon name="x" size="sm" />{{ d.cancel_reason }}</span>
                        <!-- over: the champion and the runner-up -->
                        <span v-else-if="d.phase === 'done' && d.podium.length" class="dpod">
                            <span v-for="p in d.podium" :key="p.place">{{ p.place === 1 ? '🏆' : '🥈' }} {{ p.name }}</span>
                        </span>
                        <!-- me there -->
                        <span v-if="d.my?.place" class="tag mine">{{ $t('club.tournament.my_place', { n: d.my.place }) }}</span>
                        <span v-else-if="d.opponent" class="tag mine"><Icon name="users" size="sm" />{{ $t('club.tournament.d_you_play', { name: d.opponent }) }}</span>
                        <span v-else-if="d.my" :class="['tag', d.my.status === 'accepted' ? 'mine' : d.my.status]"
                            ><Icon :name="d.my.status === 'declined' ? 'x' : d.my.status === 'applied' ? 'clock' : 'check'" size="sm" />{{
                                $t('club.tournament.d_my_' + d.my.status)
                            }}</span
                        >
                        <span v-else-if="d.fits && d.phase === 'registration'" class="tag"><Icon name="star" size="sm" />{{ $t('club.tournament.fits_you') }}</span>
                        <span class="dcta"
                            >{{ $t('club.tournament.d_cta_' + d.phase) }} <Icon name="arrow" size="sm"
                        /></span>
                    </Link>
                </div>

                <!-- my registration: the club's answer, my place, the chat -->
                <div v-if="entry" :class="['ok', entry.status]" data-test="my-entry">
                    <Icon :name="entry.status === 'declined' ? 'x' : 'check'" />{{
                        entry.place ? $t('club.tournament.my_place', { n: entry.place }) : $t('club.tournament.status_' + entry.status)
                    }}
                </div>
                <div v-else-if="done" class="ok"><Icon name="check" />{{ $t('player.tournament.registered') }}</div>
                <div v-if="entry?.unpaid && tournament.phase === 'registration'" class="ok later" data-test="unpaid"><Icon name="wallet" />{{ $t('club.tournament.fee_later', { amount: amd(entry.unpaid) }) }}</div>
                <div v-else-if="entry?.unpaid && tournament.phase !== 'cancelled'" class="ok unpaid" data-test="unpaid"><Icon name="wallet" />{{ $t('club.tournament.unpaid', { amount: amd(entry.unpaid) }) }}</div>
                <Link v-if="chat" class="btn" :href="route('messages.show', chat)" data-test="t-chat"><Icon name="chat" size="sm" />{{ $t('club.tournament.chat') }}</Link>
                <!-- where the tournament is: no form once the draw is made, it started or it is over -->
                <div v-if="tournament.phase !== 'registration'" :class="['phase', tournament.phase]" data-test="phase">
                    <i v-if="tournament.phase === 'live'" class="pdot" />{{
                        tournament.phase === 'cancelled' ? $t('club.tournament.phase_cancelled', { reason: tournament.cancel_reason ?? '' }) : $t('club.tournament.phase_' + tournament.phase)
                    }}
                </div>
                <ol v-if="tournament.podium.length" class="podium" data-test="podium">
                    <li v-for="p in tournament.podium" :key="p.place + p.name" :class="'p' + p.place">
                        <span>{{ p.place === 1 ? '🥇' : p.place === 2 ? '🥈' : '🥉' }}</span><b>{{ p.name }}</b>
                    </li>
                </ol>
                <template v-if="entry || done || tournament.phase !== 'registration'" />
                <p v-else-if="full" class="muted">{{ $t('club.tournament.full') }}</p>
                <!-- a guest: log in first (a Telegram login takes a few seconds), then back here to register -->
                <div v-else-if="tournament.open && !me" class="needlogin" data-test="login-to-register">
                    <b>{{ $t('club.tournament.login_title') }}</b>
                    <small>{{ $t('club.tournament.login_note') }}</small>
                    <Link class="btn block" :href="loginUrl" data-test="login-register"><Icon name="user" size="sm" />{{ $t('club.tournament.login_btn') }}</Link>
                </div>
                <form v-else-if="tournament.open" class="tf" data-test="register-form" @submit.prevent="send">
                    <div class="two">
                        <label class="field"><span>{{ $t('club.first_name') }}</span><input v-model="form.first_name" class="input" required minlength="2" maxlength="60" autocomplete="given-name" /></label>
                        <label class="field"><span>{{ $t('club.last_name') }}</span><input v-model="form.last_name" class="input" required minlength="2" maxlength="60" autocomplete="family-name" /></label>
                    </div>
                    <!-- the account's phone (the club calls it); asked only when the account has none -->
                    <div v-if="me?.phone" class="field">
                        <span>{{ $t('common.phone') }}</span><b class="mono myphone">{{ me.phone }}</b>
                    </div>
                    <label v-else class="field"
                        ><span>{{ $t('common.phone') }}</span>
                        <div class="row" style="gap: 6px"><span class="input pre">+374</span><input v-model="form.phone" class="input mono" inputmode="tel" required placeholder="91 234 567" /></div
                    ></label>
                    <label class="field"
                        ><span>{{ $t('club.level') }}</span
                        ><input v-model="form.level" class="input" inputmode="numeric" :required="ranged" :placeholder="$t('club.level_ph')"
                    /></label>
                    <!-- one price: just the entry fee; older tournaments may still have several options to pick from -->
                    <div v-if="tournament.options.length === 1" class="fee1">
                        <span>{{ $t('club.entry_fee') }}</span><b>{{ tournament.options[0].price ? amd(tournament.options[0].price) : $t('club.free') }}</b>
                    </div>
                    <div v-else class="field">
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
        <section
            v-if="!tournament.isParent && tournament.phase !== 'cancelled' && (tournament.phase !== 'registration' || tournament.players.length)"
            class="pg wide spot"
            data-test="bracket"
        >
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
.fee1 { display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; border-radius: 12px; background: var(--surface-2); font-weight: 700; }
.fee1 span { color: var(--muted); }
.pg { max-width: 560px; margin: 0 auto; padding-top: 18px; }
.pg.wide { max-width: 980px; margin-top: 14px; }
.tfull { position: fixed; inset: 0; z-index: 70; display: flex; flex-direction: column; background: #070908; color: #eef5ec; }
.tfull-h { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; padding-top: max(12px, env(safe-area-inset-top)); border-bottom: 1px solid rgba(255, 159, 67, 0.15); font: 700 15px var(--display); text-transform: uppercase; }
.tfull-x { width: 40px; height: 40px; border-radius: 50%; border: 1px solid rgba(255, 159, 67, 0.3); background: rgba(18, 23, 19, 0.9); color: #eef5ec; display: grid; place-items: center; cursor: pointer; }
.tfull-b { flex: 1; overflow: auto; padding: 14px; -webkit-overflow-scrolling: touch; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 4px 0; }
.chip.hot { color: var(--hot); border-color: var(--hot); }
.needlogin { display: flex; flex-direction: column; gap: 8px; padding: 16px; border-radius: 14px; background: rgba(255, 159, 67, 0.08); border: 1px solid rgba(255, 159, 67, 0.3); }
.needlogin small { color: var(--muted); }
.myphone { display: block; padding: 10px 0 2px; font-size: 15px; }
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
.ok.later { background: var(--surface-2); color: var(--muted); font-weight: 700; }
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
.up { display: inline-flex; align-items: center; gap: 4px; align-self: flex-start; color: var(--muted); font-size: 13px; font-weight: 700; text-decoration: none; }
.dtabs { display: flex; gap: 6px; overflow-x: auto; scrollbar-width: none; margin: 2px 0; }
.dtab { flex: none; display: flex; flex-direction: column; padding: 7px 12px; border-radius: 12px; border: 1px solid var(--line-2); background: var(--surface-2); color: var(--ink); text-decoration: none; font-weight: 800; font-size: 13px; line-height: 1.2; }
.dtab small { color: var(--muted); font: 500 11px var(--mono); }
.dtab.on { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent); }
.divs { display: grid; gap: 8px; margin-top: 4px; }
.divs-h { font-size: 15px; }
.dcard { display: flex; flex-direction: column; gap: 5px; padding: 12px 14px; border-radius: 14px; border: 1px solid var(--line-2); border-left-width: 4px; background: var(--surface-2); color: inherit; text-decoration: none; min-width: 0; }
.dcard.ph-registration { border-left-color: #5ef0a8; }
.dcard.ph-drawn { border-left-color: #ff9f43; }
.dcard.ph-live { border-left-color: #ff6b6b; background: linear-gradient(90deg, rgba(255, 107, 107, 0.08), transparent 60%), var(--surface-2); }
.dcard.ph-done { border-left-color: #ffd166; }
.dcard.ph-closed { border-left-color: #6f7a70; }
.dtop { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.dtop b { font-size: 16px; }
.dmeta { color: var(--muted); font-size: 12.5px; }
.dlive { display: flex; align-items: center; gap: 7px; color: var(--hot); font-weight: 800; font-size: 13px; }
.dscore { display: grid; grid-template-columns: 1fr auto 1fr; gap: 8px; align-items: center; padding: 8px 10px; border-radius: 10px; background: rgba(0, 0, 0, 0.25); font-size: 13px; font-weight: 700; }
.dscore span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dscore span:last-child { text-align: right; }
.dscore b { font: 700 15px var(--mono); color: var(--hot); }
.dprog { height: 5px; border-radius: 3px; background: var(--surface-3); overflow: hidden; }
.dprog i { display: block; height: 100%; background: linear-gradient(90deg, #ff9f43, #ff6b6b); }
.dnote { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 700; }
.dnote.drawn { color: #ff9f43; }
.dnote.cancelled { color: #ff8a8a; }
.dcard.ph-cancelled { border-left-color: #6f7a70; opacity: 0.75; }
.phase.cancelled { background: var(--hot-soft); color: #ff8a8a; }
.dpod { display: flex; flex-wrap: wrap; gap: 6px 14px; font-size: 13.5px; font-weight: 800; }
.dcard { cursor: pointer; transition: transform 0.15s, box-shadow 0.15s, background 0.15s; }
.dcard:hover { transform: translateY(-2px); box-shadow: 0 10px 24px -14px rgba(0, 0, 0, 0.9), 0 0 0 1px var(--accent); background-color: var(--surface-3); }
.dcard:active { transform: scale(0.99); }
.dcard:hover .dcta .ico { transform: translateX(3px); }
.dcta .ico { transition: transform 0.15s; }
.dcta { align-self: flex-end; display: inline-flex; align-items: center; gap: 4px; font-size: 12.5px; font-weight: 800; color: var(--accent); }
.tag.applied { color: var(--warn); }
.tag.declined { color: var(--hot); }
.dcard.fits { border-top-color: color-mix(in srgb, var(--accent) 55%, transparent); border-right-color: color-mix(in srgb, var(--accent) 55%, transparent); border-bottom-color: color-mix(in srgb, var(--accent) 55%, transparent); }
.dcard.mine { border-top-color: var(--free); border-right-color: var(--free); border-bottom-color: var(--free); }
.tag { display: inline-flex; align-items: center; gap: 4px; align-self: flex-start; margin-top: 3px; font-size: 11.5px; font-weight: 800; color: var(--accent); }
.tag.mine { color: var(--free); }
</style>
