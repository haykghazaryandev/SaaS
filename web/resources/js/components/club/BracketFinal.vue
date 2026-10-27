<script setup lang="ts">
import { trans } from '@/lib/i18n';
import { computed } from 'vue';
import type { Match, Side } from './Bracket.vue';

// The Grand Final card ("arena"): trophy, date, the two finalists with VS, the champion when it is played.
// A finalist already known shows at once (Ani is in the Final); the other side says where it comes from.
const props = defineProps<{ match: Match; total: number; editable?: boolean; when?: string; large?: boolean }>();
const emit = defineEmits<{ pick: [m: Match] }>();
const m = computed(() => props.match);
const champion = computed(() => (m.value.winner ? (m.value.a?.id === m.value.winner ? m.value.a : m.value.b) : null));
const canPick = computed(() => !!props.editable && !!m.value.a && !!m.value.b);
const ini = (s: Side) => `${(s.first || s.name)[0] ?? ''}${(s.last || '')[0] ?? ''}`.toUpperCase();
const hue = (id: number) => (id * 67 + 24) % 360;
const state = (s: Side | null) => (!s ? 'tbd' : m.value.winner ? (m.value.winner === s.id ? 'win' : 'lose') : m.value.live ? 'live' : '');
/** the side not known yet: "Winner of SF1" (a 2-player tournament: to be decided) */
const from = (j: number) => (props.total > 1 ? trans('club.bracket.winner_of', { m: `SF${j + 1}` }) : trans('club.bracket.tbd'));
function scoreOf(s: Side | null): string {
    if (!s || !m.value.score) return '';
    const p = /^\s*(\d+)\s*\D+\s*(\d+)\s*$/.exec(m.value.score);
    if (!p) return s.id === m.value.winner ? m.value.score : '';
    if (!m.value.winner) return String(s.id === m.value.a?.id ? +p[1] : +p[2]);
    return String(s.id === m.value.winner ? Math.max(+p[1], +p[2]) : Math.min(+p[1], +p[2]));
}
</script>

<template>
    <component
        :is="canPick ? 'button' : 'div'"
        :type="canPick ? 'button' : undefined"
        :class="['fb', { 'st-l': m.live, pick: canPick, large }]"
        :data-m="`${total}-0`"
        :data-test="`match-${m.id}`"
        @click.stop="canPick && emit('pick', m)"
    >
        <div class="cup" aria-hidden="true">
            <svg viewBox="0 0 64 64">
                <defs>
                    <linearGradient :id="`bk-gold-${m.id}`" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ffd166" /><stop offset="1" stop-color="#ff8a3d" /></linearGradient>
                </defs>
                <path :fill="`url(#bk-gold-${m.id})`" d="M18 8h28v12c0 9-6 16-14 16s-14-7-14-16Z" />
                <path fill="none" :stroke="`url(#bk-gold-${m.id})`" stroke-width="4" d="M18 13H9v4c0 6 4 10 10 10M46 13h9v4c0 6-4 10-10 10" />
                <path :fill="`url(#bk-gold-${m.id})`" d="M28 36h8v10h-8zM20 48h24v6H20z" />
            </svg>
        </div>
        <h4>{{ $t('club.bracket.grand_final') }}</h4>
        <div v-if="when" class="when">{{ when }}</div>
        <div class="anc vs">
            <template v-for="(s, j) in [m.a, m.b]" :key="j">
                <span v-if="j === 1" class="vsb">VS</span>
                <div :class="['fp', state(s), { me: s?.me }]">
                    <span v-if="s" class="av" :style="{ '--h': hue(s.id) }">{{ ini(s) }}</span><span v-else class="av q">?</span>
                    <div class="nm">{{ s ? s.name : from(j) }}</div>
                    <div v-if="s?.rating" class="rt">{{ s.rating }}</div>
                    <span class="sc">{{ scoreOf(s) || '–' }}</span>
                </div>
            </template>
        </div>
        <div class="fstat">
            <span v-if="champion" class="champ">🏆 {{ $t('club.bracket.champion') }}<b>{{ champion.name }}</b></span>
            <span v-else-if="m.live" class="livetag"><i class="dot" />LIVE</span>
        </div>
    </component>
</template>

<style scoped>
.fb { --win: #5ef0a8; --live: #ff6b6b; --muted: #9aa69b; --faint: #657066; display: block; width: 100%; font: inherit; color: #eef5ec; text-align: center; padding: 16px 12px 14px; border-radius: 22px; border: 1.5px solid transparent;
    background: radial-gradient(circle at 50% 0%, rgba(255, 209, 102, 0.22), transparent 60%) padding-box, linear-gradient(#111612, #111612) padding-box, linear-gradient(135deg, #ffd166 0%, #ff8a3d 100%) border-box;
    box-shadow: 0 0 50px rgba(255, 159, 67, 0.28), 0 0 0 6px rgba(255, 159, 67, 0.05); }
.fb.pick { cursor: pointer; }
.cup svg { width: 54px; height: 54px; filter: drop-shadow(0 0 16px rgba(255, 159, 67, 0.8)); }
h4 { font: 800 13px var(--display); letter-spacing: 0.18em; margin: 4px 0 2px; text-transform: uppercase; }
.when { font: 500 10.5px var(--mono); color: var(--muted); }
.vs { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); align-items: start; gap: 6px; margin: 12px 0 8px; }
.av { width: 46px; height: 46px; border-radius: 50%; display: grid; place-items: center; font: 700 13px var(--display); margin: 0 auto 6px; background: hsl(var(--h) 38% 20%); color: hsl(var(--h) 85% 80%); border: 2px solid hsl(var(--h) 45% 38%); }
.av.q { background: transparent; border: 1.5px dashed var(--faint); color: var(--faint); }
.nm { font-weight: 700; font-size: 12.5px; line-height: 1.2; }
.rt { margin-top: 2px; font: 400 10px var(--mono); color: var(--faint); }
.sc { display: block; margin-top: 4px; font: 600 24px var(--mono); color: var(--muted); }
.fp.win .av { border-color: var(--win); box-shadow: 0 0 18px rgba(94, 240, 168, 0.7); }
.fp.win .sc { color: var(--win); }
.fp.lose { opacity: 0.4; filter: grayscale(1); }
.fp.tbd .nm { color: var(--faint); font-style: italic; }
.fp.me .nm::after { content: ' ●'; color: var(--win); font-size: 9px; vertical-align: middle; }
.fb.st-l .sc { color: var(--live); }
.vsb { font: 800 12px var(--display); color: #ff9f43; align-self: center; text-shadow: 0 0 12px rgba(255, 159, 67, 0.8); padding-top: 2px; }
.fstat { font: 500 11px var(--mono); color: var(--muted); min-height: 14px; }
.champ { display: inline-flex; flex-direction: column; gap: 2px; }
.champ b { font-size: 15px; color: var(--win); font-family: var(--display); }
.livetag { color: var(--live); display: inline-flex; align-items: center; gap: 6px; font-weight: 600; }
.dot { width: 7px; height: 7px; border-radius: 50%; background: var(--live); display: inline-block; animation: pulse 1.4s infinite; }
@keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(255, 107, 107, 0.75); } 70% { box-shadow: 0 0 0 8px rgba(255, 107, 107, 0); } 100% { box-shadow: 0 0 0 0 rgba(255, 107, 107, 0); } }
.large .av { width: 60px; height: 60px; font-size: 16px; }
.large h4 { font-size: 16px; }
</style>
