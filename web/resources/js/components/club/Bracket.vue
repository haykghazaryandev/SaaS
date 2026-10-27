<script setup lang="ts">
import { trans } from '@/lib/i18n';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// A tournament bracket, "arena" style: two halves that meet in the middle at the Grand Final (a World Cup scheme),
// glowing curved wires between the rounds, winners lit green, losers greyed out, a live match pulsing red.
// Any size: the draw rounds the players up to 4, 8, 16 … 128 (byes for the best seeds), so the first round of each
// half has size / 4 matches; columns stretch to the same height so every match sits between the two it comes from.
// editable: the owner taps a match to mark the winner (the page opens its sheet). Players only look.
export interface Side {
    id: number;
    name: string;
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
}
export interface BracketData {
    total: number;
    rounds: { round: number; size: number; matches: Match[] }[];
}
const props = defineProps<{ bracket: BracketData; editable?: boolean; title?: string; when?: string }>();
const emit = defineEmits<{ pick: [m: Match] }>();

const roundName = (size: number) =>
    size === 2 ? trans('club.bracket.final') : size === 4 ? trans('club.bracket.semi') : size === 8 ? trans('club.bracket.quarter') : trans('club.bracket.round_of', { n: size, half: size / 2 });

type Col = { round: number; size: number; side: 'L' | 'R'; matches: Match[] };
// columns: the left half (top of every round) rounds 1…R-1, the final, the right half R-1…1 (mirrored)
const cols = computed(() => {
    const rs = props.bracket.rounds;
    const half = (r: (typeof rs)[number], side: 'L' | 'R'): Col => ({ round: r.round, size: r.size, side, matches: r.matches.filter((m) => (m.slot < r.matches.length / 2) === (side === 'L')) });
    const inner = rs.slice(0, -1);
    return { left: inner.map((r) => half(r, 'L')), right: inner.map((r) => half(r, 'R')).reverse(), final: rs[rs.length - 1]?.matches[0] ?? null };
});
const finalMatch = computed(() => cols.value.final);

// ---------- phones, big brackets (16+ players): one round at a time, the tree on request ----------
const narrow = ref(typeof window !== 'undefined' && window.innerWidth < 760);
const onResize = () => (narrow.value = window.innerWidth < 760);
const big = computed(() => props.bracket.total >= 4);
const treeOnPhone = ref(false);
const byRound = computed(() => narrow.value && big.value && !treeOnPhone.value);
// the round being played: the first with a real match not decided yet (else the final)
const current = computed(() => props.bracket.rounds.find((r) => r.matches.some((m) => m.a && m.b && !m.winner))?.round ?? props.bracket.total);
const shownRound = ref<number | null>(null);
const roundNow = computed(() => props.bracket.rounds.find((r) => r.round === (shownRound.value ?? current.value)) ?? props.bracket.rounds[0]);
const shortName = (size: number) => (size === 2 ? trans('club.bracket.final') : size === 4 ? '1/2' : size === 8 ? '1/4' : `1/${size / 2}`);
const doneIn = (r: (typeof props.bracket.rounds)[number]) => r.matches.every((m) => m.winner || m.bye);
const liveIn = (r: (typeof props.bracket.rounds)[number]) => r.matches.some((m) => m.live);
// n columns per side + the final (2 players: only the final)
const gridCols = computed(() => {
    const n = cols.value.left.length;
    const side = n ? `repeat(${n}, minmax(128px, 1fr))` : '';
    return [side, 'minmax(190px, 1.1fr)', side].filter(Boolean).join(' ');
});
const champion = computed(() => {
    const f = finalMatch.value;
    return f?.winner ? (f.a?.id === f.winner ? f.a : f.b) : null;
});
// everybody who lost a match: their wires stop glowing
const out = computed(() => {
    const s = new Set<number>();
    for (const r of props.bracket.rounds) for (const m of r.matches) if (m.winner && m.a && m.b) s.add(m.winner === m.a.id ? m.b.id : m.a.id);
    return s;
});

const initials = (n: string) =>
    n
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]!.toUpperCase())
        .join('');
