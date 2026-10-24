<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import LangSwitch from '@/components/LangSwitch.vue';
import TelegramConnect from '@/components/TelegramConnect.vue';
import { fdate, useOwner } from '@/composables/useOwner';
import { useShared } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { trans } from '@/lib/i18n';
import { computed, reactive, ref } from 'vue';

// Settings: place info + phone, prices per field, opening hours, booking rules, closures. Only the owner role can change them.
// Prices, hours, rules and the phone are saved together by one sticky "Save changes" bar that shows up when something changed.
interface PriceRow {
    field_id: number;
    label: string;
    surface: string;
    indoor: boolean;
    day: number;
    evening: number;
    weekend: number;
}
interface HourRow {
    weekday: number;
    open: string | null;
    close: string | null;
}
interface ClosureRow {
    id: number;
    field: string | null;
    from: string;
    to: string;
    reason: string | null;
}
const props = defineProps<{
    canEdit: boolean;
    place: { name: string; address: string | null; district: string; phone: string | null; tel: string | null; status: string };
    prices: PriceRow[];
    hours: HourRow[];
    rules: { free_cancel_hours: number; min_notice_min: number; request_expire_min: number; auto_accept_regulars: boolean; late_grace_min: number };
    closures: ClosureRow[];
}>();
const owner = useOwner();
const { user } = useShared();
const opts = { preserveScroll: true, preserveState: true };

// ---------- helpers ----------
/** 15000 -> "15 000" (the ֏ sign sits next to the input) */
const sep = (n: number) => Math.round(n).toLocaleString('en-US').replace(/,/g, ' ');
const digits = (v: string | number) => String(v ?? '').replace(/\D/g, '');
const num = (v: string | number) => Number(digits(v)) || 0;
/** "91234567" -> "91 234 567" */
const fmtPhone = (d: string) => [d.slice(0, 2), d.slice(2, 5), d.slice(5, 8)].filter(Boolean).join(' ');

// ---------- editable state ----------
type Prices = { field_id: number; day: string; evening: string; weekend: string }[];
const ORDER = [1, 2, 3, 4, 5, 6, 0]; // Monday first
const priceOf = (id: number) => props.prices.find((p) => p.field_id === id)!;
const state = reactive({
    prices: [] as Prices,
    hours: [] as HourRow[],
    same: true,
    every: { open: '09:00', close: '23:00' },
    rules: { ...props.rules },
    phoneOpen: false,
    phone: '',
});
const allSame = (hs: HourRow[]) => hs.every((h) => h.open !== null && h.open === hs[0].open && h.close === hs[0].close);

/** What "Save changes" sends, per part. */
function payload() {
    return {
        prices: state.prices.map((p) => ({ field_id: p.field_id, day: num(p.day), evening: num(p.evening), weekend: num(p.weekend) })),
        hours: state.same ? state.hours.map((h) => ({ weekday: h.weekday, ...state.every })) : state.hours.map((h) => ({ ...h })),
        rules: { ...state.rules },
        phone: state.phoneOpen && digits(state.phone) ? `+374${digits(state.phone).slice(-8)}` : null,
    };
}
type Payload = ReturnType<typeof payload>;
function load(p: Payload) {
    state.prices = p.prices.map((x) => ({ field_id: x.field_id, day: sep(x.day), evening: sep(x.evening), weekend: sep(x.weekend) }));
    state.hours = p.hours.map((h) => ({ ...h }));
    state.same = allSame(state.hours);
    state.every = state.same ? { open: state.hours[0].open!, close: state.hours[0].close! } : { open: '09:00', close: '23:00' };
    state.rules = { ...p.rules };
    state.phoneOpen = !!p.phone;
    state.phone = p.phone ? fmtPhone(p.phone.slice(4)) : '';
}
const initial: Payload = {
    prices: props.prices.map((p) => ({ field_id: p.field_id, day: p.day, evening: p.evening, weekend: p.weekend })),
    hours: ORDER.map((wd) => ({ ...props.hours.find((h) => h.weekday === wd)! })),
    rules: { ...props.rules },
    phone: props.place.tel,
};
load(initial);
const saved = ref<Record<keyof Payload, string>>(snap(payload()));
function snap(p: Payload) {
    return { prices: JSON.stringify(p.prices), hours: JSON.stringify(p.hours), rules: JSON.stringify(p.rules), phone: JSON.stringify(p.phone) };
}
const changed = computed(() => {
    const now = snap(payload());
    return (Object.keys(now) as (keyof Payload)[]).filter((k) => now[k] !== saved.value[k]);
});
const dirty = computed(() => props.canEdit && changed.value.length > 0);

