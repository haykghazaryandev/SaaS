<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { useOwner } from '@/composables/useOwner';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// A tournament's settings: title, date and time, a note and its divisions — each with who may play (a rating range),
// how many players and its price. One row = a single tournament (still with a rating range and a price); two or more =
// a tournament with divisions, each a tournament of its own (players, bracket, chat). A division's own form changes
// its name, rating, places and price. For an existing one: its status too.
interface Opt {
    label: string;
    price: number;
}
export interface TournamentSettings {
    id: number;
    title: string;
    date: string;
    time: string;
    description: string | null;
    options: Opt[];
    status: 'open' | 'closed' | 'done' | 'cancelled';
    min_rating: number | null;
    max_rating: number | null;
    max_players: number | null;
    division?: string | null;
    cancel_reason?: string | null;
    parent?: { id: number; title: string } | null;
    divisions?: { id: number; division: string | null; min_rating: number | null; max_rating: number | null; max_players: number | null; options?: Opt[] }[];
}
interface Div {
    id?: number;
    name: string;
    min_rating: number | '';
    max_rating: number | '';
    max_players: number | '';
    price: number | '';
}
const props = defineProps<{ tournament?: TournamentSettings }>();
const emit = defineEmits<{ done: [] }>();
const t = props.tournament;
const isDivision = !!t?.parent;
// more divisions: a new tournament or one that has them (a plain one that has players stays plain)
const canSplit = !isDivision && (!t || !!t.divisions?.length);
const price = (o?: Opt[]) => o?.[0]?.price ?? '';
// the place's sport has ratings (ping-pong): divisions by rating; football: no rating fields
const owner = useOwner();
const rated = computed(() => (owner.value.features ?? []).includes('rating'));
const blank = (): Div => ({ name: '', min_rating: '', max_rating: '', max_players: '', price: divs.value[divs.value.length - 1]?.price ?? '' });
const divs = ref<Div[]>(
    t?.divisions?.length
        ? t.divisions.map((d) => ({ id: d.id, name: d.division ?? '', min_rating: d.min_rating ?? '', max_rating: d.max_rating ?? '', max_players: d.max_players ?? '', price: price(d.options) }))
        : [{ name: t?.division ?? '', min_rating: t?.min_rating ?? '', max_rating: t?.max_rating ?? '', max_players: t?.max_players ?? '', price: price(t?.options) }],
);
const split = computed(() => divs.value.length > 1);
const form = useForm({
    title: (isDivision ? t?.parent?.title : t?.title) ?? '',
    date: t?.date ?? '',
    time: t?.time ?? '11:00',
    description: t?.description ?? '',
    status: t?.status ?? 'open',
});
const num = (v: number | '') => (v === '' ? null : Number(v));
function save() {
    const done = { preserveScroll: true, onSuccess: () => emit('done') };
    const one = divs.value[0];
    form.transform((d) => ({
        ...d,
        // a single tournament (or a division): its rating range, places and price on itself
        division: isDivision ? one.name : null,
        min_rating: split.value ? null : num(one.min_rating),
        max_rating: split.value ? null : num(one.max_rating),
        max_players: split.value ? null : num(one.max_players),
        price: num(one.price) ?? 0,
        divisions: split.value ? divs.value.map((x) => ({ ...x, min_rating: num(x.min_rating), max_rating: num(x.max_rating), max_players: num(x.max_players), price: num(x.price) ?? 0 })) : [],
    }));
    if (t) form.put(route('owner.tournaments.update', t.id), done);
    else form.post(route('owner.tournaments.store'), done);
}
</script>