/** "Aram Petrosyan" → "A. Petrosyan" for the narrow tree cards (the full name on hover, the final and the round list) */
const short = (n: string) => {
    const w = n.trim().split(/\s+/);
    return w.length > 1 ? `${w[0]![0]}. ${w.slice(1).join(' ')}` : n;
};
const hue = (id: number) => (id * 47) % 360;
/** "3:1" → the winner's number on the winner's line (whichever way it was typed) */
function scoreOf(m: Match, s: Side | null): string {
    if (!m.score || !s || !m.winner) return '';
    const p = /^\s*(\d+)\s*\D+\s*(\d+)\s*$/.exec(m.score);
    if (!p) return s.id === m.winner ? m.score : '';
    const [hi, lo] = [Math.max(+p[1], +p[2]), Math.min(+p[1], +p[2])];
    return String(s.id === m.winner ? hi : lo);
}
const state = (m: Match, s: Side | null) => (!s ? 'tbd' : m.winner ? (m.winner === s.id ? 'win' : 'lose') : '');
const canPick = (m: Match | null) => !!m && !!props.editable && !!m.a && !!m.b;
const pick = (m: Match | null) => m && canPick(m) && emit('pick', m);
const emptyName = (m: Match) => (m.bye ? '—' : trans('club.bracket.tbd'));

// ---------- the wires: from each match to the one its winner goes to, measured on the page ----------
const root = ref<HTMLElement>();
const paths = ref<{ d: string; cls: string }[]>([]);
const rounds = computed(() => props.bracket.total);
function wire() {
    const tree = root.value;
    if (!tree) return;
    const tb = tree.getBoundingClientRect();
    if (!tb.width) return;
    const next: { d: string; cls: string }[] = [];
    for (const r of props.bracket.rounds) {
        if (r.round >= rounds.value) continue;
        for (const m of r.matches) {
            const a = tree.querySelector(`[data-m="${r.round}-${m.slot}"] .anc`);
            const b = tree.querySelector(`[data-m="${r.round + 1}-${m.slot >> 1}"] .anc`);
            if (!a || !b) continue;
            const left = m.slot < r.matches.length / 2;
            const ra = a.getBoundingClientRect();
            const rb = b.getBoundingClientRect();
            const x1 = (left ? ra.right : ra.left) - tb.left;
            const y1 = ra.top + ra.height / 2 - tb.top;
            const x2 = (left ? rb.left : rb.right) - tb.left;
            const y2 = rb.top + rb.height / 2 - tb.top;
            const mx = (x1 + x2) / 2;
            const d = `M${x1} ${y1}C${mx} ${y1} ${mx} ${y2} ${x2} ${y2}`;
            next.push({ d, cls: !m.winner ? 'off' : out.value.has(m.winner) ? 'on' : 'on alive' });
        }
    }
    // the dim ones first, the glowing ones on top
    paths.value = next.sort((x, y) => (x.cls === 'off' ? 0 : 1) - (y.cls === 'off' ? 0 : 1));
}
let ro: ResizeObserver | undefined;
onMounted(() => {
    window.addEventListener('resize', onResize);
    nextTick(wire);
    ro = new ResizeObserver(() => wire());
    if (root.value) ro.observe(root.value);
    document.fonts?.ready.then(wire);
});
onBeforeUnmount(() => {
    ro?.disconnect();
    window.removeEventListener('resize', onResize);
});
watch(byRound, (v) => !v && nextTick(wire));
watch(() => props.bracket, () => nextTick(wire), { deep: true });
</script>

