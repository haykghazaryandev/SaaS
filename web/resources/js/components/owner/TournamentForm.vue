<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

// A tournament's settings: title, date and time, a note, up to 3 price options, who may register (a rating range,
// how many players) and, for an existing one, its status. New: saved and opened on its own page.
// Divisions by rating: one tournament with 2–6 divisions under it (a name, a rating range, how many players each);
// each division is a tournament of its own (players, bracket, chat). A division's own form changes its name, not the title.
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
    divisions?: { id: number; division: string | null; min_rating: number | null; max_rating: number | null; max_players: number | null }[];
}
interface Div {
    id?: number;
    name: string;
    min_rating: number | '';
    max_rating: number | '';
    max_players: number | '';
}
const props = defineProps<{ tournament?: TournamentSettings }>();
const emit = defineEmits<{ done: [] }>();
const t = props.tournament;
const opts = t ? t.options.map((o) => ({ ...o })) : [];
while (opts.length < 3) opts.push({ label: '', price: 0 });
const isDivision = !!t?.parent;
// divisions: a new tournament or one that has them (a plain one with players stays plain)
const canSplit = !t || !!t.divisions?.length;
const split = ref(!!t?.divisions?.length);
const blank = (): Div => ({ name: '', min_rating: '', max_rating: '', max_players: '' });
const divs = ref<Div[]>(
    t?.divisions?.length
        ? t.divisions.map((d) => ({
              id: d.id,
              name: d.division ?? '',
              min_rating: d.min_rating ?? '',
              max_rating: d.max_rating ?? '',
              max_players: d.max_players ?? '',
          }))
        : [
              { name: '', min_rating: '', max_rating: '', max_players: '' },
              { name: '', min_rating: '', max_rating: '', max_players: '' },
          ],
);
const form = useForm({
    title: (isDivision ? t?.parent?.title : t?.title) ?? '',
    division: t?.division ?? '',
    date: t?.date ?? '',
    time: t?.time ?? '11:00',
    description: t?.description ?? '',
    status: t?.status ?? 'open',
    min_rating: (t?.min_rating ?? '') as number | '',
    max_rating: (t?.max_rating ?? '') as number | '',
    max_players: (t?.max_players ?? '') as number | '',
    options: opts,
});
function save() {
    const done = { preserveScroll: true, onSuccess: () => emit('done') };
    form.transform((d) => ({ ...d, divisions: split.value ? divs.value.filter((x) => x.name.trim()) : [] }));
    if (t) form.put(route('owner.tournaments.update', t.id), done);
    else form.post(route('owner.tournaments.store'), done);
}
</script>