<template>
    <form class="fgrid" @submit.prevent="save">
        <div v-if="!isDivision" class="field" style="grid-column: 1 / -1">
            <label>{{ $t('owner.club.t_title') }}</label
            ><input v-model="form.title" class="input" required maxlength="100" data-test="t-title" />
        </div>
        <div class="field">
            <label>{{ $t('owner.club.date') }}</label
            ><input v-model="form.date" class="input" type="date" required />
        </div>
        <div class="field">
            <label>{{ $t('owner.club.start') }}</label
            ><input v-model="form.time" class="input" type="time" required />
        </div>
        <div class="field" style="grid-column: 1 / -1">
            <label>{{ $t('owner.club.t_note') }}</label
            ><textarea v-model="form.description" class="input" rows="2" maxlength="1000" />
        </div>

        <!-- who plays and for how much: one row = a single tournament; more rows = divisions by rating -->
        <div class="divs" style="grid-column: 1 / -1">
            <div class="divs-h">
                <b>{{ isDivision ? $t('owner.tournament.this_division') : split ? $t('owner.tournament.divisions') : $t('owner.tournament.players_price') }}</b>
                <small class="faint">{{ isDivision ? $t('owner.tournament.division_of', { title: t?.parent?.title ?? '' }) : $t('owner.tournament.divisions_hint') }}</small>
            </div>
            <div v-for="(d, i) in divs" :key="i" class="div" :data-test="`div-${i}`">
                <div v-if="split || isDivision" class="div-h">
                    <input v-model="d.name" class="input" :placeholder="$t('owner.tournament.division_ph_' + Math.min(i, 2))" maxlength="40" required data-test="t-division" />
                    <button v-if="split && !isDivision" type="button" class="btn ghost sm" :aria-label="$t('common.remove')" @click="divs.splice(i, 1)">
                        <Icon name="trash" size="sm" />
                    </button>
                </div>
                <div class="div-r">
                    <!-- a rating range only where the sport has ratings (not football) -->
                    <label v-if="rated"
                        ><small>{{ $t('owner.tournament.min_rating') }}</small
                        ><input v-model="d.min_rating" class="input" type="number" min="0" max="4000" :placeholder="$t('owner.tournament.any')" data-test="t-min"
                    /></label>
                    <label v-if="rated"
                        ><small>{{ $t('owner.tournament.max_rating') }}</small
                        ><input v-model="d.max_rating" class="input" type="number" min="0" max="4000" :placeholder="$t('owner.tournament.any')" data-test="t-max"
                    /></label>
                    <label
                        ><small>{{ $t('owner.tournament.max_players') }}</small
                        ><input v-model="d.max_players" class="input" type="number" min="2" max="256" :placeholder="$t('owner.tournament.any')" data-test="t-players"
                    /></label>
                    <label
                        ><small>{{ $t('owner.tournament.fee') }}</small
                        ><span class="money"><input v-model="d.price" class="input" type="number" min="0" step="500" placeholder="0" data-test="t-price" /><span>֏</span></span></label
                    >
                </div>
            </div>
            <button v-if="canSplit && divs.length < 6" type="button" class="btn ghost sm" data-test="div-add" @click="divs.push(blank())">
                <Icon name="plus" size="sm" />{{ divs.length === 1 && rated ? $t('owner.tournament.split_by_rating') : $t('owner.tournament.division_add') }}
            </button>
        </div>
        <div v-if="t" class="field">
            <label>{{ $t('common.status') }}</label>
            <select v-model="form.status" class="input">
                <option v-for="s in ['open', 'closed', 'done']" :key="s" :value="s">{{ $t('owner.club.t_status_' + s) }}</option>
            </select>
        </div>
        <div v-for="(e, k) in form.errors" :key="k" class="err" style="grid-column: 1 / -1">{{ e }}</div>
        <div class="row" style="gap: 8px; grid-column: 1 / -1">
            <button class="btn" :disabled="form.processing" data-test="t-save">{{ t ? $t('common.save') : $t('owner.tournament.create') }}</button>
            <button type="button" class="btn ghost" @click="emit('done')">{{ $t('common.cancel') }}</button>
        </div>
    </form>
</template>

<style scoped>
.divs-h { display: flex; flex-direction: column; gap: 2px; }
.divs-h small { font-size: 12.5px; }
.money { display: flex; align-items: center; gap: 4px; }
.divs { display: grid; gap: 8px; min-width: 0; }
.div { border: 1px solid var(--line-2); border-radius: 14px; padding: 10px; display: grid; gap: 8px; min-width: 0; }
.div-h { display: flex; gap: 8px; }
.div-h .input { flex: 1; min-width: 0; font-weight: 700; }
.div-r { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; }
@media (max-width: 560px) { .div-r { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.div-r label { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.div-r small { color: var(--muted); font-size: 11.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.div-r .input { width: 100%; box-sizing: border-box; min-width: 0; }
</style>