<template>
    <div class="arena">
        <div v-if="title" class="ar-h">
            <b>{{ title }}</b>
        </div>
        <!-- phones, 16+ players: one round at a time -->
        <template v-if="byRound">
            <div class="rtabs" role="tablist">
                <button
                    v-for="r in bracket.rounds"
                    :key="r.round"
                    type="button"
                    role="tab"
                    :aria-selected="r.round === roundNow.round"
                    :class="{ done: doneIn(r) }"
                    @click="shownRound = r.round"
                >
                    {{ shortName(r.size) }}<i v-if="liveIn(r)" class="dot" />
                </button>
            </div>
            <div class="rlist">
                <div class="ch">{{ roundName(roundNow.size) }}</div>
                <component
                    :is="canPick(m) ? 'button' : 'div'"
                    v-for="m in roundNow.matches.filter((x) => !x.bye)"
                    :key="m.id"
                    :type="canPick(m) ? 'button' : undefined"
                    :class="['mb', { live: m.live, pick: canPick(m) }]"
                    :data-test="`match-${m.id}`"
                    @click="pick(m)"
                >
                    <div v-for="(s, j) in [m.a, m.b]" :key="j" :class="['pl', state(m, s), { me: s?.me }]">
                        <span v-if="s" class="av" :style="{ '--h': hue(s.id) }">{{ initials(s.name) }}</span><span v-else class="av q">?</span>
                        <span class="who"
                            ><span class="nm">{{ s ? s.name : $t('club.bracket.tbd') }}</span><span v-if="s?.rating" class="rt">{{ s.rating }}</span></span
                        >
                        <b class="sc">{{ scoreOf(m, s) }}</b>
                    </div>
                    <div v-if="m.live" class="lv"><i class="dot" />{{ $t('club.bracket.live') }}</div>
                </component>
                <p v-if="roundNow.matches.some((x) => x.bye)" class="byes">
                    {{ $t('club.bracket.byes', { n: roundNow.matches.filter((x) => x.bye).length }) }}
                </p>
                <div v-if="champion && roundNow.round === bracket.total" class="fstat champ-row">
                    🥇 {{ $t('club.bracket.champion') }} <b>{{ champion.name }}</b>
                </div>
            </div>
            <button type="button" class="tree-btn" @click="treeOnPhone = true">{{ $t('club.bracket.show_tree') }}</button>
        </template>
        <button v-else-if="narrow && big" type="button" class="tree-btn" @click="treeOnPhone = false">{{ $t('club.bracket.by_round') }}</button>
        <p v-if="!byRound" class="ar-swipe">{{ $t('club.bracket.swipe') }}</p>
        <div v-if="!byRound" class="ar-scroll">
            <div ref="root" class="tree" :style="{ gridTemplateColumns: gridCols, minWidth: `${cols.left.length * 2 * (128 + 12) + 190}px` }">
                <svg class="wires" aria-hidden="true"><path v-for="(p, i) in paths" :key="i" :class="p.cls" :d="p.d" /></svg>

                <div v-for="c in cols.left" :key="`L${c.round}`" class="col">
                    <div class="ch">{{ roundName(c.size) }}</div>
                    <div class="cb">
                        <div v-for="m in c.matches" :key="m.id" class="slot">
                            <component
                                :is="canPick(m) ? 'button' : 'div'"
                                :type="canPick(m) ? 'button' : undefined"
                                :class="['mb', { live: m.live, pick: canPick(m), bye: m.bye }]"
                                :data-m="`${c.round}-${m.slot}`"
                                :data-test="`match-${m.id}`"
                                @click="pick(m)"
                            >
                                <div class="anc">
                                    <div v-for="(s, j) in [m.a, m.b]" :key="j" :class="['pl', state(m, s), { me: s?.me }]">
                                        <span v-if="s" class="av" :style="{ '--h': hue(s.id) }">{{ initials(s.name) }}</span><span v-else class="av q">?</span>
                                        <span class="who"
                                            ><span class="nm" :title="s?.name">{{ s ? short(s.name) : emptyName(m) }}</span
                                            ><span v-if="s?.rating" class="rt">{{ s.rating }}</span></span
                                        >
                                        <b class="sc">{{ scoreOf(m, s) }}</b>
                                    </div>
                                </div>
                                <div v-if="m.live" class="lv"><i class="dot" />{{ $t('club.bracket.live') }}</div>
                            </component>
                        </div>
                    </div>
                </div>

                <!-- the Grand Final in the middle -->
                <div class="col fin">
                    <div class="ch">{{ roundName(2) }}</div>
                    <div class="cb">
                        <div class="slot">
                            <component
                                :is="canPick(finalMatch) ? 'button' : 'div'"
                                v-if="finalMatch"
                                :type="canPick(finalMatch) ? 'button' : undefined"
                                :class="['fb', { live: finalMatch.live, pick: canPick(finalMatch) }]"
                                :data-m="`${bracket.total}-0`"
                                :data-test="`match-${finalMatch.id}`"
                                @click="pick(finalMatch)"
                            >
                                <div class="cup" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0Z" />
                                        <path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3" />
                                    </svg>
                                </div>
                                <h4>{{ $t('club.bracket.grand_final') }}</h4>
                                <div v-if="when" class="when">{{ when }}</div>
                                <div class="anc vs">
                                    <template v-for="(s, j) in [finalMatch.a, finalMatch.b]" :key="j">
                                        <span v-if="j === 1" class="vsb">VS</span>
                                        <div :class="['fp', state(finalMatch, s), { me: s?.me }]">
                                            <span v-if="s" class="av" :style="{ '--h': hue(s.id) }">{{ initials(s.name) }}</span><span v-else class="av q">?</span>
                                            <div class="nm">{{ s ? s.name : $t('club.bracket.tbd') }}</div>
                                            <div v-if="s?.rating" class="rt">{{ s.rating }}</div>
                                            <span class="sc">{{ scoreOf(finalMatch, s) || (finalMatch.winner ? '' : '–') }}</span>
                                        </div>
                                    </template>
                                </div>
                                <div class="fstat">
                                    <span v-if="champion" class="champ">🥇 {{ $t('club.bracket.champion') }} <b>{{ champion.name }}</b></span>
                                    <span v-else-if="finalMatch.live" class="livetag"><i class="dot" />{{ $t('club.bracket.live') }}</span>
                                </div>
                            </component>
                        </div>
                    </div>
                </div>

                <div v-for="c in cols.right" :key="`R${c.round}`" class="col right">
                    <div class="ch">{{ roundName(c.size) }}</div>
                    <div class="cb">
                        <div v-for="m in c.matches" :key="m.id" class="slot">
                            <component
                                :is="canPick(m) ? 'button' : 'div'"
                                :type="canPick(m) ? 'button' : undefined"
                                :class="['mb', { live: m.live, pick: canPick(m), bye: m.bye }]"
                                :data-m="`${c.round}-${m.slot}`"
                                :data-test="`match-${m.id}`"
                                @click="pick(m)"
                            >
                                <div class="anc">
                                    <div v-for="(s, j) in [m.a, m.b]" :key="j" :class="['pl', state(m, s), { me: s?.me }]">
                                        <span v-if="s" class="av" :style="{ '--h': hue(s.id) }">{{ initials(s.name) }}</span><span v-else class="av q">?</span>
                                        <span class="who"
                                            ><span class="nm" :title="s?.name">{{ s ? short(s.name) : emptyName(m) }}</span
                                            ><span v-if="s?.rating" class="rt">{{ s.rating }}</span></span
                                        >
                                        <b class="sc">{{ scoreOf(m, s) }}</b>
                                    </div>
                                </div>
                                <div v-if="m.live" class="lv"><i class="dot" />{{ $t('club.bracket.live') }}</div>
                            </component>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.arena {
    --win: #5ef0a8;
    --live: #ff6b6b;
    --gold: #ff9f43;
    --grad-gold: linear-gradient(135deg, #ffd166 0%, #ff8a3d 100%);
    position: relative;
    border-radius: 20px;
    border: 1px solid rgba(255, 159, 67, 0.2);
    padding: 16px 12px;
    overflow: hidden;
    background:
        radial-gradient(ellipse 45% 55% at 50% 50%, rgba(255, 159, 67, 0.16), transparent 70%),
        radial-gradient(ellipse 35% 60% at 0% 50%, rgba(94, 240, 168, 0.06), transparent 70%),
        radial-gradient(ellipse 35% 60% at 100% 50%, rgba(94, 240, 168, 0.06), transparent 70%),
        #070908;
    color: #eef5ec;
}
/* the stadium grid, fading out to the edges */
.arena::before {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    background-image: linear-gradient(rgba(220, 255, 220, 0.035) 1px, transparent 1px), linear-gradient(90deg, rgba(220, 255, 220, 0.035) 1px, transparent 1px);
    background-size: 36px 36px;
    -webkit-mask-image: radial-gradient(ellipse at center, #000 30%, transparent 80%);
    mask-image: radial-gradient(ellipse at center, #000 30%, transparent 80%);
}
.arena > * { position: relative; }
.ar-h { margin: 0 4px 10px; font: 800 15px var(--display); letter-spacing: 0.06em; text-transform: uppercase; text-shadow: 0 0 18px rgba(255, 159, 67, 0.45); }
.ar-swipe { display: none; font-size: 12px; color: #657066; margin: 0 4px 8px; }
.ar-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; padding-bottom: 4px; }
@media (max-width: 900px) { .ar-swipe { display: block; } }

/* columns: n per side + the final; every column as tall as the tallest, so matches line up between their two */
.tree { position: relative; display: grid; column-gap: 12px; width: 100%; }
.col { display: flex; flex-direction: column; min-width: 0; position: relative; z-index: 1; }
.ch { text-align: center; font: 600 10px var(--display); letter-spacing: 0.14em; text-transform: uppercase; color: rgba(255, 209, 102, 0.55); text-shadow: 0 0 10px rgba(255, 159, 67, 0.35); padding: 2px 0 8px; white-space: nowrap; }
.cb { flex: 1; display: flex; flex-direction: column; }
.slot { flex: 1; display: flex; align-items: center; padding: 6px 0; }
.slot > * { width: 100%; }

.wires { position: absolute; inset: 0; width: 100%; height: 100%; z-index: 0; pointer-events: none; overflow: visible; }
.wires path { fill: none; stroke-width: 2; stroke-linecap: round; }
.wires .off { stroke: rgba(255, 159, 67, 0.14); stroke-dasharray: 3 5; }
.wires .on { stroke: rgba(94, 240, 168, 0.38); }
.wires .alive { stroke: #ffb36b; stroke-width: 3; filter: drop-shadow(0 0 3px #ff9f43) drop-shadow(0 0 10px rgba(255, 138, 61, 0.9)); }

/* a match */
.mb { display: block; width: 100%; text-align: left; font: inherit; color: inherit; background: rgba(18, 23, 19, 0.88); border: 1px solid rgba(255, 159, 67, 0.16); border-radius: 16px; padding: 5px; box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4); }
.mb.pick { cursor: pointer; transition: border-color 0.15s, transform 0.15s; }
.mb.pick:hover { border-color: rgba(255, 159, 67, 0.6); }
.mb.pick:active { transform: scale(0.98); }
.mb.live { border-color: rgba(255, 107, 107, 0.55); box-shadow: 0 0 22px rgba(255, 107, 107, 0.22); }
.mb.bye { opacity: 0.55; }
.pl { display: flex; align-items: center; gap: 6px; padding: 5px; border-radius: 11px; }
.right .pl { flex-direction: row-reverse; }
.right .who { text-align: right; }
.av { width: 22px; height: 22px; border-radius: 50%; display: grid; place-items: center; font: 700 10px var(--display); flex: none; background: hsl(var(--h) 38% 20%); color: hsl(var(--h) 85% 80%); border: 1.5px solid hsl(var(--h) 45% 38%); }
.av.q { background: transparent; border: 1.5px dashed #657066; color: #657066; }
.who { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.nm { font-size: 12.5px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rt { font: 400 10px var(--mono); color: #657066; }
.sc { min-width: 16px; text-align: center; font: 600 14px var(--mono); color: #9aa69b; }
.pl.win { background: rgba(94, 240, 168, 0.08); }
.pl.win .nm { font-weight: 800; }
.pl.win .av { border-color: var(--win); box-shadow: 0 0 12px rgba(94, 240, 168, 0.6); }
.pl.win .sc { color: var(--win); text-shadow: 0 0 10px rgba(94, 240, 168, 0.7); }
.pl.lose { opacity: 0.36; filter: grayscale(1); }
.pl.tbd .nm { color: #657066; font-style: italic; font-weight: 500; }
/* me: the player looking at it */
.pl.me .nm::after, .fp.me .nm::after { content: ' ●'; color: var(--win); font-size: 9px; vertical-align: middle; }
.lv { display: flex; align-items: center; gap: 6px; font: 500 10px var(--mono); color: var(--live); padding: 3px 6px 2px; }
.dot { width: 7px; height: 7px; border-radius: 50%; background: var(--live); display: inline-block; flex: none; animation: pulse 1.4s infinite; }
@keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(255, 107, 107, 0.75); } 70% { box-shadow: 0 0 0 8px rgba(255, 107, 107, 0); } 100% { box-shadow: 0 0 0 0 rgba(255, 107, 107, 0); } }

/* phones: round tabs and one round's matches */
.rtabs { display: flex; gap: 6px; overflow-x: auto; padding: 0 2px 10px; scrollbar-width: none; }
.rtabs button { flex: none; display: inline-flex; align-items: center; gap: 6px; border: 1px solid rgba(255, 159, 67, 0.25); background: rgba(18, 23, 19, 0.88); color: #9aa69b; font: 700 12px var(--display); padding: 7px 12px; border-radius: 999px; }
.rtabs button.done { color: var(--win); border-color: rgba(94, 240, 168, 0.35); }
.rtabs button[aria-selected='true'] { color: #1a1206; background: var(--grad-gold); border-color: transparent; box-shadow: 0 0 18px rgba(255, 159, 67, 0.4); }
.rlist { display: grid; gap: 10px; }
.rlist .ch { text-align: left; padding: 0 2px; }
.byes { margin: 2px; font-size: 12px; color: #657066; }
.champ-row { text-align: center; padding: 8px; }
.tree-btn { margin: 12px auto 0; display: block; border: 1px solid rgba(255, 159, 67, 0.3); background: transparent; color: #ffd166; font: 700 12px var(--body); padding: 8px 14px; border-radius: 999px; }

/* the Grand Final */
.fb { display: block; width: 100%; font: inherit; color: inherit; text-align: center; padding: 16px 12px 14px; border-radius: 22px; border: 1.5px solid transparent;
    background: radial-gradient(circle at 50% 0%, rgba(255, 209, 102, 0.22), transparent 60%) padding-box, linear-gradient(#111612, #111612) padding-box, var(--grad-gold) border-box;
    box-shadow: 0 0 50px rgba(255, 159, 67, 0.28), 0 0 0 6px rgba(255, 159, 67, 0.05); }
.fb.pick { cursor: pointer; }
.fb.live { box-shadow: 0 0 50px rgba(255, 107, 107, 0.3), 0 0 0 6px rgba(255, 107, 107, 0.08); }
.cup { color: var(--gold); }
.cup svg { width: 54px; height: 54px; filter: drop-shadow(0 0 16px rgba(255, 159, 67, 0.8)); }
.fb h4 { font: 800 13px var(--display); letter-spacing: 0.18em; margin: 4px 0 2px; text-transform: uppercase; }
.when { font: 500 10.5px var(--mono); color: #9aa69b; }
.vs { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); align-items: start; gap: 6px; margin: 12px 0 8px; }
.fp .av { width: 46px; height: 46px; font-size: 13px; margin: 0 auto 6px; border-width: 2px; }
.fp .nm { white-space: normal; font-size: 12.5px; line-height: 1.2; }
.fp .sc { display: block; font-size: 24px; margin-top: 4px; }
.fp.win .av { border-color: var(--win); box-shadow: 0 0 18px rgba(94, 240, 168, 0.7); }
.fp.win .sc { color: var(--win); }
.fp.lose { opacity: 0.4; filter: grayscale(1); }
.fp.tbd .nm { color: #657066; font-style: italic; }
.vsb { font: 800 12px var(--display); color: var(--gold); align-self: center; text-shadow: 0 0 12px rgba(255, 159, 67, 0.8); padding-top: 14px; }
.fstat { font: 500 11px var(--mono); color: #9aa69b; min-height: 16px; }
.champ b { font-size: 14px; color: var(--win); }
.livetag { color: var(--live); display: inline-flex; align-items: center; gap: 6px; font-weight: 600; }
</style>
