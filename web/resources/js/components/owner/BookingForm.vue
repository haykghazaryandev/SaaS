<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import OwnerSheet from '@/components/owner/OwnerSheet.vue';
import { dur, useOwner, weekdayOf, type FormOpts } from '@/composables/useOwner';
import { hhmm } from '@/composables/useShared';
import { useForm } from '@inertiajs/vue3';
import { trans } from '@/lib/i18n';
import { computed, onMounted, ref, watch } from 'vue';

// Create or edit a booking (phone calls, walk-ins, regular teams). The price comes from the place's prices and can be changed.
const props = defineProps<{ opts: FormOpts }>();
const emit = defineEmits<{ close: [] }>();
const owner = useOwner();
const b = props.opts.booking ?? null;
const fields = computed(() => owner.value.fields.filter((f) => f.active || f.id === b?.field_id));

// the next half hour from now (Yerevan time from the server)
const nowStart = () => Math.ceil(owner.value.nowHour * 2) / 2;
const firstField = fields.value[0];
const field0 = owner.value.fields.find((f) => f.id === (b?.field_id ?? props.opts.field_id)) ?? firstField;
const date0 = b?.date ?? props.opts.date ?? owner.value.today;
const hours0 = owner.value.hours[weekdayOf(date0)];
let start0 = b?.start ?? props.opts.start ?? Math.max(hours0?.[0] ?? 8, date0 === owner.value.today ? nowStart() : 0);
if (hours0 && start0 >= hours0[1]) start0 = hours0[0];

const form = useForm({
    field_id: field0?.id,
    date: date0,
    start: hhmm(start0),
    minutes: b?.minutes ?? field0?.default_duration ?? 60,
    name: b?.name ?? '',
    phone: b?.tel ? b.tel.slice(-8) : '',
    team_name: b?.team ?? '',
    note_owner: b?.note_owner ?? '',
    price: (b?.price ?? null) as number | null,
    repeat: false,
    weeks: 4,
});
const touched = ref(false);
const quote = ref<{
    price: number;
    problem: string | null;
    message: string | null;
    player: { name: string | null; no_shows: number } | null;
    phoneValid: boolean;
} | null>(null);

const times = computed(() => {
    const h = owner.value.hours[weekdayOf(form.date || owner.value.today)] ?? [0, 24];
    const out: string[] = [];
    for (let t = h[0]; t < h[1]; t += 0.5) out.push(hhmm(t));
    if (!out.includes(form.start)) out.unshift(form.start);
    return out;
});
const durations = computed(() => {
    const set = new Set([60, 90, 120, 150, 180, ...(fields.value.find((f) => f.id === form.field_id)?.durations ?? []), form.minutes]);
    return [...set].sort((a, c) => a - c);
});
const moved = computed(
    () => !b || b.field_id !== form.field_id || b.date !== form.date || hhmm(b.start) !== form.start || b.minutes !== form.minutes,
);

