<script setup lang="ts">
import { computed } from 'vue';
import { trans } from '@/lib/i18n';

// A tournament bracket: two halves that meet in the middle (the final), like a World Cup scheme. The left half is
// the top of every round, the right half the bottom, mirrored. Winners stand out, losers fade, a live match pulses.
// editable: the owner taps a match to mark the winner (the page opens its sheet).
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
const props = defineProps<{ bracket: BracketData; editable?: boolean }>();
const emit = defineEmits<{ pick: [m: Match] }>();

const roundName = (size: number) =>
    size === 2 ? trans('club.bracket.final') : size === 4 ? trans('club.bracket.semi') : size === 8 ? trans('club.bracket.quarter') : trans('club.bracket.round_of', { n: size, half: size / 2 });

// columns: left half rounds 1…R-1, the final, right half R-1…1
const cols = computed(() => {
    const rs = props.bracket.rounds;
    const last = rs[rs.length - 1];
    if (rs.length === 1) return { left: [], final: last, right: [] };
    const half = (r: (typeof rs)[number], right: boolean) => ({ ...r, matches: r.matches.filter((m) => (m.slot < r.matches.length / 2) !== right) });
    return {
        left: rs.slice(0, -1).map((r) => half(r, false)),
        final: last,
        right: rs
            .slice(0, -1)
            .map((r) => half(r, true))
            .reverse(),
    };
});
const champion = computed(() => {
    const f = cols.value.final.matches[0];
    return f?.winner ? (f.a?.id === f.winner ? f.a : f.b) : null;
});
const canPick = (m: Match) => props.editable && !!m.a && !!m.b;
const sideClass = (m: Match, s: Side | null) => ({ won: !!s && m.winner === s.id, lost: !!s && !!m.winner && m.winner !== s.id, me: s?.me, empty: !s });
</script>

<template>
    <div class="bk-wrap">
        <p class="bk-swipe">{{ $t('club.bracket.swipe') }}</p>
        <div class="bk" :style="{ '--cols': cols.left.length * 2 + 1 }">
            <template v-for="(side, si) in [cols.left, null, cols.right]" :key="si">
                <template v-if="side">
                    <div v-for="r in side" :key="`${si}-${r.round}`" :class="['bk-col', si === 0 ? 'l' : 'r']">
                        <h4>{{ roundName(r.size) }}</h4>
                        <div class="bk-ms">
                            <component
                                :is="canPick(m) ? 'button' : 'div'"
                                v-for="m in r.matches"
                                :key="m.id"
                                :type="canPick(m) ? 'button' : undefined"
                                :class="['bk-m', { live: m.live, done: !!m.winner, pick: canPick(m) }]"
                                :data-test="`match-${m.id}`"
                                @click="canPick(m) && emit('pick', m)"
                            >
                                <span v-for="s in [m.a, m.b]" :key="s?.id ?? Math.random()" :class="['bk-p', sideClass(m, s)]">
                                    <b>{{ s ? s.name : m.bye ? '—' : $t('club.bracket.tbd') }}</b>
                                    <small v-if="s?.rating">{{ s.rating }}</small>
                                </span>
                                <span v-if="m.score || m.live" class="bk-sc"
                                    ><i v-if="m.live" class="dot" />{{ m.live ? $t('club.bracket.live') : m.score }}</span
                                >
                            </component>
                        </div>
                    </div>
                </template>
                <div v-else class="bk-col final">
                    <h4>{{ roundName(2) }}</h4>
                    <div class="bk-ms">
                        <div class="bk-trophy" aria-hidden="true">🏆</div>
                        <component
                            :is="canPick(cols.final.matches[0]) ? 'button' : 'div'"
                            v-for="m in cols.final.matches"
                            :key="m.id"
                            :type="canPick(m) ? 'button' : undefined"
                            :class="['bk-m', 'fin', { live: m.live, done: !!m.winner, pick: canPick(m) }]"
                            :data-test="`match-${m.id}`"
                            @click="canPick(m) && emit('pick', m)"
                        >
                            <span v-for="s in [m.a, m.b]" :key="s?.id ?? Math.random()" :class="['bk-p', sideClass(m, s)]">
                                <b>{{ s ? s.name : $t('club.bracket.tbd') }}</b>
                                <small v-if="s?.rating">{{ s.rating }}</small>
                            </span>
                            <span v-if="m.score || m.live" class="bk-sc"><i v-if="m.live" class="dot" />{{ m.live ? $t('club.bracket.live') : m.score }}</span>
                        </component>
                        <div v-if="champion" class="bk-champ">
                            <small>{{ $t('club.bracket.champion') }}</small><b>{{ champion.name }}</b>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
