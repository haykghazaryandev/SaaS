<script setup lang="ts">
import { trans } from '@/lib/i18n';
import { computed } from 'vue';
import type { BracketData, Match, Round, Side } from './Bracket.vue';

// The bracket on a tournament's page ("Option A"): one spotlight that changes with the tournament, live.
// Someone in the Final → the Grand Final (A4), live (A5) or the champion (A6); otherwise the matches being played (A3),
// else the next / first matches (A2); before the draw the players registered (A1). "Open the bracket" → full screen.
export interface Player {
    id: number;
    first: string;
    last: string;
    me?: boolean;
}
const props = defineProps<{
    bracket: BracketData | null;
    count: number;
    maxPlayers: number | null;
    players: Player[];
    podium: { place: number; name: string }[];
    when: string;
}>();
const emit = defineEmits<{ open: [] }>();

const rounds = computed<Round[]>(() => props.bracket?.rounds ?? []);
const total = computed(() => props.bracket?.total ?? 0);
const fin = computed<Match | null>(() => rounds.value[rounds.value.length - 1]?.matches[0] ?? null);
const semis = computed<Match[]>(() => (total.value > 1 ? (rounds.value[rounds.value.length - 2]?.matches ?? []) : []));
const real = (m: Match) => !m.bye && !!m.a && !!m.b;
const all = computed(() => rounds.value.flatMap((r) => r.matches.map((m) => ({ ...m, round: r.round }))));
const sizeOf = (round: number) => 2 ** (total.value - round + 1);
const short = (round: number) => (sizeOf(round) === 2 ? trans('club.bracket.final') : `1/${sizeOf(round) / 2}`);

type State = 'before' | 'upcoming' | 'live' | 'final' | 'finalLive' | 'champion';
const state = computed<State>(() => {
    if (!props.bracket) return 'before';
    const f = fin.value;
    if (f && (f.a || f.b)) return f.winner ? 'champion' : f.live ? 'finalLive' : 'final';
    return all.value.some((m) => m.live) ? 'live' : 'upcoming';
});

// ---------- matches ----------
const live = computed(() => all.value.filter((m) => m.live && real(m)));
const pending = computed(() => all.value.filter((m) => real(m) && !m.winner && !m.live));
const results = computed(() => all.value.filter((m) => real(m) && m.winner).sort((a, b) => (b.at ?? 0) - (a.at ?? 0)));
const started = computed(() => results.value.length > 0);
const SHOW = 4;

// ---------- the progress strip: each round done / live / next / later ----------
const strip = computed(() => {
    let nextGiven = false;
    return rounds.value.map((r) => {
        const ms = r.matches.filter((m) => !m.bye);
        const done = ms.filter((m) => m.winner).length;
        const isLive = ms.some((m) => m.live);
        const allDone = ms.length > 0 && done === ms.length;
        let cls = allDone ? 'done' : isLive || done > 0 ? 'live' : 'idle';
        if (cls === 'idle' && !nextGiven && rounds.value.filter((x) => x.round < r.round).every((x) => x.matches.every((m) => m.bye || m.winner))) {
            cls = 'next';
        }
        if (cls !== 'done') nextGiven = nextGiven || cls === 'next' || cls === 'live';
        const isFinal = r.round === total.value;
        const sub = cls === 'done' ? (isFinal ? (fin.value?.score ?? '✓') : `${done}/${ms.length}`) : cls === 'live' ? `${trans('club.bracket.live').toLowerCase()} · ${done}/${ms.length}` : '';
        return { round: r.round, label: short(r.round), cls, sub, isFinal };
    });
});
const lineCls = (i: number) => {
    const a = strip.value[i]?.cls;
    const b = strip.value[i + 1]?.cls;
    return a === 'done' && b === 'done' ? 'done' : a === 'done' && b === 'live' ? 'half' : '';
};

