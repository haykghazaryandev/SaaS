<script setup lang="ts">
import type { BookingRow } from '@/composables/useOwner';
import { hhmm } from '@/composables/useShared';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

// The owner's schedule as an hour grid (style A1): hours go down, every column is one table / field on one day,
// a booking is a block from its start to its end (blue booked, lime playing now, orange request, violet every week,
// grey finished), every empty time is a green dashed "+ Free" block with its times (tap = new booking there),
// outside the opening hours is striped, a red line is now. Day view: a column per court; week: 7 days × courts.
export type Cell = 'booked' | 'live' | 'req' | 'reg' | 'done' | 'off';
export interface Col {
    key: string;
    date: string;
    field: number;
    head: string;
    /** under the head: "● playing → 15:30" / "free → 16:00" (today) or the court's short name (week) */
    sub?: string;
    subCls?: string;
    /** week: the day's name over its first column */
    group?: string;
}
export interface GridClosure {
    id: number;
    field_id: number | null;
    date: string;
    start: number;
    end: number;
    reason: string | null;
}
const props = defineProps<{
    cols: Col[];
    bookings: BookingRow[];
    closures: GridClosure[];
    hours: Record<string, [number, number] | null>;
    from: number;
    to: number;
    now: number;
    today: string;
    /** the place's time step in hours (a free time shorter than this is not shown) */
    step: number;
    /** px per hour */
    pxh: number;
    compact?: boolean;
    stateOf: (b: BookingRow) => Cell;
    label: (b: BookingRow) => string;
    /** the place's colours (Settings); missing ones: the default */
    colors?: Partial<Record<string, string>> | null;
}>();
const colorVars = computed(() => Object.fromEntries(Object.entries(props.colors ?? {}).filter(([, v]) => !!v).map(([k, v]) => [`--c-${k}`, v as string])));
const emit = defineEmits<{ open: [id: number]; free: [field: number, date: string, start: number] }>();

const y = (h: number) => (h - props.from) * props.pxh;
const ticks = computed(() => {
    const out: { h: number; full: boolean }[] = [];
    for (let h = Math.ceil(props.from * 2) / 2; h <= props.to + 1e-9; h += 0.5) out.push({ h, full: Math.abs(h - Math.round(h)) < 1e-9 });
    return out;
});
const isPast = (date: string, end: number) => date < props.today || (date === props.today && end <= props.now + 1e-9);

interface Piece {
    key: string;
    kind: 'b' | 'free' | 'shut' | 'closed';
    top: number;
    h: number;
    start: number;
    end: number;
    b?: BookingRow;
    cell?: Cell;
    reason?: string | null;
    past?: boolean;
    /** overlapping bookings share the column: this one's place and how many */
    lane?: number;
    lanes?: number;
}
function piecesOf(c: Col): Piece[] {
    const out: Piece[] = [];
    const open = props.hours[c.date];
    // outside the opening hours: striped
    const [o, e] = open ?? [props.to, props.to];
    if (o > props.from) out.push({ key: 's0', kind: 'shut', top: y(props.from), h: y(o) - y(props.from), start: props.from, end: o });
    if (e < props.to) out.push({ key: 's1', kind: 'shut', top: y(Math.max(e, props.from)), h: y(props.to) - y(Math.max(e, props.from)), start: e, end: props.to });
    const busy: [number, number][] = [];
    for (const b of props.bookings) {
        if (b.field_id !== c.field || b.date !== c.date) continue;
        const cell = props.stateOf(b);
        if (cell !== 'off') busy.push([b.start, b.end]);
        out.push({ key: 'b' + b.id, kind: 'b', top: y(b.start), h: y(b.end) - y(b.start), start: b.start, end: b.end, b, cell });
    }
    for (const z of props.closures) {
        if (z.date !== c.date || (z.field_id !== null && z.field_id !== c.field)) continue;
        busy.push([z.start, z.end]);
        out.push({ key: 'z' + z.id + z.date, kind: 'closed', top: y(z.start), h: y(z.end) - y(z.start), start: z.start, end: z.end, reason: z.reason });
    }
    // the free times: the opening hours minus bookings and closed times, at least one step long
    if (open) {
        busy.sort((a, b) => a[0] - b[0]);
        let t = o;
        const gap = (s: number, f: number) => {
            if (f - s >= props.step - 1e-9) out.push({ key: 'f' + s, kind: 'free', top: y(s), h: y(f) - y(s), start: s, end: f, past: isPast(c.date, f) });
        };
        for (const [s, f] of busy) {
            if (s > t) gap(t, Math.min(s, e));
            t = Math.max(t, f);
        }
        if (t < e) gap(t, e);
    }
    // bookings that overlap (a walk-in over a booking, a request over another) side by side
    const bs = out.filter((x) => x.kind === 'b').sort((a, b) => a.start - b.start || b.end - a.end);
    let group: Piece[] = [];
    let groupEnd = -1;
    const flush = () => {
        const ends: number[] = [];
        for (const x of group) {
            let i = ends.findIndex((e) => e <= x.start + 1e-9);
            if (i < 0) i = ends.push(0) - 1;
            ends[i] = x.end;
            x.lane = i;
        }
        group.forEach((x) => (x.lanes = ends.length));
        group = [];
    };
    for (const x of bs) {
        if (x.start >= groupEnd - 1e-9) flush();
        group.push(x);
        groupEnd = Math.max(groupEnd, x.end);
    }
    flush();
    return out;
}
const side = (p: Piece) =>
    p.lanes && p.lanes > 1 ? { left: `calc(${(100 * (p.lane ?? 0)) / p.lanes}% + 2px)`, right: 'auto', width: `calc(${100 / p.lanes}% - 4px)` } : {};
