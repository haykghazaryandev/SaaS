<script setup lang="ts">
import BracketFinal from '@/components/club/BracketFinal.vue';
import { trans } from '@/lib/i18n';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Match, Round, Side } from './Bracket.vue';

// Phones (design "half by half"): a Top half / Bottom half switch (or a swipe); each half is a small tree
// (1/8 → 1/4 → 1/2) that fits the screen without sideways scrolling, and the Grand Final card below it shows
// whoever is in the Final already ("Ani") and where the other side comes from ("Winner of SF2").
const props = defineProps<{ rounds: Round[]; total: number; editable?: boolean; when?: string }>();
const emit = defineEmits<{ pick: [m: Match] }>();

const sizeOf = (round: number) => 2 ** (props.total - round + 1);
const head = (round: number) => `1/${sizeOf(round) / 2}`;
const inner = computed(() => props.rounds.slice(0, -1));
const fin = computed(() => props.rounds[props.rounds.length - 1]?.matches[0] ?? null);
/** the matches of a half in each round (top: the first half of the slots) */
const half = (h: 0 | 1) => inner.value.map((r) => ({ round: r.round, matches: r.matches.filter((m) => (m.slot < r.matches.length / 2) === (h === 0)) }));
const halves = computed(() => [half(0), half(1)]);
// the half with my matches gets a green dot
const mine = (h: 0 | 1) => halves.value[h].some((c) => c.matches.some((m) => m.a?.me || m.b?.me));

const shown = ref<0 | 1>(0);
const panes = ref<HTMLElement>();
function go(h: 0 | 1) {
    shown.value = h;
    panes.value?.scrollTo({ left: h * panes.value.clientWidth, behavior: 'smooth' });
}
function onScroll() {
    const p = panes.value;
    if (p) shown.value = Math.round(p.scrollLeft / Math.max(1, p.clientWidth)) as 0 | 1;
}

const state = (m: Match, s: Side | null) => (!s ? 'tbd' : m.winner ? (m.winner === s.id ? 'win' : 'lose') : m.live ? 'live' : '');
function scoreOf(m: Match, s: Side | null): string {
    if (!s || !m.score) return '';
    const p = /^\s*(\d+)\s*\D+\s*(\d+)\s*$/.exec(m.score);
    if (!p) return s.id === m.winner ? m.score : '';
    if (!m.winner) return String(s.id === m.a?.id ? +p[1] : +p[2]);
    return String(s.id === m.winner ? Math.max(+p[1], +p[2]) : Math.min(+p[1], +p[2]));
}
const label = (round: number, slot: number) => {
    const size = sizeOf(round);
    return size === 4 ? `SF${slot + 1}` : size === 8 ? `QF${slot + 1}` : size === 16 ? `R16·${slot + 1}` : `1/${size / 2}·${slot + 1}`;
};
const first = computed(() => props.rounds[0]?.round ?? 1);
const fromShort = (round: number, slot: number, j: number) => (round > first.value ? label(round - 1, 2 * slot + j) : trans('club.bracket.tbd'));
const canPick = (m: Match) => !!props.editable && !!m.a && !!m.b;

// ---------- wires inside each half ----------
const trees = ref<HTMLElement[]>([]);
const paths = ref<{ d: string; cls: string }[][]>([[], []]);
const out = computed(() => {
    const s = new Set<number>();
    for (const r of props.rounds) for (const m of r.matches) if (m.winner && m.a && m.b) s.add(m.winner === m.a.id ? m.b.id : m.a.id);
    return s;
});
function wire() {
    trees.value.forEach((tree, h) => {
        const tb = tree.getBoundingClientRect();
        if (!tb.width) return;
        const next: { d: string; cls: string }[] = [];
        for (const c of halves.value[h]) {
            for (const m of c.matches) {
                const a = tree.querySelector(`[data-m="${c.round}-${m.slot}"]`);
                const b = tree.querySelector(`[data-m="${c.round + 1}-${m.slot >> 1}"]`);
                if (!a || !b) continue;
                const ra = a.getBoundingClientRect();
                const rb = b.getBoundingClientRect();
                const x1 = ra.right - tb.left;
                const y1 = ra.top + ra.height / 2 - tb.top;
                const x2 = rb.left - tb.left;
                const y2 = rb.top + rb.height / 2 - tb.top;
                const mx = (x1 + x2) / 2;
                next.push({ d: `M${x1} ${y1}C${mx} ${y1} ${mx} ${y2} ${x2} ${y2}`, cls: !m.winner ? 'off' : out.value.has(m.winner) ? 'on' : 'on alive' });
            }
        }
        paths.value[h] = next.sort((x, y) => (x.cls === 'off' ? 0 : 1) - (y.cls === 'off' ? 0 : 1));
    });
}
let ro: ResizeObserver | undefined;
let raf = 0;
const later = () => {
    cancelAnimationFrame(raf);
    raf = requestAnimationFrame(wire);
};
onMounted(() => {
    // open on the half with my matches
    if (!mine(0) && mine(1)) nextTick(() => go(1));
    later();
    ro = new ResizeObserver(later);
    trees.value.forEach((t) => ro!.observe(t));
});
onBeforeUnmount(() => {
    ro?.disconnect();
    cancelAnimationFrame(raf);
});
watch(() => props.rounds, () => nextTick(later));
</script>

