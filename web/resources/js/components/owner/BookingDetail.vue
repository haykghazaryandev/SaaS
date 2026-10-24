<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import OwnerSheet from '@/components/owner/OwnerSheet.vue';
import { act, askCancel, askDecline, decide, dur, openForm, pillOf, relDay, useOwner, whoOf, type BookingDetail } from '@/composables/useOwner';
import { amd, hhmm } from '@/composables/useShared';
import { trans } from 'laravel-vue-i18n';
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

function noShow() {
    if (!b.value || !window.confirm(trans('owner.a.no_show_ask'))) return;
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
</script>

<template>
    <OwnerSheet wide @close="emit('close')">
        <div v-if="!b" class="loading">
            <div class="row" style="justify-content: flex-end">
                <button type="button" class="x" :aria-label="$t('owner.close')" @click="emit('close')"><Icon name="x" /></button>
            </div>
            {{ failed ? $t('owner.err.generic') : $t('owner.loading') }}
        </div>
        <template v-else>
            <div class="row wrapflex" style="margin-bottom: 12px; gap: 6px">
                <span :class="['pill', pill?.cls]">{{ pill?.label }}</span>
                <span class="pill mono">{{ b.code }}</span>
                <span v-if="b.weekly" class="pill">{{ $t('owner.b.weekly') }}</span>
                <span v-if="b.arrived" class="pill green">{{ $t('owner.b.arrived') }}</span>
                <span v-else-if="b.late_minutes && !b.past" class="pill warn">{{ $t('owner.b.late', { min: String(b.late_minutes) }) }}</span>
                <span v-else-if="b.status === 'accepted' && b.started && !b.past" class="pill warn">{{ $t('owner.b.waiting') }}</span>
                <button type="button" class="x" :aria-label="$t('owner.close')" @click="emit('close')"><Icon name="x" /></button>
            </div>
            <h3 style="font-size: 22px; margin-bottom: 2px">{{ b.name }}</h3>
            <div class="muted" style="font-size: 14px; margin-bottom: 14px">{{ b.team ? b.team + ' · ' : '' }}{{ sourceLabel }}</div>
            <div class="kvs">
                <div class="kv">
                    <Icon name="grid" size="sm" /><span>{{ $t('owner.b.field') }}</span
                    ><b>{{ b.field }}</b>
                </div>
                <div class="kv">
                    <Icon name="calendar" size="sm" /><span>{{ $t('owner.b.when') }}</span
                    ><b>{{ relDay(b.date, owner.today) }} · {{ hhmm(b.start) }}–{{ hhmm(b.end) }}</b>
                </div>
                <div class="kv">
                    <Icon name="clock" size="sm" /><span>{{ $t('owner.b.duration') }}</span
                    ><b>{{ dur(b.minutes) }}</b>
                </div>
                <div class="kv">
                    <Icon name="wallet" size="sm" /><span>{{ $t('owner.b.price') }}</span
                    ><b class="mono">{{ amd(b.price) }}</b>
                </div>
                <div class="kv">
                    <Icon name="phone" size="sm" /><span>{{ $t('owner.b.phone') }}</span>
                    <b
                        ><a v-if="b.tel" class="tel" :href="`tel:${b.tel}`">{{ b.phone }}</a
                        ><template v-else>—</template></b
                    >
                </div>
                <div v-if="b.user" class="kv">
                    <Icon name="user" size="sm" /><span>{{ $t('owner.b.player') }}</span>
                    <b
                        >{{ b.user.name || '—'
                        }}<span v-if="b.user.no_shows" class="pill hot" style="margin-left: 6px">{{
                            $t('owner.b.no_shows', { n: b.user.no_shows })
                        }}</span></b
                    >
                </div>
                <div v-if="b.note_player" class="kv">
                    <Icon name="msg" size="sm" /><span>{{ $t('owner.b.note_player') }}</span
                    ><b class="note">{{ b.note_player }}</b>
                </div>
                <div v-if="b.note_owner" class="kv">
                    <Icon name="edit" size="sm" /><span>{{ $t('owner.b.note_owner') }}</span
                    ><b class="note">{{ b.note_owner }}</b>
                </div>
                <div v-if="b.decline_reason" class="kv">
                    <Icon name="x" size="sm" /><span>{{ $t('owner.b.decline_reason') }}</span
                    ><b>{{ b.decline_reason }}</b>
                </div>
                <div v-if="b.cancel_reason" class="kv">
                    <Icon name="x" size="sm" /><span>{{ $t('owner.b.cancel_reason') }}</span
                    ><b>{{ b.cancel_reason }}</b>
                </div>
            </div>
            <div class="tg-hint" style="margin: 12px 0 4px">
                <Icon name="send" size="sm" />{{ $t(b.tel ? 'owner.b.tg_hint' : 'owner.b.no_phone') }}
            </div>
            <div class="hist">
                <div class="kicker" style="margin: 14px 0 8px">{{ $t('owner.b.history') }}</div>
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
                        <Icon name="check" size="sm" />{{ $t('owner.a.accept') }}
                    </button>
                    <button type="button" class="btn danger" data-test="decline" @click="askDecline(b.id, whoOf(b))">
                        <Icon name="x" size="sm" />{{ $t('owner.a.decline') }}
                    </button>
                </template>
                <button v-if="holds" type="button" class="btn ghost" data-test="edit" @click="edit">
                    <Icon name="edit" size="sm" />{{ $t('owner.a.edit') }}
                </button>
                <button v-if="b.status === 'accepted' && !b.started" type="button" class="btn ghost hot" data-test="cancel" @click="askCancel(b.id)">
                    {{ $t('owner.a.cancel') }}
                </button>
                <button v-if="b.can_arrive" type="button" class="btn" data-test="arrived" @click="arrived">
                    <Icon name="check" size="sm" />{{ $t('owner.a.arrived') }}
                </button>
                <button v-if="b.can_no_show" type="button" class="btn ghost hot" data-test="noshow" @click="noShow">
                    {{ $t('owner.a.no_show') }}
                </button>
                <a v-if="b.tel" class="btn ghost" :href="`tel:${b.tel}`"><Icon name="phone" size="sm" />{{ $t('owner.a.call') }}</a>
            </div>
        </template>
    </OwnerSheet>
</template>