.bk-wrap { overflow-x: auto; margin: 0 -4px; padding: 0 4px 6px; -webkit-overflow-scrolling: touch; }
.bk-swipe { display: none; font-size: 12px; color: var(--faint); margin: 0 0 6px; }
@media (max-width: 760px) { .bk-swipe { display: block; } }
.bk { display: grid; grid-template-columns: repeat(var(--cols), minmax(150px, 1fr)); gap: 18px; min-width: max-content; align-items: stretch; }
.bk-col { display: flex; flex-direction: column; min-width: 150px; }
.bk-col h4 { margin: 0 0 8px; font-size: 11.5px; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--muted); text-align: center; }
.bk-ms { flex: 1; display: flex; flex-direction: column; justify-content: space-around; gap: 10px; }
.bk-m { position: relative; display: flex; flex-direction: column; gap: 2px; padding: 6px; border-radius: 12px; border: 1px solid var(--line-2); background: var(--surface-2); color: var(--ink); text-align: left; font: inherit; width: 100%; }
.bk-m.pick { cursor: pointer; }
.bk-m.pick:hover { border-color: var(--accent); }
.bk-m.live { border-color: #ff6b6b; box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.15); }
/* connectors: a short line towards the next round (right of left-half matches, left of right-half ones) */
.bk-col.l .bk-m::after, .bk-col.r .bk-m::after { content: ''; position: absolute; top: 50%; width: 18px; border-top: 2px solid var(--line-2); }
.bk-col.l .bk-m::after { left: 100%; }
.bk-col.r .bk-m::after { right: 100%; }
.bk-col.l .bk-m.done::after, .bk-col.r .bk-m.done::after { border-color: var(--accent); }
.bk-p { display: flex; align-items: baseline; gap: 6px; padding: 4px 6px; border-radius: 8px; font-size: 13px; }
.bk-p b { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; }
.bk-p small { font-family: var(--mono); font-size: 11px; color: var(--faint); }
.bk-p.won { background: color-mix(in srgb, var(--accent) 18%, transparent); }
.bk-p.won b { font-weight: 800; color: var(--accent); }
.bk-p.lost { opacity: 0.45; }
.bk-p.lost b { text-decoration: line-through; }
.bk-p.empty b { color: var(--faint); font-weight: 500; font-style: italic; }
.bk-p.me b::after { content: ' •'; color: #5ef0a8; }
.bk-sc { align-self: flex-end; display: inline-flex; align-items: center; gap: 5px; font-family: var(--mono); font-size: 11.5px; color: var(--muted); padding: 0 6px; }
.dot { width: 7px; height: 7px; border-radius: 50%; background: #ff6b6b; animation: bkpulse 1.2s infinite; }
@keyframes bkpulse { 50% { opacity: 0.25; } }
.final .bk-ms { justify-content: center; align-items: center; }
.bk-trophy { font-size: 34px; text-align: center; }
.bk-m.fin { border-color: color-mix(in srgb, var(--accent) 55%, transparent); padding: 8px; }
.bk-champ { text-align: center; display: flex; flex-direction: column; }
.bk-champ small { font-size: 11px; font-weight: 800; text-transform: uppercase; color: var(--muted); }
.bk-champ b { font-family: var(--display); color: var(--accent); }
</style>