// ---------- people ----------
const hue = (id: number) => (id * 67 + 24) % 360;
const ini = (s: { first?: string; last?: string; name?: string }) => `${(s.first || s.name || '?')[0]}${(s.last || '')[0] ?? ''}`.toUpperCase();
const scoreOf = (m: Match, s: Side | null) => {
    if (!s || !m.score) return m.live ? '0' : '–';
    const p = /^\s*(\d+)\s*\D+\s*(\d+)\s*$/.exec(m.score);
    if (!p) return '–';
    if (!m.winner) return String(s.id === m.a?.id ? +p[1] : +p[2]);
    return String(s.id === m.winner ? Math.max(+p[1], +p[2]) : Math.min(+p[1], +p[2]));
};
const rowCls = (m: Match, s: Side | null) => (!s ? 't' : m.winner ? (m.winner === s.id ? 'w' : 'l') : '');

// ---------- the Grand Final ----------
const sfName = (j: number) => `SF${j + 1}`;
/** a finalist side: the player, or (not known yet) the semi-final they come from */
function finSide(j: number) {
    const f = fin.value!;
    const s = j === 0 ? f.a : f.b;
    const semi = semis.value[j] ?? null;
    if (s) {
        // "won SF1 3:2"
        const sc = semi?.score ? `${scoreOf(semi, s)}:${scoreOf(semi, semi.a?.id === s.id ? semi.b : semi.a)}` : '';
        return { s, semi, note: semi ? trans('club.preview.won', { sf: sfName(j), score: sc }).trim() : '' };
    }
    return { s: null, semi, note: '' };
}
const champion = computed(() => (fin.value?.winner ? (fin.value.a?.id === fin.value.winner ? fin.value.a : fin.value.b) : null));
const runnerUp = computed(() => (fin.value?.winner ? (fin.value.a?.id === fin.value.winner ? fin.value.b : fin.value.a) : null));
const finalScore = computed(() => (fin.value && champion.value ? `${scoreOf(fin.value, champion.value)}:${scoreOf(fin.value, runnerUp.value)}` : ''));
const thirds = computed(() => props.podium.filter((p) => p.place === 3));

// ---------- before the draw ----------
const slots = computed(() => Math.max(0, Math.min(32, (props.maxPlayers ?? 0) - props.players.length)));
const pct = computed(() => (props.maxPlayers ? Math.min(100, Math.round((props.count / props.maxPlayers) * 100)) : 100));
</script>

