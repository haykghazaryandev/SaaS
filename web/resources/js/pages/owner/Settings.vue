<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import TelegramConnect from '@/components/TelegramConnect.vue';
import { fdate, useOwner } from '@/composables/useOwner';
import { useShared } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

// Settings: place info (read-only), prices per field, opening hours, booking rules, closures. Only the owner role can change them.
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
    place: { name: string; address: string | null; district: string; phone: string | null; status: string };
    prices: PriceRow[];
    hours: HourRow[];
    rules: { free_cancel_hours: number; min_notice_min: number; request_expire_min: number; auto_accept_regulars: boolean };
    closures: ClosureRow[];
}>();
const owner = useOwner();
const { user } = useShared();
const opts = { preserveScroll: true, preserveState: true };

// prices
const priceForm = useForm({ prices: props.prices.map((p) => ({ field_id: p.field_id, day: p.day, evening: p.evening, weekend: p.weekend })) });
const priceOf = (id: number) => props.prices.find((p) => p.field_id === id)!;
function savePrices() {
    priceForm.transform((d) => ({
        prices: d.prices.map((p) => ({
            ...p,
            day: +String(p.day).replace(/\D/g, '') || 0,
            evening: +String(p.evening).replace(/\D/g, '') || 0,
            weekend: +String(p.weekend).replace(/\D/g, '') || 0,
        })),
    }));
    priceForm.put(route('owner.settings.prices'), opts);
}

// opening hours (Monday first)
const ORDER = [1, 2, 3, 4, 5, 6, 0];
const hours = useForm({ hours: ORDER.map((wd) => ({ ...props.hours.find((h) => h.weekday === wd)! })) });
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
    const h = hours.hours[i];
    h.open = open ? '09:00' : null;
    h.close = open ? '23:00' : null;
}
function copyMonday() {
    const m = hours.hours[0];
    hours.hours.forEach((h) => {
        h.open = m.open;
        h.close = m.close;
    });
}
const hoursError = computed(() => Object.values(hours.errors)[0] || '');

// rules
const rules = useForm({ ...props.rules });
const CANCEL_H = [0, 2, 6, 12, 24, 48];
const NOTICE = [0, 30, 60, 120, 240];
const EXPIRE = [30, 60, 120, 240, 720];
const mins = (m: number) =>
    m === 0 ? trans('owner.settings.none') : m < 60 ? trans('owner.settings.min', { n: m }) : trans('owner.settings.hours_n', { n: m / 60 });
const withCurrent = (list: number[], v: number) => [...new Set([...list, v])].sort((a, b) => a - b);

