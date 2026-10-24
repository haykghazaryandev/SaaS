<script setup lang="ts">
import Avatar from '@/components/Avatar.vue';
import Icon from '@/components/Icon.vue';
import { wdName } from '@/composables/usePlayer';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import type { GamePerson } from '@/types/games';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { trans, transChoice } from '@/lib/i18n';
import { computed, nextTick, ref } from 'vue';

// Create a game: N vs N, district / place, time window, note, and friends to invite.
const props = defineProps<{
    friends: GamePerson[];
    venues: { id: number; name: string; district: string }[];
    districts: string[];
    today: string;
    days?: { date: string; k: number; wd: number; day: number; weekend: boolean }[];
    active: number;
    maxActive: number;
    friendsUrl: string | null;
}>();

const form = useForm({
    players_per_side: 5,
    district: '',
    venue_id: '' as number | '',
    date: '',
    from: '',
    to: '',
    note: '',
    invite: [] as number[],
});

const total = computed(() => form.players_per_side * 2);
const minToOpen = computed(() => Math.max(2, Math.ceil(total.value * 0.3)));
const need = computed(() => minToOpen.value - 1);
const enough = computed(() => form.invite.length >= need.value);
const tooMany = computed(() => form.invite.length > total.value - 1);

const q = ref('');
const shown = computed(() => {
    const s = q.value.trim().toLowerCase();
    return s ? props.friends.filter((f) => f.name.toLowerCase().includes(s)) : props.friends;
});
const allShown = computed(() => shown.value.length > 0 && shown.value.every((f) => form.invite.includes(f.id)));
function toggle(id: number) {
    const i = form.invite.indexOf(id);
    if (i >= 0) form.invite.splice(i, 1);
    else form.invite.push(id);
}
function toggleAll() {
    if (allShown.value) form.invite = form.invite.filter((id) => !shown.value.some((f) => f.id === id));
    else form.invite = [...new Set([...form.invite, ...shown.value.map((f) => f.id)])];
}
const step = (d: number) => (form.players_per_side = Math.min(11, Math.max(3, form.players_per_side + d)));
const QUICK = [5, 6, 7, 8, 11];
const venuesShown = computed(() => (form.district ? props.venues.filter((v) => v.district === form.district) : props.venues));
const limitReached = computed(() => props.active >= props.maxActive);
// server errors that are not form fields ("game")
const errs = computed(() => form.errors as Record<string, string | undefined>);
// G2: why "Create" is disabled, shown right above it
const why = computed(() => {
    if (limitReached.value) return trans('social.error.too_many_games', { n: String(props.maxActive) });
    if (tooMany.value) return trans('games.create.too_many', { n: String(total.value - 1) });
    if (!enough.value) return transChoice('games.create.pick_more', need.value - form.invite.length, { n: String(need.value - form.invite.length) });
    return '';
});

