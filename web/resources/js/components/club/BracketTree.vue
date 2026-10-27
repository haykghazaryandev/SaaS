<script setup lang="ts">
import { trans } from '@/lib/i18n';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Match, Round, Side } from './Bracket.vue';

// The "arena" tree (design B): the last rounds of a bracket (at most 16 players: 1/8 → the Grand Final), left half
// and right half meeting at the Grand Final card in the middle, glowing curved wires measured on the page.
// Surname in bold, "first name · rating" under it, the score on the right; winners green, losers grey, live red.
const props = defineProps<{ rounds: Round[]; total: number; editable?: boolean; when?: string; large?: boolean }>();
const emit = defineEmits<{ pick: [m: Match] }>();

const sizeOf = (round: number) => 2 ** (props.total - round + 1);
const roundName = (size: number) =>
    size === 2 ? trans('club.bracket.final') : size === 4 ? trans('club.bracket.semi') : size === 8 ? trans('club.bracket.quarter') : trans('club.bracket.round_of', { n: size, half: size / 2 });
/** "QF3", "SF1", "R16 · 5": a match's short name, for "Winner of …" */
function label(round: number, slot: number) {
    const size = sizeOf(round);
    return size === 4 ? `SF${slot + 1}` : size === 8 ? `QF${slot + 1}` : size === 16 ? `R16·${slot + 1}` : `1/${size / 2}·${slot + 1}`;
}
const first = computed(() => props.rounds[0]?.round ?? 1);

type Col = { round: number; side: 'L' | 'R'; matches: Match[] };
const cols = computed(() => {
    const inner = props.rounds.slice(0, -1);
    const half = (r: Round, side: 'L' | 'R'): Col => ({ round: r.round, side, matches: r.matches.filter((m) => (m.slot < r.matches.length / 2) === (side === 'L')) });
    return { left: inner.map((r) => half(r, 'L')), right: inner.map((r) => half(r, 'R')).reverse(), final: props.rounds[props.rounds.length - 1]?.matches[0] ?? null };
});
const n = computed(() => cols.value.left.length);
const colW = computed(() => (props.large ? 168 : 128));
const gridCols = computed(() => {
    const side = n.value ? `repeat(${n.value}, minmax(${colW.value}px, 1fr))` : '';
    return [side, `minmax(${props.large ? 230 : 190}px, 1.35fr)`, side].filter(Boolean).join(' ');
});
const minW = computed(() => n.value * 2 * (colW.value + 16) + (props.large ? 230 : 190));

const fin = computed(() => cols.value.final);
const champion = computed(() => (fin.value?.winner ? (fin.value.a?.id === fin.value.winner ? fin.value.a : fin.value.b) : null));
const out = computed(() => {
    const s = new Set<number>();
    for (const r of props.rounds) for (const m of r.matches) if (m.winner && m.a && m.b) s.add(m.winner === m.a.id ? m.b.id : m.a.id);
    return s;
});

const ini = (s: Side) => `${(s.first || s.name)[0] ?? ''}${(s.last || '')[0] ?? ''}`.toUpperCase();
const hue = (id: number) => (id * 67 + 24) % 360;
const state = (m: Match, s: Side | null) => (!s ? 'tbd' : m.winner ? (m.winner === s.id ? 'win' : 'lose') : m.live ? 'live' : '');
/** "3:1" → each player's number, the winner's the higher one (whichever way it was typed) */
function scoreOf(m: Match, s: Side | null): string {
    if (!s || !m.score) return '';
    const p = /^\s*(\d+)\s*\D+\s*(\d+)\s*$/.exec(m.score);
    if (!p) return s.id === m.winner ? m.score : '';
    if (!m.winner) return String(s.id === m.a?.id ? +p[1] : +p[2]);
    return String(s.id === m.winner ? Math.max(+p[1], +p[2]) : Math.min(+p[1], +p[2]));
}
/** an empty place: "Winner of QF3" (the match it comes from) */
const fromName = (round: number, slot: number, j: number) => (round > first.value ? trans('club.bracket.winner_of', { m: label(round - 1, 2 * slot + j) }) : trans('club.bracket.tbd'));
// the narrow cards: just the match it comes from ("QF3", "R16·5"); the Grand Final card says "Winner of SF2"
const fromShort = (round: number, slot: number, j: number) => (round > first.value ? label(round - 1, 2 * slot + j) : trans('club.bracket.tbd'));
const canPick = (m: Match | null) => !!m && !!props.editable && !!m.a && !!m.b;

