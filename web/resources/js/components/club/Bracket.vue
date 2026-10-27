<script setup lang="ts">
import BracketHalves from '@/components/club/BracketHalves.vue';
import BracketTree from '@/components/club/BracketTree.vue';
import Icon from '@/components/Icon.vue';
import { trans } from '@/lib/i18n';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// A tournament bracket, "arena" style (design B). The last 16 players (1/8 → the Grand Final) are the glowing tree:
// two halves meeting at the final; a tap opens it full screen. A bigger tournament (17–128 players) shows its early
// rounds above it as round tabs with 10 match cards per page, so a 100-player bracket stays light.
// editable: the owner taps a match to mark the winner (the page opens its sheet). Players only look.
export interface Side {
    id: number;
    name: string;
    first?: string;
    last?: string;
    rating: number | null;
    me?: boolean;
}
export interface Match {
    id: number;
    slot: number;
    a: Side | null;
    b: Side | null;
    winner: number | null;
    score: string | null;
    live: boolean;
    bye: boolean;
    /** when the result came (seconds) */
    at?: number | null;
}
export interface Round {
    round: number;
    size: number;
    matches: Match[];
}
export interface BracketData {
    total: number;
    rounds: Round[];
}
// embedded: already inside a full-screen layer (the tournament page): no own "Full screen"
const props = defineProps<{ bracket: BracketData; editable?: boolean; title?: string; when?: string; players?: number; embedded?: boolean }>();
const emit = defineEmits<{ pick: [m: Match] }>();

/** the tree: at most the last 4 rounds (16 players) */
const TREE_ROUNDS = 4;
const tree = computed(() => props.bracket.rounds.slice(-TREE_ROUNDS));
const early = computed(() => props.bracket.rounds.slice(0, -TREE_ROUNDS));

// ---------- early rounds: tabs, 10 cards a page ----------
const PAGE = 10;
const current = computed(() => early.value.find((r) => r.matches.some((m) => m.a && m.b && !m.winner))?.round ?? early.value[0]?.round ?? 0);
const shown = ref<number | null>(null);
const round = computed(() => early.value.find((r) => r.round === (shown.value ?? current.value)) ?? early.value[0]);
const page = ref(0);
const real = computed(() => round.value?.matches.filter((m) => !m.bye) ?? []);
const pages = computed(() => Math.max(1, Math.ceil(real.value.length / PAGE)));
const onPage = computed(() => real.value.slice(page.value * PAGE, (page.value + 1) * PAGE));
const byes = computed(() => round.value?.matches.filter((m) => m.bye).length ?? 0);
// a new round tab: its first page (not when the same round only got a new result)
watch(() => round.value?.round, () => (page.value = 0));
watch(pages, (n) => page.value >= n && (page.value = n - 1));
const tabName = (size: number) => `1/${size / 2}`;
const done = (r: Round) => r.matches.every((m) => m.winner || m.bye);
const live = (r: Round) => r.matches.some((m) => m.live);
const roundName = (size: number) => trans('club.bracket.round_of', { n: size, half: size / 2 });
const state = (m: Match, s: Side | null) => (!s ? 'tbd' : m.winner ? (m.winner === s.id ? 'win' : 'lose') : '');
function scoreOf(m: Match, s: Side | null): string {
    if (!s || !m.score || !m.winner) return '';
    const p = /^\s*(\d+)\s*\D+\s*(\d+)\s*$/.exec(m.score);
    if (!p) return s.id === m.winner ? m.score : '';
    return String(s.id === m.winner ? Math.max(+p[1], +p[2]) : Math.min(+p[1], +p[2]));
}
const canPick = (m: Match) => !!props.editable && !!m.a && !!m.b;

// phones: the half-by-half view (the tree stays for computers and full screen)
const narrow = ref(typeof window !== 'undefined' && window.innerWidth < 760);
const onResize = () => (narrow.value = window.innerWidth < 760);

