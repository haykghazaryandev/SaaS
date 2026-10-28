<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import PlayerRecord from '@/components/owner/PlayerRecord.vue';
import OwnerSheet from '@/components/owner/OwnerSheet.vue';
import { ask } from '@/composables/useConfirm';
import { act, askCancel, askDecline, decide, dur, openForm, pillOf, relDay, useOwner, whoOf, type BookingDetail } from '@/composables/useOwner';
import { amd, hhmm } from '@/composables/useShared';
import { trans } from '@/lib/i18n';
import { Link, router } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

// Booking details: contact, notes, price, history and the actions for the place.
const props = defineProps<{ id: number }>();
const emit = defineEmits<{ close: [] }>();
const owner = useOwner();
const b = ref<BookingDetail | null>(null);
const failed = ref(false);

onMounted(async () => {
    try {
        const r = await fetch(route('owner.bookings.show', props.id), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!r.ok) throw new Error(String(r.status));
        b.value = await r.json();
    } catch {
        failed.value = true;
    }
});

const pill = computed(() => (b.value ? pillOf(b.value) : null));
const holds = computed(() => b.value && ['pending', 'accepted'].includes(b.value.status));
const sourceLabel = computed(() => (b.value ? trans(`owner.source.${b.value.source}`) : ''));

async function noShow() {
    if (!b.value || !(await ask({ title: trans('owner.action.no_show_ask'), danger: true }))) return;
    act('no-show', b.value.id, {}, () => emit('close'));
}
// "the team is here" (optional): then "how was the field?" goes to the players 30 min after the game
function arrived() {
    if (!b.value) return;
    act('arrived', b.value.id, {}, () => emit('close'));
}
function accept() {
    if (!b.value) return;
    decide('accept', b.value.id, { who: whoOf(b.value) });
    emit('close');
}
function edit() {
    if (b.value) openForm({ booking: b.value });
}
// the bill: time + things taken during the game, paid at the end (card after a scan, cash, or both)
const hasFeature = (f: string) => (owner.value.features ?? []).includes(f);
// a place with the desk: "Start game" instead of "Arrived", and a bill only once the game was started
const desk = computed(() => hasFeature('wallet') || hasFeature('shop') || hasFeature('money'));
const billable = computed(() => b.value && desk.value && !!b.value.game_started && ['accepted', 'completed'].includes(b.value.status));
async function startGame() {
    if (!b.value) return;
    if (await ask({ title: trans('owner.club.ask_start', { name: whoOf(b.value) }), text: trans('owner.club.ask_start_text'), ok: trans('owner.club.start_game') }))
        router.post(route('owner.bookings.start', b.value.id), {}, { preserveScroll: true, onSuccess: () => emit('close') });
}
const itemsSum = computed(() => (b.value?.items ?? []).reduce((s, i) => s + i.sum, 0));
</script>