// ---------- wires ----------
const root = ref<HTMLElement>();
const paths = ref<{ d: string; cls: string }[]>([]);
function wire() {
    const tree = root.value;
    if (!tree) return;
    const tb = tree.getBoundingClientRect();
    if (!tb.width) return;
    const next: { d: string; cls: string }[] = [];
    for (const r of props.rounds) {
        if (r.round >= props.total) continue;
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
            next.push({ d: `M${x1} ${y1}C${mx} ${y1} ${mx} ${y2} ${x2} ${y2}`, cls: !m.winner ? 'off' : out.value.has(m.winner) ? 'on' : 'on alive' });
        }
    }
    paths.value = next.sort((x, y) => (x.cls === 'off' ? 0 : 1) - (y.cls === 'off' ? 0 : 1));
}
let ro: ResizeObserver | undefined;
let raf = 0;
const later = () => {
    cancelAnimationFrame(raf);
    raf = requestAnimationFrame(wire);
};
onMounted(() => {
    later();
    ro = new ResizeObserver(later);
    if (root.value) ro.observe(root.value);
    document.fonts?.ready.then(later);
});
onBeforeUnmount(() => {
    ro?.disconnect();
    cancelAnimationFrame(raf);
});
watch(() => props.rounds, () => nextTick(later));
</script>

<template>
    <div ref="root" :class="['tree', { large }]" :style="{ gridTemplateColumns: gridCols, minWidth: `${minW}px` }">
        <svg class="wires" aria-hidden="true"><path v-for="(p, i) in paths" :key="i" :class="p.cls" :d="p.d" /></svg>

        <template v-for="side in ['L', 'C', 'R'] as const" :key="side">
            <div v-for="c in side === 'L' ? cols.left : side === 'R' ? cols.right : []" :key="`${side}${c.round}`" :class="['col', `col-${side}`]">
                <div class="ch">{{ roundName(sizeOf(c.round)) }}</div>
                <div class="cb">
                    <div v-for="m in c.matches" :key="m.id" class="slot">
                        <!-- a bye: one player going on -->
                        <div v-if="m.bye" class="mb bye" :data-m="`${c.round}-${m.slot}`">
                            <div class="anc">
                                <div v-for="s in [m.a ?? m.b]" :key="s?.id" :class="['pl', 'win', { me: s?.me }]">
                                    <span class="av" :style="{ '--h': hue(s!.id) }">{{ ini(s!) }}</span>
                                    <span class="who"><span class="nm" :title="s!.name">{{ s!.last || s!.name }}</span><span class="rt">{{ $t('club.bracket.goes_on') }}</span></span>
                                </div>
                            </div>
                        </div>
                        <component
                            :is="canPick(m) ? 'button' : 'div'"
                            v-else
                            :type="canPick(m) ? 'button' : undefined"
                            :class="['mb', { 'st-l': m.live, pick: canPick(m) }]"
                            :data-m="`${c.round}-${m.slot}`"
                            :data-test="`match-${m.id}`"
                            @click.stop="canPick(m) && emit('pick', m)"
                        >
                            <div class="anc">
                                <div v-for="(s, j) in [m.a, m.b]" :key="j" :class="['pl', state(m, s), { me: s?.me }]">
                                    <span v-if="s" class="av" :style="{ '--h': hue(s.id) }">{{ ini(s) }}</span><span v-else class="av q">?</span>
                                    <span class="who"
                                        ><span class="nm" :title="s?.name">{{ s ? s.last || s.name : fromShort(c.round, m.slot, j) }}</span
                                        ><span v-if="s" class="rt">{{ s.first }}{{ s.rating ? ` · ${s.rating}` : '' }}</span></span
                                    >
                                    <b class="sc">{{ scoreOf(m, s) }}</b>
                                </div>
                            </div>
                            <div v-if="m.live" class="lv"><span class="livetag"><i class="dot" />LIVE</span></div>
                        </component>
                    </div>
                </div>
            </div>

            <!-- the Grand Final -->
            <div v-if="side === 'C'" class="col col-C">
                <div class="ch">{{ roundName(2) }}</div>
                <div class="cb">
                    <div class="slot">
                        <component
                            :is="canPick(fin) ? 'button' : 'div'"
                            v-if="fin"
                            :type="canPick(fin) ? 'button' : undefined"
                            :class="['fb', { 'st-l': fin.live, pick: canPick(fin) }]"
                            :data-m="`${total}-0`"
                            :data-test="`match-${fin.id}`"
                            @click.stop="canPick(fin) && emit('pick', fin)"
                        >
                            <div class="cup" aria-hidden="true">
                                <svg viewBox="0 0 64 64">
                                    <defs>
                                        <linearGradient id="bk-gold" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ffd166" /><stop offset="1" stop-color="#ff8a3d" /></linearGradient>
                                    </defs>
                                    <path fill="url(#bk-gold)" d="M18 8h28v12c0 9-6 16-14 16s-14-7-14-16Z" />
                                    <path fill="none" stroke="url(#bk-gold)" stroke-width="4" d="M18 13H9v4c0 6 4 10 10 10M46 13h9v4c0 6-4 10-10 10" />
                                    <path fill="url(#bk-gold)" d="M28 36h8v10h-8zM20 48h24v6H20z" />
                                </svg>
                            </div>
                            <h4>{{ $t('club.bracket.grand_final') }}</h4>
                            <div v-if="when" class="when">{{ when }}</div>
                            <div class="anc vs">
                                <template v-for="(s, j) in [fin.a, fin.b]" :key="j">
                                    <span v-if="j === 1" class="vsb">VS</span>
                                    <div :class="['fp', state(fin, s), { me: s?.me }]">
                                        <span v-if="s" class="av" :style="{ '--h': hue(s.id) }">{{ ini(s) }}</span><span v-else class="av q">?</span>
                                        <div class="nm">{{ s ? s.name : fromName(total, 0, j) }}</div>
                                        <div v-if="s?.rating" class="rt">{{ s.rating }}</div>
                                        <span class="sc">{{ scoreOf(fin, s) || '–' }}</span>
                                    </div>
                                </template>
                            </div>
                            <div class="fstat">
                                <span v-if="champion" class="champ">🏆 {{ $t('club.bracket.champion') }}<b>{{ champion.name }}</b></span>
                                <span v-else-if="fin.live" class="livetag"><i class="dot" />LIVE</span>
                            </div>
                        </component>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>

