<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

// A tournament's settings: title, date and time, a note, up to 3 price options, who may register (a rating range,
// how many players) and, for an existing one, its status. New: saved and opened on its own page.
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
    status: 'open' | 'closed' | 'done';
    min_rating: number | null;
    max_rating: number | null;
    max_players: number | null;
}
const props = defineProps<{ tournament?: TournamentSettings }>();
const emit = defineEmits<{ done: [] }>();
const t = props.tournament;
const opts = t ? t.options.map((o) => ({ ...o })) : [];
while (opts.length < 3) opts.push({ label: '', price: 0 });
const form = useForm({
    title: t?.title ?? '',
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
    if (t) form.put(route('owner.tournaments.update', t.id), done);
    else form.post(route('owner.tournaments.store'), done);
}
</script>

<template>
    <form class="fgrid" @submit.prevent="save">
        <div class="field" style="grid-column: 1 / -1"><label>{{ $t('owner.club.t_title') }}</label><input v-model="form.title" class="input" required maxlength="100" /></div>
        <div class="field"><label>{{ $t('owner.club.date') }}</label><input v-model="form.date" class="input" type="date" required /></div>
        <div class="field"><label>{{ $t('owner.club.start') }}</label><input v-model="form.time" class="input" type="time" required /></div>
        <div class="field" style="grid-column: 1 / -1"><label>{{ $t('owner.club.t_note') }}</label><textarea v-model="form.description" class="input" rows="2" maxlength="1000" /></div>
        <div class="field" style="grid-column: 1 / -1">
            <label>{{ $t('owner.club.options') }}</label>
            <div v-for="(o, i) in form.options" :key="i" class="row" style="gap: 8px; margin-bottom: 6px">
                <input v-model="o.label" class="input" :placeholder="$t('owner.club.option_ph_' + i)" maxlength="40" style="flex: 1; min-width: 0" />
                <span class="money" style="width: 130px; flex: none"><input v-model.number="o.price" class="input" type="number" min="0" step="500" /><span>֏</span></span>
            </div>
        </div>
        <!-- who may register: a rating range (empty = any) and how many players -->
        <div class="field">
            <label>{{ $t('owner.tournament.min_rating') }}</label
            ><input v-model="form.min_rating" class="input" type="number" min="0" max="4000" :placeholder="$t('owner.tournament.any')" data-test="t-min" />
        </div>
        <div class="field">
            <label>{{ $t('owner.tournament.max_rating') }}</label
            ><input v-model="form.max_rating" class="input" type="number" min="0" max="4000" :placeholder="$t('owner.tournament.any')" data-test="t-max" />
        </div>
        <div class="field">
            <label>{{ $t('owner.tournament.max_players') }}</label
            ><input v-model="form.max_players" class="input" type="number" min="2" max="256" :placeholder="$t('owner.tournament.any')" data-test="t-players" />
        </div>
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