<template>
    <div class="pv stage">
        <!-- A1: before the draw -->
        <div v-if="state === 'before'" class="calm" data-state="before">
            <div class="troph big">🏆</div>
            <span class="gf">{{ $t('club.preview.no_draw') }}</span>
            <p class="calm-p">{{ $t('club.preview.no_draw_note') }} · {{ when }}</p>
            <div class="reg">
                <div class="reg-h">
                    <span class="rl">{{ $t('club.preview.registered') }}</span>
                    <b>{{ count }}<span v-if="maxPlayers"> {{ $t('club.preview.of', { max: maxPlayers }) }}</span></b>
                </div>
                <div class="bar"><i :style="{ width: pct + '%' }" /></div>
                <div class="avrow">
                    <span v-for="p in players" :key="p.id" :class="['av', { me: p.me }]" :style="{ '--h': hue(p.id), '--s': '30px' }" :title="`${p.first} ${p.last}`">{{ ini(p) }}</span>
                    <span v-for="i in slots" :key="'s' + i" class="av slot" style="--s: 30px">+</span>
                </div>
            </div>
            <div v-if="maxPlayers && maxPlayers > count" class="calm-foot">{{ $t('club.preview.places_left', { n: maxPlayers - count }) }}</div>
        </div>

        <!-- A2 / A3: the matches of the rounds before the Final -->
        <template v-else-if="state === 'upcoming' || state === 'live'">
            <div class="hero-h" :data-state="state">
                <span v-if="state === 'live'" class="rl red"><i class="dot" />{{ $t('club.preview.live_now') }}</span>
                <span v-else class="gf">{{ started ? $t('club.preview.next_matches') : $t('club.preview.first_matches') }}</span>
                <small>{{ $t('club.preview.n_matches', { n: (state === 'live' ? live : pending).length }) }}</small>
            </div>
            <div class="mgrid">
                <div v-for="m in (state === 'live' ? live : pending).slice(0, SHOW)" :key="m.id" :class="['mc', { live: m.live }]">
                    <div class="mh">
                        <span>{{ short(m.round) }}</span><span v-if="m.live" class="livetag"><i class="dot" />LIVE</span>
                    </div>
                    <div v-for="(s, j) in [m.a, m.b]" :key="j" :class="['r', rowCls(m, s)]">
                        <span :class="['av', { me: s?.me }]" :style="{ '--h': hue(s!.id) }">{{ ini(s!) }}</span>
                        <div class="nm">
                            <div class="sn">{{ s!.last || s!.name }}</div>
                            <div class="fn">
                                {{ s!.first }}<template v-if="s!.rating"> · {{ s!.rating }}</template><span v-if="s!.me" class="you"> · {{ $t('club.preview.you') }}</span>
                            </div>
                        </div>
                        <span class="sc">{{ scoreOf(m, s) }}</span>
                    </div>
                </div>
            </div>
            <div v-if="(state === 'live' ? live : pending).length > SHOW" class="more">
                <button type="button" :class="['morelink', { gold: state !== 'live' }]" @click="emit('open')">
                    {{ state === 'live' ? $t('club.preview.more_live', { n: live.length - SHOW }) : $t('club.preview.more_matches', { n: pending.length - SHOW }) }}
                </button>
            </div>
            <div v-if="results.length" class="lres">
                <span class="rl">{{ $t('club.preview.latest') }}</span>
                <span v-for="m in results.slice(0, 3)" :key="m.id" class="it">
                    <b>{{ (m.winner === m.a?.id ? m.a : m.b)?.last }}</b
                    ><em>{{ m.score ? `${scoreOf(m, m.winner === m.a?.id ? m.a : m.b)}:${scoreOf(m, m.winner === m.a?.id ? m.b : m.a)}` : '›' }}</em
                    ><u>{{ (m.winner === m.a?.id ? m.b : m.a)?.last }}</u>
                </span>
            </div>
        </template>

        <!-- A4 / A5: the Grand Final -->
        <div v-else-if="state === 'final' || state === 'finalLive'" :class="['fa', { live: state === 'finalLive' }]" :data-state="state">
            <div class="fa-in">
                <div class="fa-top">
                    <span class="troph">🏆</span><span class="gf">{{ $t('club.bracket.grand_final') }}</span
                    ><span v-if="state === 'finalLive'" class="livetag big"><i class="dot" />LIVE</span><span v-else class="troph">🏆</span>
                </div>
                <div class="fa-grid">
                    <template v-for="j in [0, 1]" :key="j">
                        <div v-if="j === 1" class="vsb">
                            <span class="vs">VS</span><span class="when">{{ when }}</span>
                        </div>
                        <div class="fp">
                            <template v-if="finSide(j).s">
                                <span :class="['av', { me: finSide(j).s!.me, gw: state === 'final' }]" :style="{ '--h': hue(finSide(j).s!.id), '--s': '72px' }">{{ ini(finSide(j).s!) }}</span>
                                <div>
                                    <div class="sn">{{ finSide(j).s!.last || finSide(j).s!.name }}</div>
                                    <div class="fn">{{ finSide(j).s!.first }}<template v-if="finSide(j).s!.rating"> · {{ finSide(j).s!.rating }}</template></div>
                                </div>
                                <span v-if="state === 'finalLive'" :class="['bigsc', { lead: +scoreOf(fin!, finSide(j).s) > +scoreOf(fin!, finSide(1 - j).s) }]">{{ scoreOf(fin!, finSide(j).s) }}</span>
                                <span v-else-if="finSide(j).note" class="note">✓ {{ finSide(j).note }}</span>
                            </template>
                            <template v-else>
                                <div v-if="finSide(j).semi?.a && finSide(j).semi?.b" class="duo">
                                    <span class="av" :style="{ '--h': hue(finSide(j).semi!.a!.id), '--s': '50px' }">{{ ini(finSide(j).semi!.a!) }}</span
                                    ><span class="av" :style="{ '--h': hue(finSide(j).semi!.b!.id), '--s': '50px' }">{{ ini(finSide(j).semi!.b!) }}</span>
                                </div>
                                <span v-else class="av tbd" style="--s: 50px">?</span>
                                <div>
                                    <div class="sn">
                                        {{ finSide(j).semi?.a && finSide(j).semi?.b ? `${finSide(j).semi!.a!.first} / ${finSide(j).semi!.b!.first}` : $t('club.bracket.tbd') }}
                                    </div>
                                    <div class="fn">{{ $t('club.bracket.winner_of', { m: sfName(j) }) }}</div>
                                </div>
                                <span v-if="finSide(j).semi?.live" class="note lv"
                                    ><i class="dot" />{{ $t('club.preview.sf_live', { sf: sfName(j) }) }} {{ scoreOf(finSide(j).semi!, finSide(j).semi!.a) }}:{{
                                        scoreOf(finSide(j).semi!, finSide(j).semi!.b)
                                    }}</span
                                >
                            </template>
                        </div>
                    </template>
                </div>
                <div class="fa-ch">
                    <b>{{ $t('club.bracket.champion').toUpperCase() }}</b> · {{ $t('club.preview.to_be_decided') }}
                </div>
            </div>
        </div>

        <!-- A6: the champion -->
        <template v-else>
            <div class="fa" data-state="champion">
                <div class="fa-in champ-in">
                    <div class="fa-top" style="margin-bottom: 0">
                        <span class="troph">🏆</span><span class="gf">{{ $t('club.bracket.champion').toUpperCase() }}</span><span class="troph">🏆</span>
                    </div>
                    <div class="champ-av">
                        <span :class="['av', { me: champion!.me }]" :style="{ '--h': hue(champion!.id), '--s': '104px' }">{{ ini(champion!) }}</span>
                    </div>
                    <div class="champ-n">{{ champion!.name }}</div>
                    <div v-if="champion!.rating" class="fn">{{ champion!.rating }}</div>
                    <div v-if="runnerUp" class="final-res">
                        <span class="rl">{{ $t('club.bracket.grand_final') }}</span><b>{{ finalScore }}</b><span>vs {{ runnerUp.name }}</span>
                    </div>
                </div>
            </div>
            <div class="podium">
                <span v-if="runnerUp">🥈 <b>{{ runnerUp.last || runnerUp.name }}</b> {{ $t('club.preview.runner_up') }}</span>
                <span v-for="p in thirds" :key="p.name">🥉 <b>{{ p.name }}</b></span>
            </div>
        </template>

        <!-- the rounds, and the way to the whole bracket -->
        <div v-if="state !== 'before'" class="a-foot">
            <div class="prog">
                <template v-for="(x, i) in strip" :key="x.round">
                    <div :class="['ps', x.cls]">
                        <span class="b"
                            ><template v-if="x.cls === 'done'">✓</template><i v-else-if="x.cls === 'live'" class="dot" /><template v-else-if="x.isFinal">🏆</template></span
                        >
                        <span class="t">{{ x.label }}</span><span class="s">{{ x.sub }}</span>
                    </div>
                    <i v-if="i < strip.length - 1" :class="['pl', lineCls(i)]" />
                </template>
            </div>
            <button type="button" class="obtn" data-test="open-bracket" @click="emit('open')">{{ $t('club.preview.open') }} <span class="ic">⤢</span></button>
        </div>
    </div>