// ---------- the tree: scroll fade + "swipe →", full screen ----------
const scroller = ref<HTMLElement>();
const ov = ref(false);
const end = ref(false);
function onScroll() {
    const s = scroller.value;
    if (!s) return;
    ov.value = s.scrollWidth > s.clientWidth + 4;
    end.value = s.scrollLeft + s.clientWidth >= s.scrollWidth - 4;
}
let ro: ResizeObserver | undefined;
onMounted(() => {
    window.addEventListener('resize', onResize);
    nextTick(onScroll);
    ro = new ResizeObserver(onScroll);
    if (scroller.value) ro.observe(scroller.value);
});
const large = ref(false);
function openLarge() {
    large.value = true;
    document.body.style.overflow = 'hidden';
}
function closeLarge() {
    large.value = false;
    document.body.style.overflow = '';
}
onBeforeUnmount(() => {
    window.removeEventListener('resize', onResize);
    ro?.disconnect();
    if (large.value) document.body.style.overflow = '';
});
const pick = (m: Match) => emit('pick', m);
// the owner edits a match in full screen: it stays open (the sheet comes over it)
const pickLarge = (m: Match) => pick(m);
// players tap the tree to see it large; the owner taps a match (and has the full screen chip)
const tapTree = () => !props.editable && !props.embedded && openLarge();
</script>

<template>
    <div class="stage">
        <div class="tour">
            <div class="tt">{{ title }}</div>
            <div class="chips">
                <span v-if="players" class="chip">{{ $t('club.bracket.players_n', { n: players }) }}</span>
                <span v-if="when" class="chip">{{ when }}</span>
                <button v-if="!embedded" type="button" class="chip big" data-test="bracket-large" @click="openLarge"><Icon name="grid" size="sm" />{{ $t('club.bracket.open_large') }}</button>
            </div>
        </div>

        <!-- 17+ players: the early rounds, one round at a time, 10 matches a page -->
        <section v-if="early.length && round" class="early">
            <div class="ch">{{ $t('club.bracket.early') }}</div>
            <div class="rtabs" role="tablist">
                <button v-for="r in early" :key="r.round" type="button" role="tab" :aria-selected="r.round === round.round" :class="{ done: done(r) }" @click="shown = r.round">
                    {{ tabName(r.size) }}<i v-if="live(r)" class="dot" />
                </button>
            </div>
            <div class="sub">
                {{ roundName(round.size) }}<template v-if="pages > 1"> · {{ $t('club.bracket.page', { n: page + 1, total: pages }) }}</template>
            </div>
            <div class="grid">
                <component
                    :is="canPick(m) ? 'button' : 'div'"
                    v-for="m in onPage"
                    :key="m.id"
                    :type="canPick(m) ? 'button' : undefined"
                    :class="['em', { live: m.live, pick: canPick(m) }]"
                    :data-test="`match-${m.id}`"
                    @click="canPick(m) && pick(m)"
                >
                    <div v-for="(s, j) in [m.a, m.b]" :key="j" :class="['er', state(m, s), { me: s?.me }]">
                        <span class="nm">{{ s ? s.name : $t('club.bracket.tbd') }}</span>
                        <span v-if="s?.rating" class="rt">{{ s.rating }}</span>
                        <b class="sc">{{ scoreOf(m, s) }}</b>
                    </div>
                    <div v-if="m.live" class="livetag"><i class="dot" />LIVE</div>
                </component>
            </div>
            <div v-if="pages > 1" class="pager">
                <button type="button" :disabled="page === 0" aria-label="‹" @click="page--">‹</button>
                <button v-for="i in pages" :key="i" type="button" :aria-current="page === i - 1" @click="page = i - 1">{{ i }}</button>
                <button type="button" :disabled="page === pages - 1" aria-label="›" @click="page++">›</button>
            </div>
            <p v-if="byes" class="byes">{{ $t('club.bracket.byes', { n: byes }) }}</p>
            <div class="ch" style="margin-top: 18px">{{ $t('club.bracket.final_stage') }}</div>
        </section>

        <BracketHalves v-if="narrow" :rounds="tree" :total="bracket.total" :editable="editable" :when="when" @pick="pick" />
        <div v-else :class="['scwrap', { ov, end }]">
            <div ref="scroller" class="scroller" @scroll.passive="onScroll" @click="tapTree">
                <BracketTree :rounds="tree" :total="bracket.total" :editable="editable" :when="when" @pick="pick" />
            </div>
        </div>
        <div v-if="ov && !narrow" class="hint">{{ $t('club.bracket.swipe') }} <b>→</b></div>

        <!-- full screen -->
        <Teleport to="body">
            <div v-if="large" class="bk-full" role="dialog" aria-modal="true">
                <div class="bk-full-h">
                    <b>{{ title }}</b>
                    <button type="button" class="bk-x" :aria-label="$t('common.close')" data-test="bracket-close" @click="closeLarge"><Icon name="x" /></button>
                </div>
                <div class="bk-full-b">
                    <BracketTree :rounds="tree" :total="bracket.total" :editable="editable" :when="when" large @pick="pickLarge" />
                </div>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