const columns = computed(() => props.cols.map((c) => ({ c, pieces: piecesOf(c) })));
// the now line over today's columns
const nowCols = computed(() => {
    const idx = props.cols.map((c, i) => (c.date === props.today ? i : -1)).filter((i) => i >= 0);
    return idx.length && props.now >= props.from && props.now <= props.to ? { a: idx[0], b: idx[idx.length - 1] } : null;
});
const dur = (h: number) => {
    const m = Math.round(h * 60);
    return m >= 60 ? `${Math.floor(m / 60)}h${m % 60 ? ' ' + (m % 60) : ''}` : `${m}′`;
};
function freeAt(c: Col, p: Piece) {
    if (p.past) return;
    // today: from the next step after now
    const start = c.date === props.today ? Math.max(p.start, Math.ceil((props.now + 1e-9) / props.step) * props.step) : p.start;
    emit('free', c.field, c.date, Math.min(start, p.end - props.step));
}

// opens scrolled to now (today) or to the first booking
const scroller = ref<HTMLElement>();
function scrollToNow() {
    const el = scroller.value;
    if (!el) return;
    const target = props.cols.some((c) => c.date === props.today) ? y(props.now) - 90 : 0;
    const box = el.closest('.hg-scroll') as HTMLElement | null;
    if (box) box.scrollTop = Math.max(0, target);
    else window.scrollTo({ top: Math.max(0, el.getBoundingClientRect().top + window.scrollY + target - 160) });
}
onMounted(() => nextTick(scrollToNow));
watch(() => props.cols.map((c) => c.key).join(), () => nextTick(scrollToNow));
defineExpose({ scrollToNow });
</script>