// closures
const today = owner.value.today;
const closure = useForm({ field_id: null as number | null, from: `${today}T09:00`, to: `${today}T12:00`, reason: '' });
function addClosure() {
    closure.post(route('owner.closures.store'), { ...opts, onSuccess: () => closure.reset('reason') });
}
function removeClosure(id: number) {
    if (window.confirm(trans('owner.settings.closure_remove_q'))) router.delete(route('owner.closures.destroy', id), opts);
}
const fmtRange = (c: ClosureRow) =>
    `${c.from.slice(8, 10)}.${c.from.slice(5, 7)} ${c.from.slice(11)} – ${c.to.slice(0, 10) === c.from.slice(0, 10) ? '' : c.to.slice(8, 10) + '.' + c.to.slice(5, 7) + ' '}${c.to.slice(11)}`;
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.settings')">
        <div class="phead">
            <div>
                <div class="kicker">{{ $t('owner.settings.kicker') }}</div>
                <h1>{{ $t('owner.nav.settings') }}</h1>
            </div>
        </div>
        <div v-if="!canEdit" class="readonly-note"><Icon name="eye" size="sm" />{{ $t('owner.settings.readonly') }}</div>

        <div class="sgrid">
            <div style="display: flex; flex-direction: column; gap: 18px; min-width: 0">
                <section class="card panel">
                    <div class="sec-h">
                        <h2>{{ $t('owner.settings.profile') }}</h2>
                        <span class="pill green" v-if="place.status === 'active'">{{ $t('owner.settings.active') }}</span>
                    </div>
                    <h3 style="font-size: 20px; margin-bottom: 12px">{{ place.name }}</h3>
                    <div class="kvs">
                        <div class="kv">
                            <Icon name="pin" size="sm" /><span>{{ $t('owner.settings.address') }}</span
                            ><b>{{ place.address || '—' }}</b>
                        </div>
                        <div class="kv">
                            <Icon name="phone" size="sm" /><span>{{ $t('owner.b.phone') }}</span
                            ><b>{{ place.phone || '—' }}</b>
                        </div>
                        <div class="kv">
                            <Icon name="grid" size="sm" /><span>{{ $t('owner.settings.fields') }}</span
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
                    <form @submit.prevent="savePrices">
                        <div v-for="(p, i) in priceForm.prices" :key="p.field_id" class="pfield">
                            <div class="lbl">
                                <b>{{ priceOf(p.field_id).label }}</b
                                ><small
                                    >{{ $t(`owner.surface.${priceOf(p.field_id).surface}`)
                                    }}{{ priceOf(p.field_id).indoor ? ' · ' + $t('owner.settings.indoor') : '' }}</small
                                >
                            </div>
                            <div class="field">
                                <label :for="`pd${i}`">{{ $t('owner.settings.p_day') }}</label
                                ><input :id="`pd${i}`" v-model="p.day" class="input mono" inputmode="numeric" :disabled="!canEdit" />
                            </div>
                            <div class="field">
                                <label :for="`pe${i}`">{{ $t('owner.settings.p_evening') }}</label
                                ><input :id="`pe${i}`" v-model="p.evening" class="input mono" inputmode="numeric" :disabled="!canEdit" />
                            </div>
                            <div class="field">
                                <label :for="`pw${i}`">{{ $t('owner.settings.p_weekend') }}</label
                                ><input :id="`pw${i}`" v-model="p.weekend" class="input mono" inputmode="numeric" :disabled="!canEdit" />
                            </div>
                        </div>
                        <div v-if="canEdit" class="save-row">
                            <span v-if="Object.keys(priceForm.errors).length" class="err">{{ Object.values(priceForm.errors)[0] }}</span>
                            <button class="btn sm" :disabled="priceForm.processing" data-test="save-prices">
                                {{ $t('owner.settings.save_prices') }}
                            </button>
                        </div>
                    </form>
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
                            <Icon name="trash" size="sm" />{{ $t('owner.settings.remove') }}
                        </button>
                    </div>
                    <p v-if="!closures.length" class="faint" style="font-size: 13px; margin: 8px 0">{{ $t('owner.settings.no_closures') }}</p>
                    <form v-if="canEdit" style="margin-top: 10px" @submit.prevent="addClosure">
                        <div class="fgrid" style="margin: 8px 0">
                            <div class="field">
                                <label for="cf">{{ $t('owner.b.field') }}</label>
                                <select id="cf" v-model="closure.field_id" class="input">
                                    <option :value="null">{{ $t('owner.settings.whole_place') }}</option>
                                    <option v-for="p in prices" :key="p.field_id" :value="p.field_id">{{ p.label }}</option>
                                </select>
                            </div>
                            <div class="field">
                                <label for="cr">{{ $t('owner.settings.reason') }}</label
                                ><input
                                    id="cr"
                                    v-model="closure.reason"
                                    class="input"
                                    maxlength="255"
                                    :placeholder="$t('owner.reason.maintenance')"
                                />
                            </div>
                            <div class="field">
                                <label for="cfrom">{{ $t('owner.settings.from') }}</label
                                ><input id="cfrom" v-model="closure.from" class="input" type="datetime-local" step="1800" required />
                            </div>
                            <div class="field">
                                <label for="cto">{{ $t('owner.settings.to') }}</label
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

            <div style="display: flex; flex-direction: column; gap: 18px; min-width: 0">
                <section class="card panel">
                    <div class="sec-h">
                        <h2>{{ $t('owner.settings.tg') }}</h2>
                    </div>
                    <p class="muted" style="font-size: 13px; margin: 0 0 12px">{{ $t('owner.settings.tg_note') }}</p>
                    <TelegramConnect v-if="!user?.telegram" compact />
                    <div v-else class="tg-hint"><Icon name="check" size="sm" />{{ $t('owner.settings.tg_on') }}</div>
                </section>

                <section class="card panel">
                    <div class="sec-h">
                        <h2>{{ $t('owner.settings.hours') }}</h2>
                        <button v-if="canEdit" type="button" class="btn ghost sm" @click="copyMonday">{{ $t('owner.settings.copy_monday') }}</button>
                    </div>
                    <form @submit.prevent="hours.put(route('owner.settings.hours'), opts)">
                        <div v-for="(h, i) in hours.hours" :key="h.weekday" class="hrow2">
                            <b>{{ weekdayName(h.weekday) }}</b>
                            <template v-if="h.open !== null">
                                <select v-model="h.open" class="input" :aria-label="$t('owner.settings.opens')" :disabled="!canEdit">
                                    <option v-for="t in OPEN_T" :key="t" :value="t">{{ t }}</option>
                                </select>
                                <select v-model="h.close" class="input" :aria-label="$t('owner.settings.closes')" :disabled="!canEdit">
                                    <option v-for="t in CLOSE_T" :key="t" :value="t">{{ t }}</option>
                                </select>
                            </template>
                            <span v-else class="closed">{{ $t('owner.settings.closed') }}</span>
                            <label class="switch" :title="$t('owner.settings.open_day')"
                                ><input
                                    type="checkbox"
                                    :checked="h.open !== null"
                                    :disabled="!canEdit"
                                    :aria-label="$t('owner.settings.open_day')"
                                    @change="toggleDay(i, ($event.target as HTMLInputElement).checked)"
                            /></label>
                        </div>
                        <div v-if="canEdit" class="save-row">
                            <span v-if="hoursError" class="err">{{ hoursError }}</span>
                            <button class="btn sm" :disabled="hours.processing" data-test="save-hours">{{ $t('owner.a.save') }}</button>
                        </div>
                    </form>
                </section>

                <section class="card panel">
                    <div class="sec-h">
                        <h2>{{ $t('owner.settings.rules') }}</h2>
                    </div>
                    <form @submit.prevent="rules.put(route('owner.settings.rules'), opts)">
                        <div class="srow">
                            <div>
                                <b>{{ $t('owner.settings.auto') }}</b
                                ><small>{{ $t('owner.settings.auto_s') }}</small>
                            </div>
                            <label class="switch"
                                ><input
                                    v-model="rules.auto_accept_regulars"
                                    type="checkbox"
                                    :disabled="!canEdit"
                                    :aria-label="$t('owner.settings.auto')"
                            /></label>
                        </div>
                        <div class="srow">
                            <div>
                                <b>{{ $t('owner.settings.free_cancel') }}</b
                                ><small>{{ $t('owner.settings.free_cancel_s') }}</small>
                            </div>
                            <select
                                v-model.number="rules.free_cancel_hours"
                                class="input"
                                :disabled="!canEdit"
                                :aria-label="$t('owner.settings.free_cancel')"
                            >
                                <option v-for="n in withCurrent(CANCEL_H, rules.free_cancel_hours)" :key="n" :value="n">
                                    {{ n ? $t('owner.settings.h_before', { n }) : $t('owner.settings.none') }}
                                </option>
                            </select>
                        </div>
                        <div class="srow">
                            <div>
                                <b>{{ $t('owner.settings.notice') }}</b
                                ><small>{{ $t('owner.settings.notice_s') }}</small>
                            </div>
                            <select
                                v-model.number="rules.min_notice_min"
                                class="input"
                                :disabled="!canEdit"
                                :aria-label="$t('owner.settings.notice')"
                            >
                                <option v-for="n in withCurrent(NOTICE, rules.min_notice_min)" :key="n" :value="n">{{ mins(n) }}</option>
                            </select>
                        </div>
                        <div class="srow">
                            <div>
                                <b>{{ $t('owner.settings.expire') }}</b
                                ><small>{{ $t('owner.settings.expire_s') }}</small>
                            </div>
                            <select
                                v-model.number="rules.request_expire_min"
                                class="input"
                                :disabled="!canEdit"
                                :aria-label="$t('owner.settings.expire')"
                            >
                                <option v-for="n in withCurrent(EXPIRE, rules.request_expire_min)" :key="n" :value="n">{{ mins(n) }}</option>
                            </select>
                        </div>
                        <div v-if="canEdit" class="save-row">
                            <span v-if="Object.keys(rules.errors).length" class="err">{{ Object.values(rules.errors)[0] }}</span>
                            <button class="btn sm" :disabled="rules.processing" data-test="save-rules">{{ $t('owner.a.save') }}</button>
                        </div>
                    </form>
                </section>

                <section class="card panel">
                    <div class="sec-h">
                        <h2>{{ $t('owner.settings.account') }}</h2>
                    </div>
                    <div class="row wrapflex">
                        <span class="muted" style="flex: 1"
                            >{{ user?.name }} · {{ user?.phone }} · {{ $t(owner.role === 'owner' ? 'owner.role_owner' : 'owner.role_staff') }}</span
                        >
                        <Link :href="route('logout')" method="post" as="button" class="btn ghost sm"
                            ><Icon name="logout" size="sm" />{{ $t('owner.sign_out') }}</Link
                        >
                    </div>
                </section>
            </div>
        </div>
    </OwnerLayout>
</template>