<template>
    <div class="halves">
        <template v-if="inner.length">
            <div class="seg" role="tablist">
                <span class="segbg" :style="{ transform: `translateX(${shown * 100}%)` }" />
                <button v-for="h in [0, 1] as const" :key="h" type="button" role="tab" :aria-selected="shown === h" :class="{ on: shown === h }" :data-test="`half-${h}`" @click="go(h)">
                    {{ h === 0 ? $t('club.bracket.top_half') : $t('club.bracket.bottom_half') }}<i v-if="mine(h)" class="medot" />
                </button>
            </div>
            <div ref="panes" class="panes" @scroll.passive="onScroll">
                <div v-for="(cols, h) in halves" :key="h" class="pane">
                    <div ref="trees" class="htree" :style="{ gridTemplateColumns: `repeat(${cols.length}, minmax(0, 1fr))` }">
                        <svg class="wires" aria-hidden="true"><path v-for="(p, i) in paths[h]" :key="i" :class="p.cls" :d="p.d" /></svg>
                        <div v-for="c in cols" :key="c.round" class="col">
                            <div class="ch">{{ head(c.round) }}</div>
                            <div class="cb">
                                <div v-for="m in c.matches" :key="m.id" class="slot">
                                    <component
                                        :is="canPick(m) ? 'button' : 'div'"
                                        :type="canPick(m) ? 'button' : undefined"
                                        :class="['mb', { live: m.live, pick: canPick(m), bye: m.bye }]"
                                        :data-m="`${c.round}-${m.slot}`"
                                        :data-test="`match-${m.id}`"
                                        @click="canPick(m) && emit('pick', m)"
                                    >
                                        <span v-if="m.live" class="livetag"><i class="dot" />LIVE</span>
                                        <template v-if="m.bye">
                                            <div class="pr win">
                                                <span class="sn">{{ (m.a ?? m.b)?.last || (m.a ?? m.b)?.name }}</span><small class="byet">{{ $t('club.bracket.bye') }}</small>
                                            </div>
                                        </template>
                                        <template v-else>
                                            <div v-for="(s, j) in [m.a, m.b]" :key="j" :class="['pr', state(m, s), { me: s?.me }]">
                                                <span class="sn" :title="s?.name">{{ s ? s.last || s.name : fromShort(c.round, m.slot, j) }}</span>
                                                <i v-if="s?.me" class="medot" />
                                                <b class="sc">{{ scoreOf(m, s) }}</b>
                                            </div>
                                        </template>
                                    </component>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="tofinal">{{ $t('club.bracket.to_final') }}</div>
                </div>
            </div>
            <div class="fh">{{ $t('club.bracket.halves_meet') }}</div>
        </template>
        <BracketFinal v-if="fin" :match="fin" :total="total" :editable="editable" :when="when" @pick="(m) => emit('pick', m)" />
    </div>
</template>