// ---------- when: day chips, then "From" / "To" time chips (whole hours) ----------
const dayList = computed(() => props.days ?? []);
const pad = (h: number) => String(h).padStart(2, '0') + ':00';
const nowHour = () => Number(new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Yerevan', hour: '2-digit', hour12: false }).format(new Date())) % 24;
const fromHours = computed(() => {
    const first = form.date === props.today ? Math.max(7, nowHour() + 1) : 7;
    return Array.from({ length: Math.max(0, 24 - first) }, (_, i) => pad(first + i));
});
const toHours = computed(() => {
    const start = form.from ? Number(form.from.slice(0, 2)) + 1 : 8;
    return Array.from({ length: Math.max(0, 25 - start) }, (_, i) => pad(start + i));
});
function pickDay(d: string) {
    form.date = d;
    if (form.from && !fromHours.value.includes(form.from)) form.from = '';
    if (form.to && !toHours.value.includes(form.to)) form.to = '';
}
function pickFrom(t: string) {
    form.from = form.from === t ? '' : t;
    if (form.to && (!form.from || form.to <= form.from)) form.to = '';
    revealPicked();
}
// keep the picked time chips in view inside their scrolling rows
function revealPicked() {
    nextTick(() => document.querySelectorAll('.times .chip[aria-pressed="true"]').forEach((el) => el.scrollIntoView({ block: 'nearest', inline: 'center' })));
}

function submit() {
    form.transform((d) => ({
        ...d,
        district: d.district || null,
        venue_id: d.venue_id || null,
        date: d.date || null,
        from: d.date && d.from ? d.from : null,
        to: d.date && d.to ? d.to : null,
        note: d.note.trim() || null,
    })).post(route('games.store'), { preserveScroll: true });
}
</script>

<template>
    <PlayerLayout>
        <Head :title="$t('common.create_game')" />
        <Link class="back" :href="route('games.index')"><Icon name="back" size="sm" />{{ $t('games.show.back') }}</Link>
        <section class="chead">
            <h1 class="page-title">{{ $t('common.create_game') }}</h1>
            <p class="muted">{{ $t('games.create.lead') }}</p>
        </section>

        <div v-if="limitReached" class="alert">
            <Icon name="clock" size="sm" />{{ $t('social.error.too_many_games', { n: String(maxActive) }) }}
            <Link :href="route('games.index', { tab: 'mine' })" class="lnk">{{ $t('games.index.tab_mine') }}</Link>
        </div>

        <form class="cgrid" @submit.prevent="submit">
            <div class="col">
                <!-- size: stepper + quick chips -->
                <section class="card blk">
                    <h3>{{ $t('games.create.per_side') }}</h3>
                    <div class="sizer">
                        <button type="button" class="stp" :aria-label="$t('games.create.less')" :disabled="form.players_per_side <= 3" @click="step(-1)">−</button>
                        <div class="big">
                            <b>{{ form.players_per_side }}×{{ form.players_per_side }}</b>
                            <small>{{ $t('games.create.total', { n: String(total) }) }}</small>
                        </div>
                        <button type="button" class="stp" :aria-label="$t('games.create.more')" :disabled="form.players_per_side >= 11" @click="step(1)">+</button>
                    </div>
                    <div class="chips quick" role="group" :aria-label="$t('games.create.per_side')">
                        <button v-for="n in QUICK" :key="n" type="button" class="chip" :aria-pressed="form.players_per_side === n" @click="form.players_per_side = n">
                            <i :class="['field-dot', n >= 7 ? 'big' : 'small']" />{{ n }}×{{ n }}
                        </button>
                    </div>
                    <div class="opens"><Icon name="users" size="sm" />{{ $t('games.create.needs', { n: String(minToOpen), f: String(need) }) }}</div>
                    <div v-if="form.errors.players_per_side" class="err">{{ form.errors.players_per_side }}</div>
                </section>

                <!-- details -->
                <section class="card blk">
                    <h3>{{ $t('games.create.details') }}</h3>
                    <div class="two">
                        <div class="field">
                            <label for="dst">{{ $t('common.district') }}</label>
                            <select id="dst" v-model="form.district" class="input" @change="form.venue_id = ''">
                                <option value="">{{ $t('player.home.any_district') }}</option>
                                <option v-for="d in districts" :key="d" :value="d">{{ $t(`common.districts.${d}`) }}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="ven">{{ $t('games.create.place') }}</label>
                            <select id="ven" v-model="form.venue_id" class="input">
                                <option value="">{{ $t('games.create.any_place') }}</option>
                                <option v-for="v in venuesShown" :key="v.id" :value="v.id">{{ v.name }} · {{ $t(`common.districts.${v.district}`) }}</option>
                            </select>
                            <div v-if="form.errors.venue_id" class="err">{{ form.errors.venue_id }}</div>
                        </div>
                    </div>
                    <div class="field">
                        <label>{{ $t('games.create.when') }}</label>
                        <div class="days" role="group" :aria-label="$t('common.day')">
                            <button type="button" class="day any" :aria-pressed="!form.date" @click="(form.date = ''), (form.from = ''), (form.to = '')"><b>{{ $t('common.any_day') }}</b></button>
                            <button v-for="d in dayList" :key="d.date" type="button" :class="['day', { we: d.weekend }]" :aria-pressed="form.date === d.date" @click="pickDay(d.date)">
                                <small>{{ d.k === 0 ? $t('common.today') : wdName(d.wd) }}</small><b>{{ d.day }}</b>
                            </button>
                        </div>
                        <small v-if="!form.date" class="hint">{{ $t('games.create.when_hint') }}</small>
                    </div>
                    <template v-if="form.date">
                        <div class="field">
                            <label>{{ $t('common.from') }}</label>
                            <div class="chips times" role="group" :aria-label="$t('common.from')">
                                <button type="button" class="chip" :aria-pressed="!form.from" @click="(form.from = ''), (form.to = '')">{{ $t('common.any_time') }}</button>
                                <button v-for="t in fromHours" :key="t" type="button" class="chip tm" :aria-pressed="form.from === t" @click="pickFrom(t)">{{ t }}</button>
                            </div>
                        </div>
                        <div v-if="form.from" class="field">
                            <label>{{ $t('common.to') }}</label>
                            <div class="chips times" role="group" :aria-label="$t('common.to')">
                                <button type="button" class="chip" :aria-pressed="!form.to" @click="form.to = ''">{{ $t('games.create.no_end') }}</button>
                                <button v-for="t in toHours" :key="t" type="button" class="chip tm" :aria-pressed="form.to === t" @click="(form.to = t), revealPicked()">{{ t }}</button>
                            </div>
                        </div>
                    </template>
                    <div v-if="form.errors.date || form.errors.from || form.errors.to" class="err">{{ form.errors.date || form.errors.from || form.errors.to }}</div>
                    <div class="field">
                        <label for="note">{{ $t('games.create.note') }}</label>
                        <textarea id="note" v-model="form.note" class="input" rows="2" maxlength="200" :placeholder="$t('games.create.note_placeholder')" />
                        <div v-if="form.errors.note" class="err">{{ form.errors.note }}</div>
                    </div>
                </section>
            </div>

            <!-- invite -->
            <section class="card blk inv">
                <div class="row between wrapflex" style="gap: 8px">
                    <h3>{{ $t('games.create.invite') }}</h3>
                    <span :class="['counter', { ok: enough && !tooMany }]">{{ $t('games.create.selected', { n: String(form.invite.length), need: String(need) }) }}</span>
                </div>

                <div v-if="!friends.length" class="empty-state plain">
                    <span class="es-ico"><Icon name="users" /></span>
                    <h3>{{ $t('games.create.no_friends') }}</h3>
                    <p>{{ $t('games.create.no_friends_text') }}</p>
                    <Link v-if="friendsUrl" class="btn sm" :href="friendsUrl + '?tab=find'"><Icon name="search" size="sm" />{{ $t('games.create.find_friends') }}</Link>
                </div>
                <template v-else>
                    <div class="row" style="gap: 8px">
                        <div class="srch">
                            <Icon name="search" size="sm" />
                            <input v-model="q" type="search" class="input" :placeholder="$t('common.search_friends')" :aria-label="$t('common.search_friends')" />
                        </div>
                        <button type="button" class="btn outline sm" :disabled="!shown.length" @click="toggleAll">{{ allShown ? $t('games.create.clear') : $t('games.create.all') }}</button>
                    </div>
                    <ul class="flist">
                        <li v-for="f in shown" :key="f.id">
                            <label :class="{ on: form.invite.includes(f.id) }">
                                <input type="checkbox" :checked="form.invite.includes(f.id)" @change="toggle(f.id)" />
                                <Avatar :id="f.id" :name="f.name" :src="f.avatar" size="sm" />
                                <span class="nm">{{ f.name }}</span>
                                <span class="tick"><Icon name="check" size="sm" /></span>
                            </label>
                        </li>
                        <li v-if="!shown.length" class="faint small" style="padding: 12px">{{ $t('games.create.no_match') }}</li>
                    </ul>
                </template>
                <div v-if="form.errors.invite" class="err">{{ form.errors.invite }}</div>
                <div v-if="errs.game" class="err">{{ errs.game }}</div>
                <div class="submit">
                    <p v-if="why" class="why"><Icon name="users" size="sm" />{{ why }}</p>
                    <button type="submit" class="btn block" :disabled="form.processing || !!why">
                        {{ $t('games.create.submit', { f: form.players_per_side + '×' + form.players_per_side }) }}
                    </button>
                    <p class="faint small after">{{ $t('games.create.after') }}</p>
                </div>
            </section>
        </form>
    </PlayerLayout>
</template>

<style scoped>
.back { display: inline-flex; gap: 6px; align-items: center; color: var(--muted); font-weight: 700; font-size: 14px; margin-top: 22px; }
.back:hover { color: var(--ink); }
.chead { margin: 12px 0 20px; }
.chead p { margin: 8px 0 0; max-width: 640px; }
.alert { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; background: var(--st-pending-soft); color: var(--st-pending); padding: 12px 14px; border-radius: 12px; font-weight: 700; font-size: 14px; margin-bottom: 16px; }
.alert .lnk { text-decoration: underline; margin-left: auto; }
.cgrid { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr); gap: 16px; align-items: start; }
@media (max-width: 860px) { .cgrid { grid-template-columns: minmax(0, 1fr); } }
.col { display: flex; flex-direction: column; gap: 16px; min-width: 0; }
.blk { padding: 20px; display: flex; flex-direction: column; gap: 14px; min-width: 0; }
.blk h3 { font-family: var(--body); font-size: 16px; font-weight: 800; letter-spacing: 0; }
.small { font-size: 13px; margin: 0; }
.sizer { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.stp { width: 48px; height: 48px; border-radius: 50%; border: 1px solid var(--line-2); background: var(--surface-2); color: var(--ink); font-size: 22px; font-weight: 700; flex: none; }
.stp:disabled { opacity: .35; cursor: not-allowed; }
.big { text-align: center; }
.big b { font-family: var(--display); font-size: 44px; font-weight: 700; letter-spacing: -0.03em; color: var(--ink); line-height: 1; }
.big small { display: block; color: var(--muted); font-weight: 700; margin-top: 6px; }
.quick { justify-content: center; flex-wrap: wrap; overflow: visible; }
.quick .field-dot { width: 8px; height: 8px; }
@media (max-width: 600px) { .quick { gap: 6px; flex-wrap: nowrap; } .quick .chip { padding: 7px 9px; font-size: 13px; gap: 5px; } .blk { padding: 16px; } }
.opens { display: flex; gap: 8px; align-items: center; background: var(--bg-2); border-radius: 12px; padding: 10px 12px; font-size: 13.5px; font-weight: 700; color: var(--muted); }
.days, .times { max-width: 100%; }
.day.we:not([aria-pressed="true"]) small { color: var(--st-pending); }
.tm { font-family: var(--mono); font-size: 13px; padding: 7px 12px; }
.submit { display: flex; flex-direction: column; gap: 0; margin-top: 4px; }
.after { margin: 8px 0 0; text-align: center; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 480px) { .two { grid-template-columns: 1fr; } }
textarea.input { resize: vertical; min-height: 64px; }
.err { color: var(--hot); font-size: 13px; font-weight: 700; }
.counter { display: inline-flex; gap: 6px; align-items: center; padding: 4px 11px; border-radius: 999px; background: var(--st-pending-soft); color: var(--st-pending); font-weight: 800; font-size: 12.5px; }
.counter.ok { background: var(--st-confirmed-soft); color: var(--st-confirmed); }
.srch { position: relative; flex: 1; min-width: 0; }
.srch .ico { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--faint); }
.srch .input { padding-left: 34px; }
.flist { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; max-height: 380px; overflow: auto; }
.flist label { position: relative; display: flex; align-items: center; gap: 12px; padding: 8px 10px; border-radius: 12px; cursor: pointer; border: 1px solid transparent; }
.flist label:hover { background: var(--surface-2); }
.flist label.on { background: rgba(200, 255, 61, 0.06); border-color: rgba(200, 255, 61, 0.25); }
.flist input { position: absolute; opacity: 0; pointer-events: none; }
.flist label:has(input:focus-visible) { outline: 2px solid var(--accent); }
.flist .nm { flex: 1; min-width: 0; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.flist .nm small { display: block; color: var(--faint); font-weight: 600; font-size: 12px; }
.flist .tick { width: 22px; height: 22px; border-radius: 50%; border: 1.5px solid var(--line-2); display: grid; place-items: center; color: transparent; flex: none; }
.flist label.on .tick { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
@media (min-width: 861px) { .inv { position: sticky; top: 80px; } }
</style>