<template>
    <div :class="['hg', { compact }]" :style="{ '--n': cols.length, ...colorVars }">
        <!-- the column heads (sticky): the court, and what is on it now -->
        <div class="hg-heads">
            <span class="hg-gut" />
            <span v-for="(c, i) in cols" :key="c.key" :class="['hg-head', { today: c.date === today, first: i === 0 || cols[i - 1].date !== c.date }]">
                <small v-if="c.group" class="hg-group">{{ c.group }}</small>
                <b>{{ c.head }}</b>
                <small v-if="c.sub" :class="['hg-sub', c.subCls]">{{ c.sub }}</small>
            </span>
        </div>
        <div ref="scroller" class="hg-body" :style="{ height: y(to) + 10 + 'px' }">
            <div v-for="t in ticks" :key="t.h" :class="['hg-line', { half: !t.full }]" :style="{ top: y(t.h) + 'px' }">
                <span v-if="t.full">{{ hhmm(t.h) }}</span>
            </div>
            <div class="hg-cols">
                <div v-for="({ c, pieces }, i) in columns" :key="c.key" :class="['hg-col', { first: i > 0 && cols[i - 1].date !== c.date }]">
                    <template v-for="p in pieces" :key="p.key">
                        <div v-if="p.kind === 'shut'" class="hg-shut" :style="{ top: p.top + 'px', height: p.h + 'px' }" />
                        <div v-else-if="p.kind === 'closed'" class="hg-b closed" :style="{ top: p.top + 1 + 'px', height: p.h - 2 + 'px' }" :title="p.reason ?? ''">
                            <b v-if="p.h > 18">{{ p.reason || $t('common.closed') }}</b>
                        </div>
                        <button
                            v-else-if="p.kind === 'free'"
                            type="button"
                            :class="['hg-free', { past: p.past, tiny: p.h < 26 }]"
                            :style="{ top: p.top + 1 + 'px', height: p.h - 2 + 'px' }"
                            :disabled="p.past"
                            :aria-label="`${$t('owner.schedule.free_row')} ${hhmm(p.start)}–${hhmm(p.end)}`"
                            data-test="free"
                            @click="freeAt(c, p)"
                        >
                            <template v-if="p.h >= 40 && !compact"
                                ><b>+ {{ $t('owner.schedule.free_row') }}</b><small>{{ hhmm(p.start) }}–{{ hhmm(p.end) }}</small></template
                            >
                            <!-- a short free time (15 / 30 min): its start and length on one line -->
                            <template v-else-if="!compact"
                                ><small class="one">+ {{ hhmm(p.start) }} · {{ dur(p.end - p.start) }}</small></template
                            >
                            <b v-else>+</b>
                        </button>
                        <div
                            v-else
                            :class="['hg-b', p.cell, { tall: p.h > 44, split: (p.lanes ?? 1) > 1 }]"
                            :style="{ top: p.top + 1 + 'px', height: p.h - 2 + 'px', ...side(p) }"
                            role="button"
                            tabindex="0"
                            data-test="block"
                            @click="emit('open', p.b!.id)"
                            @keydown.enter="emit('open', p.b!.id)"
                        >
                            <b
                                ><i v-if="p.cell === 'live'" class="ld" /><span v-if="p.b!.no_shows_here" class="nsw" :title="$t('owner.booking.no_shows_here', { n: p.b!.no_shows_here })"
                                    >⚠{{ p.b!.no_shows_here }}</span
                                >{{ p.b!.weekly ? '↻ ' : '' }}{{ compact ? label(p.b!).split(' ')[0] : label(p.b!) }}</b
                            >
                            <small v-if="p.h > 28 && !compact">{{ hhmm(p.start) }}–{{ hhmm(p.end) }}</small>
                            <em v-if="p.h > 44 && !compact && (p.cell === 'req' || p.cell === 'live')">{{
                                p.cell === 'req' ? $t('owner.schedule.request') : $t('owner.schedule.playing')
                            }}</em>
                        </div>
                    </template>
                </div>
                <div
                    v-if="nowCols"
                    class="hg-now"
                    :data-t="hhmm(now)"
                    :style="{ top: y(now) + 'px', left: `calc(${nowCols.a} * 100% / var(--n))`, width: `calc(${nowCols.b - nowCols.a + 1} * 100% / var(--n))` }"
                />
            </div>
        </div>
    </div>
</template>