<style scoped>
.halves { --win: #5ef0a8; --live: #ff6b6b; --muted: #9aa69b; --faint: #657066; color: #eef5ec; }
.seg { position: relative; display: flex; padding: 4px; border-radius: 14px; background: rgba(18, 23, 19, 0.92); border: 1px solid rgba(255, 159, 67, 0.16); margin-bottom: 10px; }
.seg button { flex: 1; position: relative; z-index: 1; height: 38px; border: 0; background: none; color: var(--muted); font: 700 13px var(--body); display: flex; align-items: center; justify-content: center; gap: 5px; cursor: pointer; transition: color 0.2s; }
.seg button.on { color: #1a1205; }
.segbg { position: absolute; top: 4px; bottom: 4px; left: 4px; width: calc(50% - 4px); border-radius: 10px; background: linear-gradient(135deg, #ffd166 0%, #ff8a3d 100%); transition: transform 0.25s; box-shadow: 0 0 18px rgba(255, 159, 67, 0.35); }
.medot { width: 7px; height: 7px; border-radius: 50%; background: var(--win); box-shadow: 0 0 8px rgba(94, 240, 168, 0.8); display: inline-block; flex: none; }
.seg button.on .medot { background: #0d5a37; box-shadow: none; }
.panes { display: flex; overflow-x: auto; overflow-y: hidden; scroll-snap-type: x mandatory; scrollbar-width: none; }
.panes::-webkit-scrollbar { display: none; }
.pane { flex: 0 0 100%; scroll-snap-align: start; scroll-snap-stop: always; min-width: 0; }
.htree { position: relative; display: grid; column-gap: 14px; }
.wires { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; overflow: visible; }
.wires path { fill: none; stroke-width: 2; stroke-linecap: round; }
.wires .off { stroke: rgba(255, 159, 67, 0.14); stroke-dasharray: 3 5; }
.wires .on { stroke: rgba(94, 240, 168, 0.38); }
.wires .alive { stroke: #ffb36b; stroke-width: 2.5; filter: drop-shadow(0 0 3px #ff9f43); }
.col { display: flex; flex-direction: column; min-width: 0; position: relative; z-index: 1; }
.ch { text-align: center; font: 600 10px var(--display); letter-spacing: 0.14em; text-transform: uppercase; color: rgba(255, 209, 102, 0.55); padding: 2px 0 6px; }
.cb { flex: 1; display: flex; flex-direction: column; }
.slot { flex: 1; display: flex; align-items: center; padding: 5px 0; }
.slot > * { width: 100%; }
/* compact cards: surname and score */
.mb { position: relative; display: block; width: 100%; font: inherit; color: inherit; text-align: left; background: rgba(18, 23, 19, 0.88); border: 1px solid rgba(255, 159, 67, 0.16); border-radius: 12px; padding: 3px; box-shadow: 0 6px 20px rgba(0, 0, 0, 0.35); }
.mb.pick { cursor: pointer; }
.mb.live { border-color: rgba(255, 107, 107, 0.55); box-shadow: 0 0 16px rgba(255, 107, 107, 0.22); }
.mb.bye { opacity: 0.7; border-style: dashed; box-shadow: none; }
.pr { display: flex; align-items: center; gap: 4px; height: 24px; padding: 0 6px 0 7px; border-radius: 9px; }
.sn { flex: 1; min-width: 0; font-size: 11px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sc { font: 600 12px var(--mono); color: var(--muted); min-width: 10px; text-align: right; }
.pr.win { background: rgba(94, 240, 168, 0.08); }
.pr.win .sn { font-weight: 800; }
.pr.win .sc { color: var(--win); text-shadow: 0 0 8px rgba(94, 240, 168, 0.7); }
.pr.lose { opacity: 0.36; filter: grayscale(1); }
.pr.tbd .sn { color: var(--faint); font-style: italic; font-weight: 600; }
.pr .medot { width: 6px; height: 6px; }
.byet { font: 400 9px var(--mono); color: var(--faint); }
.livetag { position: absolute; top: -8px; right: 6px; display: inline-flex; align-items: center; gap: 4px; padding: 1px 5px; border-radius: 99px; background: #2a1213; color: var(--live); font: 700 7.5px var(--mono); border: 1px solid rgba(255, 107, 107, 0.5); }
.dot { width: 5px; height: 5px; border-radius: 50%; background: var(--live); animation: pulse 1.4s infinite; }
@keyframes pulse { 50% { opacity: 0.3; } }
.tofinal { text-align: right; font: 10px var(--mono); color: #ffd166; opacity: 0.8; margin: 4px 0 0; }
.fh { text-align: center; font-size: 11px; color: var(--muted); margin: 12px 0 10px; }
</style>