// ---------- save / discard ----------
const errors = ref<Record<string, string>>({});
const saving = ref(false);
function save() {
    const p = payload();
    const data: Partial<Payload> = {};
    for (const k of changed.value) (data as Record<string, unknown>)[k] = p[k];
    router.put(route('owner.settings.save'), data, {
        ...opts,
        onStart: () => (saving.value = true),
        onSuccess: () => {
            errors.value = {};
            saved.value = snap(p);
        },
        onError: (e) => (errors.value = e),
        onFinish: () => (saving.value = false),
    });
}
function discard() {
    errors.value = {};
    load({
        prices: JSON.parse(saved.value.prices),
        hours: JSON.parse(saved.value.hours),
        rules: JSON.parse(saved.value.rules),
        phone: JSON.parse(saved.value.phone),
    });
}
const errOf = (prefix: string) => Object.entries(errors.value).find(([k]) => k === prefix || k.startsWith(prefix + '.'))?.[1] || '';
const firstError = computed(() => Object.values(errors.value)[0] || '');

// prices: thousand separators while typing
function onPrice(p: Prices[number], key: 'day' | 'evening' | 'weekend', e: Event) {
    const d = digits((e.target as HTMLInputElement).value).slice(0, 7);
    p[key] = d ? sep(Number(d)) : '';
    (e.target as HTMLInputElement).value = p[key];
}