<template>
    <OwnerSheet wide @close="emit('close')">
        <div v-if="!b" class="loading">
            <div class="row" style="justify-content: flex-end">
                <button type="button" class="x" :aria-label="$t('common.close')" @click="emit('close')"><Icon name="x" /></button>
            </div>
            {{ failed ? $t('owner.error.generic') : $t('common.loading') }}
        </div>
        <template v-else>
            <div class="row wrapflex" style="margin-bottom: 12px; gap: 6px">
                <span :class="['pill', pill?.cls]">{{ pill?.label }}</span>
                <span class="pill mono">{{ b.code }}</span>
                <span v-if="b.weekly" class="pill">{{ $t('owner.booking.weekly') }}</span>
                <span v-if="b.arrived" class="pill green">{{ $t('common.arrived') }}</span>
                <span v-else-if="b.late_minutes && !b.past" class="pill warn">{{ $t('owner.booking.late', { min: String(b.late_minutes) }) }}</span>
                <span v-else-if="b.status === 'accepted' && b.started && !b.past" class="pill warn">{{ $t('owner.booking.waiting') }}</span>
                <button type="button" class="x" :aria-label="$t('common.close')" @click="emit('close')"><Icon name="x" /></button>
            </div>
            <h3 style="font-size: 22px; margin-bottom: 2px">{{ b.name }}</h3>
            <div class="muted" style="font-size: 14px; margin-bottom: 14px">{{ b.team ? b.team + ' · ' : '' }}{{ sourceLabel }}</div>
            <div class="kvs">
                <div class="kv">
                    <Icon name="grid" size="sm" /><span>{{ $t('common.field') }}</span
                    ><b>{{ b.field }}</b>
                </div>
                <div class="kv">
                    <Icon name="calendar" size="sm" /><span>{{ $t('common.when') }}</span
                    ><b>{{ relDay(b.date, owner.today) }} · {{ hhmm(b.start) }}–{{ hhmm(b.end) }}</b>
                </div>
                <div class="kv">
                    <Icon name="clock" size="sm" /><span>{{ $t('common.duration') }}</span
                    ><b>{{ dur(b.minutes) }}</b>
                </div>
                <div class="kv">
                    <Icon name="wallet" size="sm" /><span>{{ $t('common.price') }}</span
                    ><b class="mono">{{ amd(b.price) }}</b>
                </div>
                <div class="kv">
                    <Icon name="phone" size="sm" /><span>{{ $t('common.phone') }}</span>
                    <b
                        ><a v-if="b.tel" class="tel" :href="`tel:${b.tel}`">{{ b.phone }}</a
                        ><template v-else>—</template></b
                    >
                </div>
                <div v-if="b.user" class="kv">
                    <Icon name="user" size="sm" /><span>{{ $t('owner.booking.player') }}</span>
                    <b
                        >{{ b.user.name || '—'
                        }}<PlayerRecord :history="b.history" style="margin-left: 6px; vertical-align: middle" /></b
                    >
                </div>
                <div v-if="b.note_player" class="kv">
                    <Icon name="msg" size="sm" /><span>{{ $t('owner.booking.note_player') }}</span
                    ><b class="note">{{ b.note_player }}</b>
                </div>
                <div v-if="b.note_owner" class="kv">
                    <Icon name="edit" size="sm" /><span>{{ $t('owner.booking.note_owner') }}</span
                    ><b class="note">{{ b.note_owner }}</b>
                </div>
                <div v-if="b.decline_reason" class="kv">
                    <Icon name="x" size="sm" /><span>{{ $t('owner.booking.decline_reason') }}</span
                    ><b>{{ b.decline_reason }}</b>
                </div>
                <div v-if="b.cancel_reason" class="kv">
                    <Icon name="x" size="sm" /><span>{{ $t('owner.booking.cancel_reason') }}</span
                    ><b>{{ b.cancel_reason }}</b>
                </div>
            </div>
            <div class="tg-hint" style="margin: 12px 0 4px">
                <Icon name="send" size="sm" />{{ $t(b.tel ? 'owner.booking.telegram_hint' : 'owner.booking.no_phone') }}
            </div>
            <div class="hist">
                <div class="kicker" style="margin: 14px 0 8px">{{ $t('common.history') }}</div>
                <div v-if="!b.events.length" class="hrow">
                    <i /><span>{{ sourceLabel }}</span
                    ><span class="faint" />
                </div>
                <div v-for="(e, i) in b.events" :key="i" class="hrow">
                    <i />
                    <span>
                        {{ e.label }} <span class="muted">· {{ e.who }}</span>
                        <small v-for="(d, k) in e.details" :key="k">{{ d }}</small>
                    </span>
                    <span class="faint">{{ e.at }}</span>
                </div>
            </div>
            <div class="sheet-actions">
                <template v-if="b.status === 'pending' && !b.started">
                    <button type="button" class="btn" data-test="accept" @click="accept">
                        <Icon name="check" size="sm" />{{ $t('common.accept') }}
                    </button>
                    <button type="button" class="btn danger" data-test="decline" @click="askDecline(b.id, whoOf(b))">
                        <Icon name="x" size="sm" />{{ $t('common.decline') }}
                    </button>
                </template>
                <button v-if="holds" type="button" class="btn ghost" data-test="edit" @click="edit">
                    <Icon name="edit" size="sm" />{{ $t('common.edit') }}
                </button>
                <button v-if="b.status === 'accepted' && !b.started" type="button" class="btn ghost hot" data-test="cancel" @click="askCancel(b.id)">
                    {{ $t('common.cancel_booking') }}
                </button>
                <button v-if="desk && b.can_start" type="button" class="btn" data-test="start-game" @click="startGame">
                    <Icon name="check" size="sm" />{{ $t('owner.club.start_game') }}
                </button>
                <button v-if="b.can_arrive && !desk" type="button" class="btn" data-test="arrived" @click="arrived">
                    <Icon name="check" size="sm" />{{ $t('common.arrived') }}
                </button>
                <button v-if="b.can_no_show" type="button" class="btn ghost hot" data-test="noshow" @click="noShow">
                    {{ $t('owner.action.no_show') }}
                </button>
                <a v-if="b.tel" class="btn ghost" :href="`tel:${b.tel}`"><Icon name="phone" size="sm" />{{ $t('common.call') }}</a>
            </div>
            <div v-if="billable" class="sheet-actions">
                <span v-if="b.paid" class="pill green"><Icon name="check" size="sm" />{{ $t('owner.club.paid') }}</span>
                <span v-else-if="b.items?.length" class="faint">{{ $t('owner.club.items_n', { n: b.items.length, a: amd(itemsSum) }) }}</span>
                <Link class="btn" :href="route('owner.bookings.checkout', b.id)" data-test="checkout" @click="emit('close')"
                    ><Icon name="wallet" size="sm" />{{ b.paid ? $t('owner.club.bill') : $t('owner.club.bill_and_pay') }}</Link
                >
            </div>
        </template>
    </OwnerSheet>
</template>
