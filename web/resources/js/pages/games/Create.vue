<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import PlayerLayout from '@/layouts/PlayerLayout.vue';
import type { GamePerson } from '@/types/games';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Create a game: N vs N, level, district / place, time window, note, and friends to invite.
const props = defineProps<{
    friends: GamePerson[];
    venues: { id: number; name: string; district: string }[];
    districts: string[];
    today: string;
    active: number;
    maxActive: number;
    level: string | null;
    friendsUrl: string | null;
}>();

const form = useForm({
    players_per_side: 5,
    level: props.level ?? '',
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
const venuesShown = computed(() => (form.district ? props.venues.filter((v) => v.district === form.district) : props.venues));
const maxDate = computed(() => {
    const d = new Date(props.today + 'T00:00:00Z');
    d.setUTCDate(d.getUTCDate() + 30);
    return d.toISOString().slice(0, 10);
});
const limitReached = computed(() => props.active >= props.maxActive);

function submit() {
    form.transform((d) => ({
        ...d,
        level: d.level || null,
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
        <Head :title="$t('games.create.title')" />
        <Link class="back" :href="route('games.index')"><Icon name="back" size="sm" />{{ $t('games.show.back') }}</Link>
        <section class="chead">
            <span class="kicker">{{ $t('games.create.kick') }}</span>
            <h1>{{ $t('games.create.title') }}</h1>
            <p class="muted">{{ $t('games.create.lead') }}</p>
        </section>

        <div v-if="limitReached" class="alert">
            <Icon name="clock" size="sm" />{{ $t('social.error.too_many_games', { n: String(maxActive) }) }}
            <Link :href="route('games.index', { tab: 'mine' })" class="lnk">{{ $t('games.index.tabMine') }}</Link>
        </div>

        <form class="cgrid" @submit.prevent="submit">
            <div class="col">
                <!-- size -->
                <section class="card blk">
                    <h3>{{ $t('games.create.perSide') }}</h3>
                    <div class="sizer">
                        <button type="button" class="stp" :aria-label="'−'" :disabled="form.players_per_side <= 3" @click="step(-1)">−</button>
                        <div class="big">
                            <b>{{ form.players_per_side }}<span>×</span>{{ form.players_per_side }}</b>
                            <small>{{ $t('games.create.total', { n: String(total) }) }}</small>
                        </div>
                        <button type="button" class="stp" :aria-label="'+'" :disabled="form.players_per_side >= 11" @click="step(1)">+</button>
                    </div>
                    <input v-model.number="form.players_per_side" type="range" min="3" max="11" step="1" class="range" :aria-label="$t('games.create.perSide')" />
                    <div class="ticks"><span v-for="n in 9" :key="n">{{ n + 2 }}</span></div>
                    <div class="opens"><Icon name="users" size="sm" />{{ $t('games.create.needs', { n: String(minToOpen), f: String(need) }) }}</div>
                    <div v-if="form.errors.players_per_side" class="err">{{ form.errors.players_per_side }}</div>
                </section>

                <!-- details -->
                <section class="card blk">
                    <h3>{{ $t('games.create.details') }}</h3>
                    <div class="two">
                        <div class="field">
                            <label for="lvl">{{ $t('games.level') }}</label>
                            <select id="lvl" v-model="form.level" class="input">
                                <option value="">{{ $t('games.anyLevel') }}</option>
                                <option v-for="l in ['beginner', 'mid', 'pro']" :key="l" :value="l">{{ $t(`level.${l}`) }}</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="dst">{{ $t('games.district') }}</label>
                            <select id="dst" v-model="form.district" class="input" @change="form.venue_id = ''">
                                <option value="">{{ $t('player.home.anyDistrict') }}</option>
                                <option v-for="d in districts" :key="d" :value="d">{{ $t(`player.district.${d}`) }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="field">
                        <label for="ven">{{ $t('games.create.place') }}</label>
                        <select id="ven" v-model="form.venue_id" class="input">
                            <option value="">{{ $t('games.create.anyPlace') }}</option>
                            <option v-for="v in venuesShown" :key="v.id" :value="v.id">{{ v.name }} · {{ $t(`player.district.${v.district}`) }}</option>
                        </select>
                        <div v-if="form.errors.venue_id" class="err">{{ form.errors.venue_id }}</div>
                    </div>
                    <div class="field">
                        <label>{{ $t('games.create.when') }}</label>
                        <div class="three">
                            <input v-model="form.date" type="date" class="input" :min="today" :max="maxDate" :aria-label="$t('games.create.date')" />
                            <input v-model="form.from" type="time" step="1800" class="input" :disabled="!form.date" :aria-label="$t('games.create.from')" />
                            <input v-model="form.to" type="time" step="1800" class="input" :disabled="!form.date" :aria-label="$t('games.create.to')" />
                        </div>
                        <small class="hint">{{ $t('games.create.whenHint') }}</small>
                        <div v-for="k in ['date', 'from', 'to'] as const" :key="k">
                            <div v-if="form.errors[k]" class="err">{{ form.errors[k] }}</div>
                        </div>
                    </div>
                    <div class="field">
                        <label for="note">{{ $t('games.create.note') }}</label>
                        <textarea id="note" v-model="form.note" class="input" rows="2" maxlength="200" :placeholder="$t('games.create.notePh')" />
                        <div v-if="form.errors.note" class="err">{{ form.errors.note }}</div>
                    </div>
                </section>
            </div>

            <!-- invite -->
            <section class="card blk inv">
                <div class="row between wrapflex" style="gap: 8px">
                    <h3>{{ $t('games.create.invite') }}</h3>
                    <span :class="['counter', { ok: enough && !tooMany }]">
                        <Icon :name="enough && !tooMany ? 'check' : 'users'" size="sm" />{{ $t('games.create.selected', { n: String(form.invite.length), need: String(need) }) }}
                    </span>
                </div>
                <p v-if="tooMany" class="small" style="color: var(--hot)">{{ $t('games.create.tooMany', { n: String(total - 1) }) }}</p>
                <p v-else class="muted small">{{ enough ? $t('games.create.enough') : $t('games.create.needMore', { n: String(need) }) }}</p>

                <div v-if="!friends.length" class="nofr">
                    <Icon name="users" size="lg" />
                    <b>{{ $t('games.create.noFriends') }}</b>
                    <p class="muted small">{{ $t('games.create.noFriendsText') }}</p>
                    <Link v-if="friendsUrl" class="btn sm" :href="friendsUrl"><Icon name="search" size="sm" />{{ $t('games.create.findFriends') }}</Link>
                </div>
                <template v-else>
                    <div class="row" style="gap: 8px">
                        <div class="srch">
                            <Icon name="search" size="sm" />
                            <input v-model="q" type="search" class="input" :placeholder="$t('games.create.search')" :aria-label="$t('games.create.search')" />
                        </div>
                        <button type="button" class="btn ghost sm" :disabled="!shown.length" @click="toggleAll">{{ allShown ? $t('games.create.clear') : $t('games.create.all') }}</button>
                    </div>
                    <ul class="flist">
                        <li v-for="f in shown" :key="f.id">
                            <label :class="{ on: form.invite.includes(f.id) }">
                                <input type="checkbox" :checked="form.invite.includes(f.id)" @change="toggle(f.id)" />
                                <span class="av">{{ f.initials }}</span>
                                <span class="nm">{{ f.name }}<small v-if="f.level">{{ $t(`level.${f.level}`) }}</small></span>
                                <span class="tick"><Icon name="check" size="sm" /></span>
                            </label>
                        </li>
                        <li v-if="!shown.length" class="faint small" style="padding: 12px">{{ $t('games.create.noMatch') }}</li>
                    </ul>
                </template>
                <div v-if="form.errors.invite" class="err">{{ form.errors.invite }}</div>
                <div v-if="form.errors.game" class="err">{{ form.errors.game }}</div>
                <button type="submit" class="btn block" :disabled="form.processing || !enough || tooMany || limitReached">
                    <Icon name="ball" size="sm" />{{ $t('games.create.submit', { f: form.players_per_side + '×' + form.players_per_side }) }}
                </button>
                <p class="faint small" style="margin: 0; text-align: center">{{ $t('games.create.after') }}</p>
            </section>
        </form>
    </PlayerLayout>
</template>

<style scoped>
.back { display: inline-flex; gap: 6px; align-items: center; color: var(--muted); font-weight: 700; font-size: 14px; margin-top: 22px; }
.back:hover { color: var(--ink); }
.chead { margin: 12px 0 20px; }
.chead h1 { font-size: clamp(28px, 4vw, 42px); margin-top: 6px; }
.chead p { margin: 8px 0 0; max-width: 640px; }
.alert { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; background: var(--warn-soft); color: var(--warn); padding: 12px 14px; border-radius: 12px; font-weight: 700; font-size: 14px; margin-bottom: 16px; }
.alert .lnk { text-decoration: underline; margin-left: auto; }
.cgrid { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr); gap: 16px; align-items: start; }
@media (max-width: 860px) { .cgrid { grid-template-columns: minmax(0, 1fr); } }
.col { display: flex; flex-direction: column; gap: 16px; min-width: 0; }
.blk { padding: 20px; display: flex; flex-direction: column; gap: 14px; min-width: 0; }
.blk h3 { font-size: 16px; }
.small { font-size: 13px; margin: 0; }
.sizer { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.stp { width: 48px; height: 48px; border-radius: 50%; border: 1px solid var(--line-2); background: var(--surface-2); color: var(--ink); font-size: 22px; font-weight: 700; flex: none; }
.stp:disabled { opacity: .35; cursor: not-allowed; }
.big { text-align: center; }
.big b { font-family: var(--display); font-size: 46px; font-weight: 700; letter-spacing: -0.04em; background: var(--grad); -webkit-background-clip: text; background-clip: text; color: transparent; line-height: 1; }
.big b span { font-size: 30px; margin: 0 4px; }
.big small { display: block; color: var(--muted); font-weight: 700; margin-top: 6px; }
.range { width: 100%; accent-color: var(--accent); }
.ticks { display: flex; justify-content: space-between; font-family: var(--mono); font-size: 11px; color: var(--faint); margin-top: -8px; padding: 0 2px; }
.opens { display: flex; gap: 8px; align-items: center; background: var(--bg-2); border-radius: 12px; padding: 10px 12px; font-size: 13.5px; font-weight: 700; color: var(--warn); }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.three { display: grid; grid-template-columns: 1.4fr 1fr 1fr; gap: 8px; }
@media (max-width: 480px) { .two { grid-template-columns: 1fr; } .three { grid-template-columns: 1fr 1fr; } .three input:first-child { grid-column: 1 / -1; } }
.three .input:disabled { opacity: .45; }
.hint { color: var(--faint); font-size: 12.5px; }
textarea.input { resize: vertical; min-height: 64px; }
.err { color: var(--hot); font-size: 13px; font-weight: 700; }
.counter { display: inline-flex; gap: 6px; align-items: center; padding: 5px 11px; border-radius: 999px; background: var(--warn-soft); color: var(--warn); font-weight: 800; font-size: 13px; }
.counter.ok { background: var(--free-soft); color: var(--free); }
.srch { position: relative; flex: 1; min-width: 0; }
.srch .ico { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--faint); }
.srch .input { padding-left: 34px; }
.flist { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; max-height: 380px; overflow: auto; }
.flist label { display: flex; align-items: center; gap: 12px; padding: 8px 10px; border-radius: 12px; cursor: pointer; border: 1px solid transparent; }
.flist label:hover { background: var(--surface-2); }
.flist label.on { background: rgba(200, 255, 61, 0.06); border-color: rgba(200, 255, 61, 0.25); }
.flist input { position: absolute; opacity: 0; pointer-events: none; }
.flist label:has(input:focus-visible) { outline: 2px solid var(--accent); }
.flist .av { width: 34px; height: 34px; border-radius: 50%; background: var(--surface-3); display: grid; place-items: center; font-size: 12px; font-weight: 800; flex: none; }
.flist .nm { flex: 1; min-width: 0; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.flist .nm small { display: block; color: var(--faint); font-weight: 600; font-size: 12px; }
.flist .tick { width: 22px; height: 22px; border-radius: 50%; border: 1.5px solid var(--line-2); display: grid; place-items: center; color: transparent; flex: none; }
.flist label.on .tick { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
.nofr { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 8px; padding: 22px 12px; border: 1px dashed var(--line-2); border-radius: 16px; color: var(--muted); }
.nofr b { color: var(--ink); }
@media (min-width: 861px) { .inv { position: sticky; top: 80px; } }
</style>