<template>
    <form class="fgrid" @submit.prevent="save">
        <div v-if="isDivision" class="field" style="grid-column: 1 / -1">
            <label>{{ $t('owner.tournament.division_name') }}</label
            ><input v-model="form.division" class="input" required maxlength="40" data-test="t-division" />
            <small class="faint">{{ $t('owner.tournament.division_of', { title: t?.parent?.title ?? '' }) }}</small>
        </div>
        <div v-else class="field" style="grid-column: 1 / -1">
            <label>{{ $t('owner.club.t_title') }}</label
            ><input v-model="form.title" class="input" required maxlength="100" />
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
        <div class="field" style="grid-column: 1 / -1">
            <label>{{ $t('owner.club.options') }}</label>
            <div v-for="(o, i) in form.options" :key="i" class="row" style="gap: 8px; margin-bottom: 6px">
                <input v-model="o.label" class="input" :placeholder="$t('owner.club.option_ph_' + i)" maxlength="40" style="flex: 1; min-width: 0" />
                <span class="money" style="width: 130px; flex: none"><input v-model.number="o.price" class="input" type="number" min="0" step="500" /><span>֏</span></span>
            </div>
        </div>
        <!-- divisions by rating: one tournament, a bracket per division -->
        <label v-if="canSplit && !isDivision" class="split" style="grid-column: 1 / -1">
            <input v-model="split" type="checkbox" data-test="t-split" />
            <span
                ><b>{{ $t('owner.tournament.split') }}</b
                ><small>{{ $t('owner.tournament.split_hint') }}</small></span
            >
        </label>
        <div v-if="split" class="divs" style="grid-column: 1 / -1">
            <div v-for="(d, i) in divs" :key="i" class="div" :data-test="`div-${i}`">
                <div class="div-h">
                    <input
                        v-model="d.name"
                        class="input"
                        :placeholder="$t('owner.tournament.division_ph_' + Math.min(i, 2))"
                        maxlength="40"
                        required
                    />
                    <button v-if="divs.length > 2" type="button" class="btn ghost sm" :aria-label="$t('common.remove')" @click="divs.splice(i, 1)">
                        <Icon name="trash" size="sm" />
                    </button>
                </div>
                <div class="div-r">
                    <label
                        ><small>{{ $t('owner.tournament.min_rating') }}</small
                        ><input v-model="d.min_rating" class="input" type="number" min="0" max="4000" :placeholder="$t('owner.tournament.any')"
                    /></label>
                    <label
                        ><small>{{ $t('owner.tournament.max_rating') }}</small
                        ><input v-model="d.max_rating" class="input" type="number" min="0" max="4000" :placeholder="$t('owner.tournament.any')"
                    /></label>
                    <label
                        ><small>{{ $t('owner.tournament.max_players') }}</small
                        ><input v-model="d.max_players" class="input" type="number" min="2" max="256" :placeholder="$t('owner.tournament.any')"
                    /></label>
                </div>
            </div>
            <button v-if="divs.length < 6" type="button" class="btn ghost sm" data-test="div-add" @click="divs.push(blank())">
                <Icon name="plus" size="sm" />{{ $t('owner.tournament.division_add') }}
            </button>
        </div>
        <!-- who may register: a rating range (empty = any) and how many players -->
        <template v-if="!split">
            <div class="field">
                <label>{{ $t('owner.tournament.min_rating') }}</label
                ><input
                    v-model="form.min_rating"
                    class="input"
                    type="number"
                    min="0"
                    max="4000"
                    :placeholder="$t('owner.tournament.any')"
                    data-test="t-min"
                />
            </div>
            <div class="field">
                <label>{{ $t('owner.tournament.max_rating') }}</label
                ><input
                    v-model="form.max_rating"
                    class="input"
                    type="number"
                    min="0"
                    max="4000"
                    :placeholder="$t('owner.tournament.any')"
                    data-test="t-max"
                />
            </div>
            <div class="field">
                <label>{{ $t('owner.tournament.max_players') }}</label
                ><input
                    v-model="form.max_players"
                    class="input"
                    type="number"
                    min="2"
                    max="256"
                    :placeholder="$t('owner.tournament.any')"
                    data-test="t-players"
                />
            </div>
        </template>
        <div v-if="t" class="field">
            <label>{{ $t('common.status') }}</label>
            <select v-model="form.status" class="input">
                <option v-for="s in ['open', 'closed', 'done']" :key="s" :value="s">{{ $t('owner.club.t_status_' + s) }}</option>
            </select>
        </div>
        <div v-for="(e, k) in form.errors" :key="k" class="err" style="grid-column: 1 / -1">{{ e }}</div>
        <div class="row" style="gap: 8px; grid-column: 1 / -1">
            <button class="btn" :disabled="form.processing" data-test="t-save">{{ $t('common.save') }}</button>
            <button type="button" class="btn ghost" @click="emit('done')">{{ $t('common.cancel') }}</button>
        </div>
    </form>
</template>

<style scoped>
.split { display: flex; gap: 10px; align-items: flex-start; padding: 12px; border-radius: 14px; background: var(--surface-2); cursor: pointer; }
.split input { margin-top: 3px; width: 18px; height: 18px; accent-color: var(--accent); flex: none; }
.split span { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.split small { color: var(--muted); font-size: 12.5px; }
.divs { display: grid; gap: 8px; min-width: 0; }
.div { border: 1px solid var(--line-2); border-radius: 14px; padding: 10px; display: grid; gap: 8px; min-width: 0; }
.div-h { display: flex; gap: 8px; }
.div-h .input { flex: 1; min-width: 0; font-weight: 700; }
.div-r { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; }
.div-r label { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.div-r small { color: var(--muted); font-size: 11.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.div-r .input { width: 100%; box-sizing: border-box; min-width: 0; }
</style>