/* the stage of design B: a night stadium (glow in the middle, a fading grid) */
.stage {
    position: relative;
    overflow: hidden;
    border-radius: 22px;
    padding: 18px;
    color: #eef5ec;
    border: 1px solid rgba(255, 159, 67, 0.2);
    background:
        radial-gradient(ellipse 45% 55% at 50% 50%, rgba(255, 159, 67, 0.16), transparent 70%),
        radial-gradient(ellipse 35% 60% at 0% 50%, rgba(94, 240, 168, 0.06), transparent 70%),
        radial-gradient(ellipse 35% 60% at 100% 50%, rgba(94, 240, 168, 0.06), transparent 70%),
        #070908;
}
.stage::before {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    background-image: linear-gradient(rgba(220, 255, 220, 0.035) 1px, transparent 1px), linear-gradient(90deg, rgba(220, 255, 220, 0.035) 1px, transparent 1px);
    background-size: 36px 36px;
    -webkit-mask-image: radial-gradient(ellipse at center, #000 30%, transparent 80%);
    mask-image: radial-gradient(ellipse at center, #000 30%, transparent 80%);
}
.stage > * { position: relative; }
@media (max-width: 600px) { .stage { padding: 14px 12px; border-radius: 18px; } }
.tour { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 8px 16px; margin-bottom: 12px; }
.tt { font: 700 16px var(--display); text-transform: uppercase; text-shadow: 0 0 18px rgba(255, 159, 67, 0.45); }
.chips { display: flex; flex-wrap: wrap; gap: 6px; }
.chip { font: 500 11px var(--mono); color: #9aa69b; padding: 4px 9px; border-radius: 999px; border: 1px solid rgba(220, 255, 220, 0.08); background: rgba(255, 255, 255, 0.02); display: inline-flex; align-items: center; gap: 5px; }
.chip.big { cursor: pointer; color: #ffd166; border-color: rgba(255, 159, 67, 0.35); }

/* the tree scrolls sideways in its own box, with a fade and "swipe →" */
.scwrap { position: relative; }
.scroller { overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; overscroll-behavior-x: contain; margin: 0 -18px; padding: 6px 18px 12px; scrollbar-width: thin; scrollbar-color: #2a332b transparent; cursor: zoom-in; }
@media (max-width: 600px) { .scroller { margin: 0 -12px; padding: 6px 12px 12px; } }
.scwrap::after { content: ''; position: absolute; top: 0; bottom: 0; right: -18px; width: 46px; pointer-events: none; background: linear-gradient(90deg, transparent, #070908); opacity: 0; transition: opacity 0.2s; }
.scwrap.ov:not(.end)::after { opacity: 1; }
.hint { display: inline-flex; align-items: center; gap: 6px; font: 600 12px var(--body); color: #ff9f43; margin: 2px 0 0; }
.hint b { display: inline-block; animation: nudge 1.4s ease-in-out infinite; }
@keyframes nudge { 50% { transform: translateX(5px); } }

/* early rounds: light cards (no glow), paged */
.ch { font: 600 10px var(--display); letter-spacing: 0.14em; text-transform: uppercase; color: rgba(255, 209, 102, 0.55); margin: 0 0 8px; }
.rtabs { display: flex; gap: 6px; overflow-x: auto; padding-bottom: 8px; scrollbar-width: none; }
.rtabs button { flex: none; display: inline-flex; align-items: center; gap: 6px; border: 1px solid rgba(255, 159, 67, 0.25); background: rgba(18, 23, 19, 0.88); color: #9aa69b; font: 700 12px var(--display); padding: 7px 13px; border-radius: 999px; cursor: pointer; }
.rtabs button.done { color: #5ef0a8; border-color: rgba(94, 240, 168, 0.35); }
.rtabs button[aria-selected='true'] { color: #1a1206; background: linear-gradient(135deg, #ffd166 0%, #ff8a3d 100%); border-color: transparent; }
.sub { font: 500 11px var(--mono); color: #657066; margin: 2px 0 8px; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 8px; }
.em { display: block; width: 100%; font: inherit; color: #eef5ec; text-align: left; background: rgba(18, 23, 19, 0.88); border: 1px solid rgba(255, 159, 67, 0.16); border-radius: 14px; padding: 4px; }
.em.pick { cursor: pointer; }
.em.pick:hover { border-color: rgba(255, 159, 67, 0.6); }
.em.live { border-color: rgba(255, 107, 107, 0.55); }
.er { display: flex; align-items: center; gap: 8px; padding: 5px 8px; border-radius: 10px; font-size: 12.5px; }
.er .nm { flex: 1; min-width: 0; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.er .rt { font: 400 10px var(--mono); color: #657066; }
.er .sc { width: 16px; text-align: center; font: 600 13px var(--mono); color: #9aa69b; }
.er.win { background: rgba(94, 240, 168, 0.08); }
.er.win .sc { color: #5ef0a8; }
.er.lose { opacity: 0.4; }
.er.tbd .nm { color: #657066; font-style: italic; font-weight: 500; }
.er.me .nm::after { content: ' ●'; color: #5ef0a8; font-size: 9px; }
.livetag { color: #ff6b6b; display: inline-flex; align-items: center; gap: 6px; font: 600 10px var(--mono); padding: 2px 8px 3px; }
.dot { width: 7px; height: 7px; border-radius: 50%; background: #ff6b6b; display: inline-block; flex: none; }
.pager { display: flex; flex-wrap: wrap; gap: 4px; justify-content: center; margin-top: 10px; }
.pager button { min-width: 32px; height: 32px; border-radius: 10px; border: 1px solid rgba(255, 159, 67, 0.2); background: rgba(18, 23, 19, 0.88); color: #9aa69b; font: 600 12px var(--mono); cursor: pointer; }
.pager button[aria-current='true'] { color: #1a1206; background: #ff9f43; border-color: transparent; }
.pager button:disabled { opacity: 0.35; cursor: default; }
.byes { font-size: 12px; color: #657066; margin: 8px 0 0; }

/* full screen */
.bk-full { position: fixed; inset: 0; z-index: 70; /* over the tab bar, under the owner's sheet (80) */ display: flex; flex-direction: column; color: #eef5ec;
    background: radial-gradient(ellipse 45% 55% at 50% 50%, rgba(255, 159, 67, 0.14), transparent 70%), #070908; }
.bk-full-h { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; padding-top: max(12px, env(safe-area-inset-top)); border-bottom: 1px solid rgba(255, 159, 67, 0.15); font: 700 15px var(--display); text-transform: uppercase; }
.bk-x { width: 40px; height: 40px; border-radius: 50%; border: 1px solid rgba(255, 159, 67, 0.3); background: rgba(18, 23, 19, 0.9); color: #eef5ec; display: grid; place-items: center; cursor: pointer; }
.bk-full-b { flex: 1; overflow: auto; padding: 18px; -webkit-overflow-scrolling: touch; }
</style>