<style scoped>
.hg { --gut: 46px; --c-booked: #5cc8ff; --c-live: #c8ff3d; --c-req: #ffb547; --c-reg: #b69cff; --c-done: #6f7a70; --c-free: #5ef0a8; min-width: 0; }
.hg-heads { display: grid; grid-template-columns: var(--gut) repeat(var(--n), minmax(0, 1fr)); position: sticky; top: var(--hg-top, 0px); z-index: 7; background: var(--bg); border-bottom: 1px solid var(--line); }
.hg-head { text-align: center; padding: 6px 2px; min-width: 0; display: flex; flex-direction: column; align-items: center; }
.hg-head b { font-size: 12.5px; font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
.hg-group { font-size: 11.5px; font-weight: 800; color: var(--muted); white-space: nowrap; }
.hg-head.today .hg-group, .hg-head.today b { color: var(--accent); }
.compact .hg-head:not(.first) .hg-group { visibility: hidden; }
.hg-sub { font: 700 10.5px var(--mono); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; color: var(--muted); }
.hg-sub.live { color: var(--c-live); }
.hg-sub.free { color: var(--c-free); }
.hg-sub.booked { color: var(--c-booked); }
.hg-sub.closed { color: var(--faint); }
.hg-body { position: relative; }
.hg-line { position: absolute; left: 0; right: 0; border-top: 1px solid var(--line); }
.hg-line.half { border-top: 1px dashed rgba(220, 255, 220, 0.035); }
.hg-line span { position: absolute; left: 0; top: -8px; font: 600 11px var(--mono); color: var(--faint); background: var(--bg); padding-right: 4px; }
.hg-cols { position: absolute; top: 0; bottom: 0; left: var(--gut); right: 0; display: grid; grid-template-columns: repeat(var(--n), minmax(0, 1fr)); }
.hg-col { position: relative; }
.hg-col.first { border-left: 1px solid var(--line-2); }
.hg-shut { position: absolute; left: 2px; right: 2px; background: repeating-linear-gradient(135deg, rgba(255, 255, 255, 0.04) 0 6px, transparent 6px 12px); border-radius: 6px; }
.hg-b { position: absolute; left: 3px; right: 3px; border-radius: 8px; padding: 3px 6px; font-size: 11.5px; line-height: 1.25; overflow: hidden; cursor: pointer; border-left: 3px solid; text-align: left; }
.hg-b b { display: flex; align-items: center; gap: 4px; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hg-b small { display: block; font: 600 10.5px var(--mono); opacity: 0.85; white-space: nowrap; }
.hg-b em { display: block; font-style: normal; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; }
.hg-b.booked { background: color-mix(in srgb, var(--c-booked) 18%, transparent); border-color: var(--c-booked); color: color-mix(in srgb, var(--c-booked) 25%, #fff); }
.hg-b.live { background: color-mix(in srgb, var(--c-live) 20%, transparent); border-color: var(--c-live); color: color-mix(in srgb, var(--c-live) 25%, #fff); }
.hg-b.req { background: color-mix(in srgb, var(--c-req) 18%, transparent); border-color: var(--c-req); color: color-mix(in srgb, var(--c-req) 25%, #fff); }
.hg-b.reg { background: color-mix(in srgb, var(--c-reg) 20%, transparent); border-color: var(--c-reg); color: color-mix(in srgb, var(--c-reg) 25%, #fff); }
.hg-b.done { background: color-mix(in srgb, var(--c-done) 18%, transparent); border-color: var(--c-done); color: color-mix(in srgb, var(--c-done) 45%, #fff); }
.hg-b.off { background: transparent; border: 1px dashed var(--line-2); color: var(--faint); text-decoration: line-through; }
.hg-b.closed { cursor: default; background: repeating-linear-gradient(135deg, rgba(255, 255, 255, 0.07) 0 6px, transparent 6px 12px); border-color: #7d867e; color: var(--muted); }
.nsw { flex: none; font-size: 10px; font-weight: 800; color: #1d0f00; background: var(--c-req); border-radius: 4px; padding: 0 3px; }
.ld { width: 7px; height: 7px; border-radius: 50%; background: currentColor; flex: none; animation: hgp 1.4s infinite; }
@keyframes hgp { 50% { opacity: 0.3; } }
.hg-free { position: absolute; left: 3px; right: 3px; border-radius: 8px; border: 1.5px dashed color-mix(in srgb, var(--c-free) 50%, transparent); background: color-mix(in srgb, var(--c-free) 8%, transparent); color: var(--c-free); display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; cursor: pointer; padding: 0 2px; font: inherit; overflow: hidden; }
.hg-free:hover:not(:disabled) { background: color-mix(in srgb, var(--c-free) 16%, transparent); }
.hg-free b { font-size: 12px; }
.hg-free small { font: 600 10.5px var(--mono); }
.hg-free small.one { white-space: nowrap; font-size: 10px; }
.hg-free.tiny { border-width: 1px; }
.hg-free.past { opacity: 0.28; cursor: default; }
.hg-now { position: absolute; height: 2px; background: #ff6b6b; z-index: 5; pointer-events: none; }
.hg-now::before { content: attr(data-t); position: absolute; left: calc(-1 * var(--gut)); top: -9px; background: #ff6b6b; color: #fff; font: 700 10px var(--mono); padding: 2px 4px; border-radius: 5px; }
.hg-b.split { padding: 2px 4px; }
.hg-b.split em { display: none; }
.compact .hg-b { padding: 2px 3px; border-left-width: 2px; border-radius: 5px; }
.compact .hg-b b { font-size: 10.5px; }
.compact .hg-free b { font-size: 11px; opacity: 0.7; }
.compact .hg-head b { font-size: 11px; color: var(--muted); }
</style>
