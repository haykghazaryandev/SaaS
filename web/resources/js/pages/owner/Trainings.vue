<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { useOwner } from '@/composables/useOwner';
import { amd } from '@/composables/useShared';
import { wdName } from '@/composables/usePlayer';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

// Trainings: the schedule players see on the place page (days, time, coach, price per visit).
// Visits are marked on the player's card (scan the QR → "Came"), which also takes the payment.
interface Row {
    id: number;
    title: string;
    coach: string | null;
    weekdays: number[];
    start: string;
    end: string;
    minutes: number;
    price: number;
    capacity: number | null;
    note: string | null;
    active: boolean;
}
defineProps<{ trainings: Row[]; visits: { id: number; who: string | null; training: string | null; date: string; amount: number | null; method: string | null }[]; today: number }>();
const owner = useOwner();
const canEdit = owner.value.role === 'owner';
const ORDER = [1, 2, 3, 4, 5, 6, 0];
const editing = ref<number | 'new' | null>(null);
const blank = () => ({ title: '', coach: '', weekdays: [] as number[], starts_at: '18:00', minutes: 90, price: 3000, capacity: null as number | null, note: '', active: true });
const form = useForm(blank());
function edit(t?: Row) {
    form.clearErrors();
    if (t) {
        Object.assign(form, { title: t.title, coach: t.coach ?? '', weekdays: [...t.weekdays], starts_at: t.start, minutes: t.minutes, price: t.price, capacity: t.capacity, note: t.note ?? '', active: t.active });
        editing.value = t.id;
    } else {
        Object.assign(form, blank());
        editing.value = 'new';
    }
}
function toggleDay(d: number) {
    form.weekdays = form.weekdays.includes(d) ? form.weekdays.filter((x) => x !== d) : [...form.weekdays, d];
}
function save() {
    const done = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    if (editing.value === 'new') form.post(route('owner.trainings.store'), done);
    else if (editing.value) form.put(route('owner.trainings.update', editing.value), done);
}
const days = (w: number[]) => ORDER.filter((d) => w.includes(d)).map((d) => wdName(d)).join(', ');
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.trainings')">
        <div class="phead">
            <div>
                <div class="kicker">{{ owner.venue.name }}</div>
                <h1>{{ $t('owner.nav.trainings') }}</h1>
            </div>
            <div class="phead-r">
                <button v-if="canEdit" type="button" class="btn" data-test="new-training" @click="edit()"><Icon name="plus" size="sm" />{{ $t('owner.club.new_training') }}</button>
            </div>
        </div>

        <section v-if="editing" class="card panel">
            <div class="sec-h"><h2>{{ editing === 'new' ? $t('owner.club.new_training') : form.title }}</h2></div>
            <form class="fgrid" @submit.prevent="save">
                <div class="field"><label>{{ $t('owner.club.t_title') }}</label><input v-model="form.title" class="input" required maxlength="80" /></div>
                <div class="field"><label>{{ $t('owner.club.coach') }}</label><input v-model="form.coach" class="input" maxlength="80" /></div>
                <div class="field" style="grid-column: 1 / -1">
                    <label>{{ $t('owner.club.days') }}</label>
                    <div class="chips">
                        <button v-for="d in ORDER" :key="d" type="button" class="chip" :aria-pressed="form.weekdays.includes(d)" @click="toggleDay(d)">{{ wdName(d) }}</button>
                    </div>
                    <div v-if="form.errors.weekdays" class="err">{{ form.errors.weekdays }}</div>
                </div>
                <div class="field"><label>{{ $t('owner.club.start') }}</label><input v-model="form.starts_at" class="input" type="time" step="900" required /></div>
                <div class="field"><label>{{ $t('owner.club.minutes') }}</label><input v-model.number="form.minutes" class="input" type="number" min="15" step="15" required /></div>
                <div class="field"><label>{{ $t('owner.club.price_visit') }}</label><input v-model.number="form.price" class="input" type="number" min="0" step="500" required /></div>
                <div class="field"><label>{{ $t('owner.club.capacity') }}</label><input v-model.number="form.capacity" class="input" type="number" min="1" /></div>
                <div class="field" style="grid-column: 1 / -1"><label>{{ $t('owner.club.t_note') }}</label><input v-model="form.note" class="input" maxlength="300" /></div>
                <label class="switch" style="grid-column: 1 / -1"><input v-model="form.active" type="checkbox" />{{ $t('owner.club.active') }}</label>
                <div class="row" style="gap: 8px; grid-column: 1 / -1">
                    <button class="btn" :disabled="form.processing">{{ $t('common.save') }}</button>
                    <button type="button" class="btn ghost" @click="editing = null">{{ $t('common.cancel') }}</button>
                </div>
            </form>
        </section>

        <section class="card panel">
            <div v-for="t in trainings" :key="t.id" :class="['trow', { off: !t.active }]">
                <div class="tw">
                    <b>{{ t.title }}<span v-if="t.weekdays.includes(today) && t.active" class="pill green" style="margin-left: 8px">{{ $t('common.today') }}</span></b>
                    <small>{{ days(t.weekdays) }} · {{ t.start }}–{{ t.end }}{{ t.coach ? ' · ' + t.coach : '' }}{{ t.capacity ? ' · ' + $t('owner.club.places_n', { n: t.capacity }) : '' }}</small>
                </div>
                <b class="mono">{{ amd(t.price) }}</b>
                <button v-if="canEdit" type="button" class="btn ghost sm" @click="edit(t)"><Icon name="edit" size="sm" /></button>
            </div>
            <p v-if="!trainings.length" class="faint" style="margin: 6px 0">{{ $t('owner.club.no_trainings') }}</p>
            <p class="faint" style="font-size: 12.5px; margin: 12px 0 0">{{ $t('owner.club.visits_hint') }}</p>
        </section>

        <section v-if="visits.length" class="card panel">
            <div class="sec-h"><h2>{{ $t('owner.club.visits') }}</h2></div>
            <div v-for="v in visits" :key="v.id" class="trow">
                <span class="mono faint">{{ v.date }}</span>
                <div class="tw"><b>{{ v.who }}</b><small>{{ v.training }}</small></div>
                <span v-if="v.amount != null" class="mono">{{ amd(v.amount) }} · {{ $t('owner.club.m_' + v.method) }}</span>
                <span v-else class="pill warn">{{ $t('owner.club.unpaid') }}</span>
            </div>
        </section>
    </OwnerLayout>
</template>

<style scoped>
.trow { display: flex; align-items: center; gap: 12px; padding: 11px 0; border-bottom: 1px solid var(--line); }
.trow:last-of-type { border-bottom: 0; }
.trow.off { opacity: .5; }
.tw { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.tw small { color: var(--muted); font-size: 12.5px; }
</style>