<style scoped>
/* design B, "arena": the same sizes and colours as the chosen mock-up */
.tree { --win: #5ef0a8; --live: #ff6b6b; --muted: #9aa69b; --faint: #657066; position: relative; display: grid; column-gap: 16px; width: 100%; }
.col { display: flex; flex-direction: column; min-width: 0; position: relative; z-index: 1; }
.ch { text-align: center; font: 600 10px var(--display); letter-spacing: 0.14em; text-transform: uppercase; color: rgba(255, 209, 102, 0.55); text-shadow: 0 0 10px rgba(255, 159, 67, 0.35); padding: 2px 0 8px; white-space: nowrap; }
.cb { flex: 1; display: flex; flex-direction: column; }
.slot { flex: 1; display: flex; align-items: center; padding: 8px 0; }
.slot > * { width: 100%; }

.wires { position: absolute; left: 0; top: 0; width: 100%; height: 100%; z-index: 0; pointer-events: none; overflow: visible; }
.wires path { fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.wires .off { stroke: rgba(255, 159, 67, 0.14); stroke-dasharray: 3 5; }
.wires .on { stroke: rgba(94, 240, 168, 0.38); }
.wires .alive { stroke: #ffb36b; stroke-width: 3; filter: drop-shadow(0 0 3px #ff9f43) drop-shadow(0 0 10px rgba(255, 138, 61, 0.9)); }

.mb { display: block; width: 100%; font: inherit; color: #eef5ec; text-align: left; background: rgba(18, 23, 19, 0.88); border: 1px solid rgba(255, 159, 67, 0.16); border-radius: 16px; padding: 5px; box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4); }
.mb.pick { cursor: pointer; }
.mb.pick:hover { border-color: rgba(255, 159, 67, 0.6); }
.mb.st-l { border-color: rgba(255, 107, 107, 0.55); box-shadow: 0 0 22px rgba(255, 107, 107, 0.22); }
.mb.st-l .sc { color: var(--live); }
.mb.bye { opacity: 0.7; border-style: dashed; box-shadow: none; }
.pl { display: flex; align-items: center; gap: 7px; padding: 5px 6px; border-radius: 11px; }
.av { width: 24px; height: 24px; border-radius: 50%; display: grid; place-items: center; font: 700 10px var(--display); background: hsl(var(--h) 38% 20%); color: hsl(var(--h) 85% 80%); flex: none; border: 1.5px solid hsl(var(--h) 45% 38%); }
.av.q { background: transparent; border: 1.5px dashed var(--faint); color: var(--faint); }
.who { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.nm { font-weight: 700; font-size: 12.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rt { font: 400 10px var(--mono); color: var(--faint); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sc { width: 18px; flex: none; text-align: center; font: 600 14px var(--mono); color: var(--muted); }
.pl.win { background: rgba(94, 240, 168, 0.08); }
.pl.win .nm { font-weight: 800; }
.pl.win .av { border-color: var(--win); box-shadow: 0 0 12px rgba(94, 240, 168, 0.6); }
.pl.win .sc { color: var(--win); text-shadow: 0 0 10px rgba(94, 240, 168, 0.7); }
.pl.lose { opacity: 0.36; filter: grayscale(1); }
.pl.tbd .nm { color: var(--faint); font-style: italic; font-weight: 600; }
.pl.me .nm::after, .fp.me .nm::after { content: ' ●'; color: var(--win); font-size: 9px; vertical-align: middle; }
.col-R .pl { flex-direction: row-reverse; }
.col-R .who { text-align: right; }
.lv { font: 500 10px var(--mono); padding: 3px 6px 2px; }
.livetag { color: var(--live); display: inline-flex; align-items: center; gap: 6px; font-weight: 600; }
.dot { width: 7px; height: 7px; border-radius: 50%; background: var(--live); display: inline-block; flex: none; animation: pulse 1.4s infinite; }
@keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(255, 107, 107, 0.75); } 70% { box-shadow: 0 0 0 8px rgba(255, 107, 107, 0); } 100% { box-shadow: 0 0 0 0 rgba(255, 107, 107, 0); } }

.fb { display: block; width: 100%; font: inherit; color: #eef5ec; text-align: center; padding: 16px 12px 14px; border-radius: 22px; border: 1.5px solid transparent;
    background: radial-gradient(circle at 50% 0%, rgba(255, 209, 102, 0.22), transparent 60%) padding-box, linear-gradient(#111612, #111612) padding-box, linear-gradient(135deg, #ffd166 0%, #ff8a3d 100%) border-box;
    box-shadow: 0 0 50px rgba(255, 159, 67, 0.28), 0 0 0 6px rgba(255, 159, 67, 0.05); }
.fb.pick { cursor: pointer; }
.fb .cup svg { width: 54px; height: 54px; filter: drop-shadow(0 0 16px rgba(255, 159, 67, 0.8)); }
.fb h4 { font: 800 13px var(--display); letter-spacing: 0.18em; margin: 4px 0 2px; text-transform: uppercase; }
.fb .when { font: 500 10.5px var(--mono); color: var(--muted); }
.fb .vs { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); align-items: start; gap: 6px; margin: 12px 0 8px; }
.fp .av { width: 46px; height: 46px; font-size: 13px; margin: 0 auto 6px; border-width: 2px; }
.fp .nm { white-space: normal; font-size: 12.5px; line-height: 1.2; }
.fp .rt { margin-top: 2px; white-space: normal; }
.fp .sc { display: block; width: auto; font-size: 24px; margin-top: 4px; color: var(--muted); }
.fp.win .av { border-color: var(--win); box-shadow: 0 0 18px rgba(94, 240, 168, 0.7); }
.fp.win .sc { color: var(--win); }
.fp.lose { opacity: 0.4; filter: grayscale(1); }
.fp.tbd .nm { color: var(--faint); font-style: italic; }
.fb.st-l .sc { color: var(--live); }
.vsb { font: 800 12px var(--display); color: #ff9f43; align-self: center; text-shadow: 0 0 12px rgba(255, 159, 67, 0.8); padding-top: 2px; }
.fstat { font: 500 11px var(--mono); color: var(--muted); min-height: 14px; }
.champ { display: inline-flex; flex-direction: column; gap: 2px; }
.champ b { font-size: 15px; color: var(--win); font-family: var(--display); }

/* full screen: everything a bit bigger */
.tree.large .nm { font-size: 14.5px; }
.tree.large .rt { font-size: 11.5px; }
.tree.large .av { width: 30px; height: 30px; font-size: 11px; }
.tree.large .sc { font-size: 16px; width: 22px; }
.tree.large .slot { padding: 12px 0; }
.tree.large .fp .av { width: 60px; height: 60px; font-size: 16px; }
.tree.large .fb h4 { font-size: 16px; }
</style>