</template>

<style scoped>
/* "Option A" of the chosen design: the same tokens as the arena bracket */
.pv {
    --text: #eef5ec;
    --muted: #9aa69b;
    --faint: #657066;
    --accent: #ff9f43;
    --green: #5ef0a8;
    --red: #ff6b6b;
    --gold: linear-gradient(135deg, #ffd166, #ff8a3d);
    --disp: var(--display);
    container-type: inline-size;
    color: var(--text);
}
.stage { position: relative; isolation: isolate; border: 1px solid rgba(255, 159, 67, 0.2); border-radius: 22px; padding: 22px; overflow: hidden;
    background: radial-gradient(ellipse 45% 55% at 50% 50%, rgba(255, 159, 67, 0.16), transparent 70%), radial-gradient(ellipse 35% 60% at 0% 50%, rgba(94, 240, 168, 0.06), transparent 70%), radial-gradient(ellipse 35% 60% at 100% 50%, rgba(94, 240, 168, 0.06), transparent 70%), #070908; }
.stage::before { content: ''; position: absolute; inset: 0; z-index: -1; pointer-events: none;
    background-image: linear-gradient(rgba(220, 255, 220, 0.035) 1px, transparent 1px), linear-gradient(90deg, rgba(220, 255, 220, 0.035) 1px, transparent 1px); background-size: 36px 36px;
    -webkit-mask-image: radial-gradient(ellipse 70% 70% at 50% 50%, #000 25%, transparent 80%); mask-image: radial-gradient(ellipse 70% 70% at 50% 50%, #000 25%, transparent 80%); }
.rl { font: 600 10px var(--disp); letter-spacing: 0.14em; text-transform: uppercase; color: rgba(255, 209, 102, 0.55); }

.av { --s: 24px; --h: 30; position: relative; flex: none; width: var(--s); height: var(--s); border-radius: 50%; display: grid; place-items: center; background: hsl(var(--h) 38% 20%); color: hsl(var(--h) 85% 80%); border: 1.5px solid hsl(var(--h) 45% 38%); font: 800 calc(var(--s) * 0.36) / 1 var(--body); letter-spacing: 0.02em; }
.av.me::after { content: ''; position: absolute; right: -2px; bottom: -2px; width: max(7px, 26%); height: max(7px, 26%); border-radius: 50%; background: var(--green); box-shadow: 0 0 0 2px #070908, 0 0 8px var(--green); }
.av.tbd { background: rgba(255, 209, 102, 0.04); border: 1.5px dashed rgba(255, 209, 102, 0.4); color: rgba(255, 209, 102, 0.7); }
.av.gw { border-color: var(--green); box-shadow: 0 0 12px rgba(94, 240, 168, 0.45); }

.mc { position: relative; min-width: 0; background: rgba(18, 23, 19, 0.88); border: 1px solid rgba(255, 159, 67, 0.16); border-radius: 16px; padding: 5px; box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4); }
.mc .mh { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 3px 7px 5px; font: 500 9.5px var(--mono); color: var(--faint); white-space: nowrap; }
.mc .r { display: flex; align-items: center; gap: 8px; padding: 5px 7px; border-radius: 11px; }
.mc .nm { flex: 1; min-width: 0; }
.mc .sn { font: 800 12.5px/1.15 var(--body); text-transform: uppercase; letter-spacing: 0.02em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mc .fn { font: 400 10px/1.35 var(--mono); color: var(--faint); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.you { color: var(--green); }
.mc .sc { font: 500 14px var(--mono); color: var(--muted); min-width: 12px; text-align: right; }
.mc .r.w { background: rgba(94, 240, 168, 0.08); }
.mc .r.w .av { border-color: var(--green); box-shadow: 0 0 10px rgba(94, 240, 168, 0.45); }
.mc .r.w .sc { color: var(--green); text-shadow: 0 0 10px rgba(94, 240, 168, 0.65); }
.mc .r.l { opacity: 0.36; filter: grayscale(1); }
.mc.live { border-color: rgba(255, 107, 107, 0.55); box-shadow: 0 0 22px rgba(255, 107, 107, 0.22), 0 8px 30px rgba(0, 0, 0, 0.4); }
.mc.live .sc { color: var(--text); }
.livetag { display: inline-flex; align-items: center; gap: 6px; color: var(--red); font: 700 9px var(--disp); letter-spacing: 0.14em; }
.dot { display: inline-block; flex: none; width: 7px; height: 7px; border-radius: 50%; background: var(--red); animation: pulse 1.4s infinite; }
@keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(255, 107, 107, 0.75); } 70% { box-shadow: 0 0 0 8px rgba(255, 107, 107, 0); } 100% { box-shadow: 0 0 0 0 rgba(255, 107, 107, 0); } }

.obtn { display: inline-flex; align-items: center; justify-content: center; gap: 10px; height: 46px; padding: 0 22px; border: 0; border-radius: 999px; background: var(--gold); color: #1a1006; font: 700 12.5px var(--disp); letter-spacing: 0.04em; white-space: nowrap; cursor: pointer;
    box-shadow: 0 0 28px rgba(255, 159, 67, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.35); transition: transform 0.15s, box-shadow 0.2s; }
.obtn:hover { transform: translateY(-1px); box-shadow: 0 0 40px rgba(255, 159, 67, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.35); }
.obtn .ic { font-size: 16px; line-height: 1; }

.prog { display: flex; align-items: center; min-width: 0; }
.ps { display: flex; flex-direction: column; align-items: center; gap: 3px; flex: none; }
.ps .b { display: grid; place-items: center; width: 30px; height: 30px; border-radius: 50%; font: 700 12px var(--body); border: 1.5px solid rgba(255, 159, 67, 0.25); color: var(--faint); background: #0c100d; }
.ps .t { font: 600 10px var(--disp); letter-spacing: 0.1em; color: rgba(255, 209, 102, 0.6); text-transform: uppercase; }
.ps .s { font: 400 9.5px var(--mono); color: var(--faint); white-space: nowrap; min-height: 12px; }
.ps.done .b { border-color: var(--green); color: var(--green); box-shadow: 0 0 10px rgba(94, 240, 168, 0.35); background: rgba(94, 240, 168, 0.08); }
.ps.live .b { border-color: var(--red); box-shadow: 0 0 14px rgba(255, 107, 107, 0.4); background: rgba(255, 107, 107, 0.08); }
.ps.live .s { color: var(--red); }
.ps.next .b { border-style: dashed; border-color: rgba(255, 209, 102, 0.65); color: #ffd166; box-shadow: 0 0 12px rgba(255, 209, 102, 0.25); }
.ps.idle .b { opacity: 0.55; }
.ps.idle .t { color: rgba(255, 209, 102, 0.3); }
.pl { flex: 1; min-width: 10px; height: 2px; margin: 0 4px 26px; border-radius: 2px; background: rgba(255, 159, 67, 0.18); }
.pl.done { background: var(--green); box-shadow: 0 0 8px rgba(94, 240, 168, 0.6); }
.pl.half { background: linear-gradient(90deg, var(--green), var(--red)); }
.a-foot { display: flex; align-items: center; gap: 28px; margin-top: 20px; }
.a-foot .prog { flex: 1; }

/* the Grand Final */
.fa { position: relative; border-radius: 20px; padding: 1.5px; background: var(--gold); box-shadow: 0 0 50px rgba(255, 159, 67, 0.28); }
.fa-in { border-radius: 19px; background: radial-gradient(ellipse 60% 90% at 50% 0%, rgba(255, 159, 67, 0.14), transparent 70%), rgba(12, 16, 13, 0.94); padding: 22px 22px 20px; }
.fa-top { display: flex; justify-content: center; align-items: center; gap: 10px; margin-bottom: 18px; }
.gf { font: 800 13px var(--disp); letter-spacing: 0.18em; text-transform: uppercase; background: var(--gold); -webkit-background-clip: text; background-clip: text; color: transparent; }
.troph { font-size: 20px; filter: drop-shadow(0 0 10px rgba(255, 209, 102, 0.6)); }
.fa-grid { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); align-items: center; gap: 16px; }
.fp { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 8px; min-width: 0; }
.fp .sn { font: 800 18px/1.1 var(--body); text-transform: uppercase; letter-spacing: 0.03em; overflow-wrap: anywhere; }
.fp .fn { font: 400 11px var(--mono); color: var(--faint); }
.note { font: 500 11px var(--mono); color: var(--green); padding: 4px 10px; border-radius: 999px; background: rgba(94, 240, 168, 0.08); border: 1px solid rgba(94, 240, 168, 0.22); white-space: nowrap; }
.note.lv { color: var(--red); background: rgba(255, 107, 107, 0.08); border-color: rgba(255, 107, 107, 0.3); display: inline-flex; align-items: center; gap: 6px; }
.vsb { display: flex; flex-direction: column; align-items: center; gap: 6px; }
.vs { font: 800 34px/1 var(--disp); color: var(--accent); text-shadow: 0 0 22px rgba(255, 159, 67, 0.75); }
.when { font: 500 11px var(--mono); color: var(--muted); text-align: center; }
.duo { display: flex; }
.duo .av + .av { margin-left: -14px; }
.fa-ch { margin-top: 18px; padding-top: 14px; border-top: 1px dashed rgba(255, 209, 102, 0.18); display: flex; justify-content: center; align-items: center; gap: 8px; font: 500 11px var(--mono); color: var(--faint); }
.fa-ch b { font: 700 10px var(--disp); letter-spacing: 0.14em; color: rgba(255, 209, 102, 0.7); }
.fa.live { background: linear-gradient(135deg, #ffd166, #ff8a3d 55%, #ff6b6b); animation: finglow 1.6s ease-in-out infinite; }
@keyframes finglow { 0%, 100% { box-shadow: 0 0 50px rgba(255, 159, 67, 0.28); } 50% { box-shadow: 0 0 70px rgba(255, 107, 107, 0.42), 0 0 0 4px rgba(255, 107, 107, 0.12); } }
.livetag.big { font-size: 11px; padding: 4px 10px; border-radius: 999px; background: rgba(255, 107, 107, 0.12); border: 1px solid rgba(255, 107, 107, 0.45); }
.bigsc { font: 600 44px/1 var(--mono); color: var(--muted); }
.bigsc.lead { color: var(--green); text-shadow: 0 0 18px rgba(94, 240, 168, 0.6); }

/* the champion */
.champ-in { text-align: center; padding-top: 26px; }
.champ-av { display: inline-block; margin: 14px 0 12px; border-radius: 50%; padding: 4px; background: var(--gold); box-shadow: 0 0 50px rgba(255, 209, 102, 0.55), 0 0 110px rgba(255, 159, 67, 0.3); }
.champ-av .av { border: 3px solid #0c100d; }
.champ-n { font: 800 24px/1.1 var(--disp); text-transform: uppercase; background: var(--gold); -webkit-background-clip: text; background-clip: text; color: transparent; }
.champ-in .fn { font: 400 11px var(--mono); color: var(--faint); margin-top: 6px; }
.final-res { display: inline-flex; align-items: center; gap: 10px; margin-top: 16px; padding: 8px 16px; border-radius: 999px; background: rgba(94, 240, 168, 0.07); border: 1px solid rgba(94, 240, 168, 0.25); font: 500 12px var(--body); color: var(--muted); }
.final-res b { font: 600 16px var(--mono); color: var(--green); text-shadow: 0 0 10px rgba(94, 240, 168, 0.6); }
.podium { display: flex; justify-content: center; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
.podium span { display: inline-flex; align-items: center; gap: 8px; padding: 7px 12px; border-radius: 999px; background: rgba(18, 23, 19, 0.88); border: 1px solid rgba(255, 159, 67, 0.14); font: 500 11px var(--mono); color: var(--faint); white-space: nowrap; }
.podium b { font: 800 11.5px var(--body); color: var(--text); text-transform: uppercase; }

/* before the draw */
.calm { border-radius: 20px; border: 1px solid rgba(255, 159, 67, 0.3); background: radial-gradient(ellipse 60% 80% at 50% 0%, rgba(255, 159, 67, 0.1), transparent 70%), rgba(12, 16, 13, 0.92); padding: 26px 24px; text-align: center; }
.troph.big { font-size: 38px; opacity: 0.9; filter: drop-shadow(0 0 18px rgba(255, 209, 102, 0.45)) grayscale(0.2); }
.calm .gf { display: block; margin-top: 10px; font-size: 15px; }
.calm-p { color: var(--muted); margin: 8px 0 20px; font-size: 13px; }
.reg { max-width: 640px; margin: 0 auto; text-align: left; }
.reg-h { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px; }
.reg-h b { font: 600 18px var(--mono); color: var(--green); }
.reg-h b span { color: var(--faint); font-size: 13px; font-weight: 400; }
.bar { height: 6px; border-radius: 6px; background: rgba(255, 255, 255, 0.06); overflow: hidden; }
.bar i { display: block; height: 100%; border-radius: 6px; background: linear-gradient(90deg, #5ef0a8, #ffd166); box-shadow: 0 0 12px rgba(94, 240, 168, 0.5); }
.avrow { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 14px; justify-content: center; }
.av.slot { background: transparent; border: 1.5px dashed rgba(255, 255, 255, 0.15); color: var(--faint); font-weight: 500; }
.calm-foot { margin-top: 18px; text-align: center; font: 500 11px var(--mono); color: var(--faint); }

/* the matches */
.hero-h { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
.hero-h .rl.red { color: var(--red); display: flex; align-items: center; gap: 8px; font-size: 12px; letter-spacing: 0.16em; }
.hero-h small { font: 500 11px var(--mono); color: var(--faint); }
.mgrid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.more { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 12px; flex-wrap: wrap; }
.morelink { border: 0; background: none; padding: 6px 0; color: var(--red); font: 700 11px var(--disp); letter-spacing: 0.06em; white-space: nowrap; cursor: pointer; }
.morelink.gold { color: #ffd166; }
.lres { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 14px; margin-top: 14px; padding-top: 12px; border-top: 1px dashed rgba(255, 209, 102, 0.16); font: 500 11.5px var(--mono); color: var(--faint); }
.lres .rl { font-size: 9.5px; }
.lres .it { white-space: nowrap; }
.lres b { font: 800 11.5px var(--body); color: var(--green); text-transform: uppercase; text-shadow: 0 0 10px rgba(94, 240, 168, 0.35); }
.lres em { font-style: normal; color: var(--text); margin: 0 6px; }
.lres u { text-decoration: none; font: 700 11.5px var(--body); text-transform: uppercase; color: var(--faint); }

/* phones */
@container (max-width: 560px) {
    .stage { padding: 14px; border-radius: 18px; }
    .obtn { width: 100%; }
    .fa-in { padding: 16px 12px; }
    .fa-grid { gap: 8px; }
    .fp .sn { font-size: 13px; }
    .note { font-size: 9.5px; padding: 3px 7px; }
    .vs { font-size: 24px; }
    .when { font-size: 10px; max-width: 70px; }
    .fp .fn { font-size: 9.5px; }
    .fa-ch { flex-wrap: wrap; text-align: center; gap: 4px; }
    .fa-grid .av { --s: 52px !important; }
    .a-foot { flex-direction: column; align-items: stretch; gap: 16px; }
    .mgrid { grid-template-columns: 1fr; gap: 8px; }
    .calm { padding: 20px 14px; }
    .calm .gf { font-size: 12px; }
    .avrow .av { --s: 26px !important; }
    .bigsc { font-size: 32px; }
    .champ-n { font-size: 18px; }
    .champ-av .av { --s: 84px !important; }
    .final-res { font-size: 11px; padding: 7px 12px; }
}
</style>