let seq = 0;
let timer: number | undefined;
async function refresh() {
    if (!form.field_id || !form.date) return;
    const my = ++seq;
    const q = new URLSearchParams({
        field_id: String(form.field_id),
        date: form.date,
        start: form.start,
        minutes: String(form.minutes),
        phone: form.phone || '',
    });
    if (b) q.set('ignore', String(b.id));
    try {
        const r = await fetch(`${route('owner.bookings.quote')}?${q}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!r.ok || my !== seq) return;
        quote.value = await r.json();
        // keep the price of an edited booking until its time changes; new bookings follow the price list
        if (!touched.value && quote.value && (!b || moved.value)) form.price = quote.value.price;
    } catch {
        /* offline: keep the last quote */
    }
}
const later = () => {
    clearTimeout(timer);
    timer = window.setTimeout(refresh, 250);
};
watch(() => [form.field_id, form.date, form.start, form.minutes], refresh);
watch(() => form.phone, later);
onMounted(refresh);

function pickField(id: number) {
    form.field_id = id;
    const f = fields.value.find((x) => x.id === id);
    if (f && !b && !f.durations.includes(form.minutes)) form.minutes = f.default_duration;
}

const status = computed(() => {
    const ruleError = (form.errors as Record<string, string | undefined>).booking;
    if (ruleError) return { ok: false, text: ruleError };
    if (!quote.value || !moved.value) return null;
    return quote.value.problem ? { ok: false, text: quote.value.message || '' } : { ok: true, text: trans('owner.form.free') };
});

function submit() {
    const opts = { preserveScroll: true, preserveState: true, onSuccess: () => emit('close') };
    form.transform((d) => ({
        field_id: d.field_id,
        date: d.date,
        start: d.start,
        minutes: d.minutes,
        name: d.name.trim() || null,
        phone: d.phone.trim() || null,
        team_name: d.team_name.trim() || null,
        note_owner: d.note_owner.trim() || null,
        price: touched.value ? Number(String(d.price ?? '').replace(/\D/g, '')) || 0 : null,
        weeks: !b && d.repeat ? d.weeks : null,
    }));
    if (b) form.patch(route('owner.bookings.update', b.id), opts);
    else form.post(route('owner.bookings.store'), opts);
}
</script>

<template>
    <OwnerSheet
        wide
        :title="$t(b ? 'owner.form.edit_title' : 'common.new_booking')"
        :sub="b ? $t(b.tel ? 'owner.form.notify' : 'owner.booking.no_phone') : $t('owner.form.new_sub')"
        @close="emit('close')"
    >
        <form @submit.prevent="submit">
            <div id="ff" class="seg" role="group" :aria-label="$t('common.field')">
                <button v-for="f in fields" :key="f.id" type="button" :aria-pressed="form.field_id === f.id" @click="pickField(f.id)">
                    {{ f.label }}
                </button>
            </div>
            <div class="fgrid">
                <div class="field">
                    <label for="fd">{{ $t('owner.form.date') }}</label>
                    <input id="fd" v-model="form.date" class="input" type="date" required />
                </div>
                <div class="field">
                    <label for="fs">{{ $t('owner.form.start') }}</label>
                    <select id="fs" v-model="form.start" class="input">
                        <option v-for="t in times" :key="t" :value="t">{{ t }}</option>
                    </select>
                </div>
                <div class="field">
                    <label for="fu">{{ $t('common.duration') }}</label>
                    <select id="fu" v-model.number="form.minutes" class="input">
                        <option v-for="d in durations" :key="d" :value="d">{{ dur(d) }}</option>
                    </select>
                </div>
                <div class="field">
                    <label for="fp">{{ $t('owner.form.price') }}</label>
                    <input id="fp" v-model="form.price" class="input mono" inputmode="numeric" @input="touched = true" />
                    <div v-if="form.errors.price" class="err">{{ form.errors.price }}</div>
                </div>
                <div class="field">
                    <label for="fh"
                        >{{ $t('common.phone') }} <span class="faint">· {{ $t('owner.form.optional') }}</span></label
                    >
                    <div class="row" style="gap: 6px">
                        <span class="input pre">+374</span>
                        <input id="fh" v-model="form.phone" class="input mono" inputmode="tel" placeholder="91 234 567" autocomplete="off" />
                    </div>
                    <span v-if="quote?.player" class="player-hint"
                        ><Icon name="user" size="sm" />{{ $t('owner.form.is_player', { name: quote.player.name || '—' }) }}</span
                    >
                    <span v-if="quote?.player?.no_shows" class="player-hint warn">{{ $t('owner.booking.no_shows', { n: quote.player.no_shows }) }}</span>
                    <div v-if="form.errors.phone || (quote && !quote.phoneValid && form.phone.length > 7)" class="err">
                        {{ form.errors.phone || $t('owner.form.bad_phone') }}
                    </div>
                </div>
                <div class="field">
                    <label for="fn">{{ $t('common.name') }}</label>
                    <input id="fn" v-model="form.name" class="input" autocomplete="off" maxlength="80" :placeholder="quote?.player?.name || ''" />
                    <div v-if="form.errors.name" class="err">{{ form.errors.name }}</div>
                </div>
                <div class="field">
                    <label for="ft"
                        >{{ $t('common.team') }} <span class="faint">· {{ $t('owner.form.optional') }}</span></label
                    >
                    <input id="ft" v-model="form.team_name" class="input" maxlength="80" />
                </div>
                <div class="field">
                    <label for="fo"
                        >{{ $t('owner.booking.note_owner') }} <span class="faint">· {{ $t('owner.form.optional') }}</span></label
                    >
                    <input id="fo" v-model="form.note_owner" class="input" maxlength="1000" :placeholder="$t('owner.form.note_placeholder')" />
                </div>
            </div>
            <label v-if="!b" class="check">
                <input v-model="form.repeat" type="checkbox" />
                <span>{{ $t('owner.form.repeat') }}</span>
                <select
                    v-model.number="form.weeks"
                    class="input"
                    style="width: auto; padding: 6px 30px 6px 10px"
                    :disabled="!form.repeat"
                    :aria-label="$t('owner.form.weeks_label')"
                >
                    <option v-for="n in [2, 4, 6, 8, 12]" :key="n" :value="n">{{ $t('owner.form.weeks', { n }) }}</option>
                </select>
            </label>
            <div class="ferr" role="alert">
                <span v-if="status?.ok" class="ok"><Icon name="check" size="sm" />{{ status.text }}</span>
                <template v-else-if="status">{{ status.text }}</template>
            </div>
            <button class="btn block" :disabled="form.processing" data-test="form-ok">{{ $t(b ? 'common.save' : 'owner.action.create') }}</button>
        </form>
    </OwnerSheet>
</template>