// opening hours
const OPEN_T: string[] = [];
const CLOSE_T: string[] = [];
for (let m = 0; m <= 24 * 60; m += 30) {
    const t = `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
    if (m < 24 * 60) OPEN_T.push(t);
    if (m > 0) CLOSE_T.push(t);
}
// 2026-10-18 is a Sunday (weekday 0)
const weekdayName = (wd: number) => fdate(`2026-10-${18 + wd}`, { weekday: 'long' });
function toggleDay(i: number, open: boolean) {
    const h = state.hours[i];
    h.open = open ? state.every.open : null;
    h.close = open ? state.every.close : null;
}
function setSame(on: boolean) {
    if (on) {
        const firstOpen = state.hours.find((h) => h.open !== null);
        if (firstOpen) state.every = { open: firstOpen.open!, close: firstOpen.close! };
    } else {
        state.hours.forEach((h) => {
            h.open = state.every.open;
            h.close = state.every.close;
        });
    }
    state.same = on;
}

// phone
function onPhone(e: Event) {
    state.phone = fmtPhone(digits((e.target as HTMLInputElement).value).slice(-8));
    (e.target as HTMLInputElement).value = state.phone;
}
function removePhone() {
    state.phoneOpen = false;
    state.phone = '';
}

// rules
const CANCEL_H = [0, 2, 6, 12, 24, 48];
const NOTICE = [0, 30, 60, 120, 240];
const EXPIRE = [30, 60, 120, 240, 720];
const GRACE = [5, 10, 15, 20, 30];
const mins = (m: number) =>
    m === 0 ? trans('owner.settings.no_limit') : m < 60 ? trans('owner.settings.minutes_count', { n: m }) : trans('owner.settings.hours_count', { n: m / 60 });
const withCurrent = (list: number[], v: number) => [...new Set([...list, v])].sort((a, b) => a - b);

// closures (added and removed right away: they are actions, not settings)
const today = owner.value.today;
const closure = useForm({ field_id: null as number | null, from: `${today}T09:00`, to: `${today}T12:00`, reason: '' });
function addClosure() {
    closure.post(route('owner.closures.store'), { ...opts, onSuccess: () => closure.reset('reason') });
}
function removeClosure(id: number) {
    if (window.confirm(trans('owner.settings.closure_remove_ask'))) router.delete(route('owner.closures.destroy', id), opts);
}
const fmtRange = (c: ClosureRow) =>
    `${c.from.slice(8, 10)}.${c.from.slice(5, 7)} ${c.from.slice(11)} – ${c.to.slice(0, 10) === c.from.slice(0, 10) ? '' : c.to.slice(8, 10) + '.' + c.to.slice(5, 7) + ' '}${c.to.slice(11)}`;
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.settings')">
        <div :class="{ 'has-savebar': dirty }">
            <div class="phead">
                <div>
                    <div class="kicker">{{ $t('owner.settings.kicker') }}</div>
                    <h1>{{ $t('owner.nav.settings') }}</h1>
                </div>
            </div>
            <div v-if="!canEdit" class="readonly-note"><Icon name="eye" size="sm" />{{ $t('owner.settings.readonly') }}</div>

            <div class="sgrid">
                <div class="scol">
                    <section class="card panel">
                        <div class="sec-h">
                            <h2>{{ $t('owner.settings.profile') }}</h2>
                            <span v-if="place.status === 'active'" class="pill green">{{ $t('owner.settings.active') }}</span>
                        </div>
                        <h3 style="font-size: 20px; margin-bottom: 12px">{{ place.name }}</h3>
                        <div class="kvs">
                            <div class="kv">
                                <Icon name="pin" size="sm" /><span>{{ $t('common.address') }}</span
                                ><b>{{ place.address || '—' }}</b>
                            </div>
                            <div class="kv kv-phone">
                                <Icon name="phone" size="sm" /><span>{{ $t('common.phone') }}</span>
                                <div v-if="canEdit && state.phoneOpen" class="phone-edit">
                                    <div class="row" style="gap: 6px">
                                        <span class="input pre">+374</span>
                                        <input
                                            id="vphone"
                                            class="input mono"
                                            inputmode="tel"
                                            placeholder="91 234 567"
                                            autocomplete="off"
                                            :value="state.phone"
                                            :aria-label="$t('common.phone')"
                                            data-test="venue-phone"
                                            @input="onPhone"
                                        />
                                        <button
                                            type="button"
                                            class="x"
                                            :title="$t('owner.settings.remove_phone')"
                                            :aria-label="$t('owner.settings.remove_phone')"
                                            @click="removePhone"
                                        >
                                            <Icon name="x" size="sm" />
                                        </button>
                                    </div>
                                    <small class="faint">{{ $t('owner.settings.phone_help') }}</small>
                                    <span v-if="errors.phone" class="err">{{ errors.phone }}</span>
                                </div>
                                <button
                                    v-else-if="canEdit"
                                    type="button"
                                    class="btn ghost sm addphone"
                                    data-test="add-phone"
                                    @click="state.phoneOpen = true"
                                >
                                    <Icon name="plus" size="sm" />{{ $t('owner.settings.add_phone') }}
                                </button>
                                <b v-else>{{ place.phone || '—' }}</b>
                            </div>
                            <div class="kv">
                                <Icon name="grid" size="sm" /><span>{{ $t('common.fields') }}</span
                                ><b>{{ prices.map((p) => p.label).join(' · ') }}</b>
                            </div>
                        </div>
                        <p class="faint" style="font-size: 12.5px; margin: 12px 0 0">{{ $t('owner.settings.profile_note') }}</p>
                    </section>

                    <section class="card panel">
                        <div class="sec-h">
                            <h2>{{ $t('owner.settings.prices') }}</h2>
                        </div>
                        <p class="muted" style="font-size: 13px; margin: 0">{{ $t('owner.settings.prices_note') }}</p>
                        <div v-for="(p, i) in state.prices" :key="p.field_id" class="pfield">
                            <div class="lbl">
                                <b>{{ priceOf(p.field_id).label }}</b
                                ><small
                                    >{{ $t(`common.surfaces.${priceOf(p.field_id).surface}`)
                                    }}{{ priceOf(p.field_id).indoor ? ' · ' + $t('owner.settings.indoor') : '' }}</small
                                >
                            </div>
                            <div v-for="k in ['day', 'evening', 'weekend'] as const" :key="k" class="field">
                                <label :for="`p${k}${i}`">{{ $t(`owner.settings.price_${k}`) }}</label>
                                <span class="money"
                                    ><input
                                        :id="`p${k}${i}`"
                                        class="input"
                                        inputmode="numeric"
                                        :value="p[k]"
                                        :disabled="!canEdit"
                                        :data-test="`price-${k}`"
                                        @input="onPrice(p, k, $event)"
                                    /><span aria-hidden="true">֏</span></span
                                >
                            </div>
                        </div>
                        <p class="faint" style="font-size: 12px; margin: 10px 0 0">{{ $t('owner.settings.per_hour') }}</p>
                        <span v-if="errOf('prices')" class="err">{{ errOf('prices') }}</span>
                    </section>

                    <section class="card panel">
                        <div class="sec-h">
                            <h2>{{ $t('owner.settings.closures') }}</h2>
                        </div>
                        <p class="muted" style="font-size: 13px; margin: 0 0 6px">{{ $t('owner.settings.closures_note') }}</p>
                        <div v-for="c in closures" :key="c.id" class="crow">
                            <div>
                                <b>{{ fmtRange(c) }}</b>
                                <small>{{ c.field || $t('owner.settings.whole_place') }}{{ c.reason ? ' · ' + c.reason : '' }}</small>
                            </div>
                            <button v-if="canEdit" type="button" class="btn ghost sm hot" @click="removeClosure(c.id)">
                                <Icon name="trash" size="sm" />{{ $t('common.remove') }}
                            </button>
                        </div>
                        <p v-if="!closures.length" class="faint" style="font-size: 13px; margin: 8px 0">{{ $t('owner.settings.no_closures') }}</p>
                        <form v-if="canEdit" style="margin-top: 10px" @submit.prevent="addClosure">
                            <div class="fgrid" style="margin: 8px 0">
                                <div class="field">
                                    <label for="cf">{{ $t('common.field') }}</label>
                                    <select id="cf" v-model="closure.field_id" class="input">
                                        <option :value="null">{{ $t('owner.settings.whole_place') }}</option>
                                        <option v-for="p in prices" :key="p.field_id" :value="p.field_id">{{ p.label }}</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label for="cr">{{ $t('common.reason') }}</label
                                    ><input
                                        id="cr"
                                        v-model="closure.reason"
                                        class="input"
                                        maxlength="255"
                                        :placeholder="$t('owner.reason.maintenance')"
                                    />
                                </div>
                                <div class="field">
                                    <label for="cfrom">{{ $t('common.from') }}</label
                                    ><input id="cfrom" v-model="closure.from" class="input" type="datetime-local" step="1800" required />
                                </div>
                                <div class="field">
                                    <label for="cto">{{ $t('common.to') }}</label
                                    ><input id="cto" v-model="closure.to" class="input" type="datetime-local" step="1800" required />
                                </div>
                            </div>
                            <div class="save-row">
                                <span v-if="Object.keys(closure.errors).length" class="err">{{ Object.values(closure.errors)[0] }}</span>
                                <button class="btn ghost sm" :disabled="closure.processing" data-test="add-closure">
                                    <Icon name="plus" size="sm" />{{ $t('owner.settings.add_closure') }}
                                </button>
                            </div>
                        </form>
                    </section>
                </div>

                <div class="scol">
                    <section class="card panel">
                        <div class="sec-h">
                            <h2>{{ $t('owner.settings.telegram') }}</h2>
                        </div>
                        <p class="muted" style="font-size: 13px; margin: 0 0 12px">{{ $t('owner.settings.telegram_note') }}</p>
                        <TelegramConnect v-if="!user?.telegram" compact />
                        <div v-else class="tg-hint"><Icon name="check" size="sm" />{{ $t('owner.settings.telegram_on') }}</div>
                    </section>

                    <section class="card panel">
                        <div class="sec-h">
                            <h2>{{ $t('common.opening_hours') }}</h2>
                        </div>
                        <label class="srow same">
                            <div>
                                <b>{{ $t('owner.settings.same_hours') }}</b
                                ><small>{{ $t('owner.settings.same_hours_help') }}</small>
                            </div>
                            <span class="switch"
                                ><input
                                    type="checkbox"
                                    :checked="state.same"
                                    :disabled="!canEdit"
                                    data-test="same-hours"
                                    @change="setSame(($event.target as HTMLInputElement).checked)"
                            /></span>
                        </label>
                        <div v-if="state.same" class="hrow2">
                            <b>{{ $t('owner.settings.every_day') }}</b>
                            <select v-model="state.every.open" class="input" :aria-label="$t('common.opens')" :disabled="!canEdit">
                                <option v-for="t in OPEN_T" :key="t" :value="t">{{ t }}</option>
                            </select>
                            <select v-model="state.every.close" class="input" :aria-label="$t('common.closes')" :disabled="!canEdit">
                                <option v-for="t in CLOSE_T" :key="t" :value="t">{{ t }}</option>
                            </select>
                            <span />
                        </div>
                        <template v-else>
                            <div v-for="(h, i) in state.hours" :key="h.weekday" class="hrow2">
                                <b>{{ weekdayName(h.weekday) }}</b>
                                <template v-if="h.open !== null">
                                    <select v-model="h.open" class="input" :aria-label="$t('common.opens')" :disabled="!canEdit">
                                        <option v-for="t in OPEN_T" :key="t" :value="t">{{ t }}</option>
                                    </select>
                                    <select v-model="h.close" class="input" :aria-label="$t('common.closes')" :disabled="!canEdit">
                                        <option v-for="t in CLOSE_T" :key="t" :value="t">{{ t }}</option>
                                    </select>
                                </template>
                                <span v-else class="closed">{{ $t('common.closed') }}</span>
                                <label class="switch" :title="$t('owner.settings.open_day')"
                                    ><input
                                        type="checkbox"
                                        :checked="h.open !== null"
                                        :disabled="!canEdit"
                                        :aria-label="$t('owner.settings.open_day')"
                                        @change="toggleDay(i, ($event.target as HTMLInputElement).checked)"
                                /></label>
                            </div>
                        </template>
                        <span v-if="errOf('hours')" class="err">{{ errOf('hours') }}</span>
                    </section>

                    <section class="card panel">
                        <div class="sec-h">
                            <h2>{{ $t('common.booking_rules') }}</h2>
                        </div>
                        <div class="srow">
                            <div>
                                <b>{{ $t('owner.settings.auto_accept') }}</b
                                ><small>{{ $t('owner.settings.auto_accept_help') }}</small>
                            </div>
                            <label class="switch"
                                ><input
                                    v-model="state.rules.auto_accept_regulars"
                                    type="checkbox"
                                    :disabled="!canEdit"
                                    :aria-label="$t('owner.settings.auto_accept')"
                            /></label>
                        </div>
                        <div class="srow stack">
                            <div>
                                <b>{{ $t('owner.settings.free_cancel') }}</b
                                ><small>{{ $t('owner.settings.free_cancel_help') }}</small>
                            </div>
                            <select
                                v-model.number="state.rules.free_cancel_hours"
                                class="input"
                                :disabled="!canEdit"
                                :aria-label="$t('owner.settings.free_cancel')"
                            >
                                <option v-for="n in withCurrent(CANCEL_H, state.rules.free_cancel_hours)" :key="n" :value="n">
                                    {{ n ? $t('owner.settings.hours_before', { n }) : $t('owner.settings.no_limit') }}
                                </option>
                            </select>
                        </div>
                        <div class="srow stack">
                            <div>
                                <b>{{ $t('owner.settings.notice') }}</b
                                ><small>{{ $t('owner.settings.notice_help') }}</small>
                            </div>
                            <select
                                v-model.number="state.rules.min_notice_min"
                                class="input"
                                :disabled="!canEdit"
                                :aria-label="$t('owner.settings.notice')"
                            >
                                <option v-for="n in withCurrent(NOTICE, state.rules.min_notice_min)" :key="n" :value="n">{{ mins(n) }}</option>
                            </select>
                        </div>
                        <div class="srow stack">
                            <div>
                                <b>{{ $t('owner.settings.expire') }}</b
                                ><small>{{ $t('owner.settings.expire_help') }}</small>
                            </div>
                            <select
                                v-model.number="state.rules.request_expire_min"
                                class="input"
                                :disabled="!canEdit"
                                :aria-label="$t('owner.settings.expire')"
                            >
                                <option v-for="n in withCurrent(EXPIRE, state.rules.request_expire_min)" :key="n" :value="n">{{ mins(n) }}</option>
                            </select>
                        </div>
                        <div class="srow stack">
                            <div>
                                <b>{{ $t('owner.settings.grace') }}</b
                                ><small>{{ $t('owner.settings.grace_help') }}</small>
                            </div>
                            <select v-model.number="state.rules.late_grace_min" class="input" :disabled="!canEdit" :aria-label="$t('owner.settings.grace')">
                                <option v-for="n in withCurrent(GRACE, state.rules.late_grace_min)" :key="n" :value="n">{{ mins(n) }}</option>
                            </select>
                        </div>
                        <span v-if="errOf('rules')" class="err">{{ errOf('rules') }}</span>
                    </section>

                    <section class="card panel">
                        <div class="sec-h">
                            <h2>{{ $t('common.account') }}</h2>
                        </div>
                        <div class="srow">
                            <div>
                                <b>{{ user?.name }}</b
                                ><small>{{ user?.phone }} · {{ $t(owner.role === 'owner' ? 'owner.role_owner' : 'owner.role_staff') }}</small>
                            </div>
                            <Link :href="route('logout')" method="post" as="button" class="btn ghost sm"
                                ><Icon name="logout" size="sm" />{{ $t('owner.sign_out') }}</Link
                            >
                        </div>
                        <div class="srow">
                            <div>
                                <b>{{ $t('common.language') }}</b>
                            </div>
                            <LangSwitch />
                        </div>
                    </section>
                </div>
            </div>
        </div>

        <div v-if="dirty" class="savebar" role="region" :aria-label="$t('owner.settings.save_changes')" data-test="savebar">
            <span class="sb-t">
                <b>{{ $t('owner.settings.unsaved_changes') }}</b>
                <small v-if="firstError" class="err">{{ firstError }}</small>
            </span>
            <button type="button" class="btn ghost sm" :disabled="saving" @click="discard">{{ $t('owner.settings.discard') }}</button>
            <button type="button" class="btn sm" :disabled="saving" data-test="save" @click="save">
                {{ saving ? $t('owner.settings.saving') : $t('owner.settings.save_changes') }}
            </button>
        </div>
    </OwnerLayout>
</template>
