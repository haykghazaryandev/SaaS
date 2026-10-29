<script setup lang="ts">
import Avatar from '@/components/Avatar.vue';
import Icon from '@/components/Icon.vue';
import RequestChips from '@/components/owner/RequestChips.vue';
import { accept, askDecline, decisions, dotOf, openBooking, relDay, useOwner, whoOf, type RequestRow } from '@/composables/useOwner';
import { amd, hhmm } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { trans } from '@/lib/i18n';
import { router, usePoll } from '@inertiajs/vue3';
import { computed } from 'vue';

// Requests: every request waiting for an answer, soonest first. Players who asked for the same court and time are
// one group ("Same time · Table 1 · tomorrow 19:00 · 3 players"), the best choice first (★), with how the order is
// made; Accept one and the others hear the time went to another player. Each request: training, rating, their record
// here, the time, the price, the phone, other requests it clashes with.
const props = defineProps<{ date: string; requests: RequestRow[]; canDemo?: boolean }>();
const owner = useOwner();
usePoll(30000, { only: ['requests'] });
const fieldOf = (id: number) => owner.value.fields.find((f) => f.id === id);
const shown = computed(() => props.requests.filter((b) => !decisions.hidden[b.id]));
// consecutive requests for the same court and time (they come sorted that way) make one group
const groups = computed(() => {
    const out: RequestRow[][] = [];
    for (const b of shown.value) {
        const last = out[out.length - 1];
        if (last && last.some((x) => (x.competing ?? []).includes(b.id))) last.push(b);
        else out.push([b]);
    }
    return out;
});
const conflictText = (c: RequestRow['conflicts'][number]) =>
    trans('owner.dashboard.conflict', { name: c.name, time: `${hhmm(c.start)} · ${fieldOf(c.field_id)?.format ?? ''}` });
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.requests')">
        <div class="phead">
            <div>
                <div class="kicker">{{ owner.venue.name }}</div>
                <h1>
                    {{ $t('owner.nav.requests') }} <span v-if="shown.length" class="pill warn">{{ shown.length }}</span>
                </h1>
            </div>
            <!-- not in production: test requests again -->
            <div v-if="canDemo && owner.role === 'owner'" class="phead-r">
                <button type="button" class="btn ghost sm" data-test="demo-requests" @click="router.post(route('owner.requests.demo'), {}, { preserveScroll: true })">
                    <Icon name="plus" size="sm" />{{ $t('owner.requests.demo') }}
                </button>
            </div>
        </div>

        <div class="rqlist">
            <section v-for="g in groups" :key="g[0].id" :class="{ 'card panel grp': g.length > 1 }" :data-test="g.length > 1 ? 'request-group' : undefined">
                <div v-if="g.length > 1" class="grp-h">
                    <b
                        ><Icon name="clock" size="sm" />{{ $t('owner.requests.same_time_h', { n: g.length }) }} · {{ fieldOf(g[0].field_id)?.label }} ·
                        {{ relDay(g[0].date, date) }} {{ hhmm(g[0].start) }}–{{ hhmm(g[0].end) }}</b
                    >
                    <small class="faint">{{ $t('owner.requests.rank_how') }}</small>
                </div>
                <div v-for="b in g" :key="b.id" :class="['req', { train: b.purpose === 'training' }]" data-test="request">
                    <div class="who" @click="openBooking(b.id)">
                        <div class="who-h">
                            <Avatar :id="b.user?.id" :name="b.name" :src="b.user?.avatar" size="sm" />
                            <span class="who-n"
                                ><b>{{ b.name }}</b
                                ><span v-if="b.team" class="muted"> · {{ b.team }}</span></span
                            >
                        </div>
                        <div style="margin: 4px 0 0 38px"><RequestChips :b="b" /></div>
                        <div class="meta">
                            <span><i :class="['fdot', dotOf(fieldOf(b.field_id)?.size || 'small')]" />{{ fieldOf(b.field_id)?.label }}</span>
                            <span><Icon name="calendar" size="sm" />{{ relDay(b.date, date) }} · {{ hhmm(b.start) }}–{{ hhmm(b.end) }}</span>
                            <span><Icon name="wallet" size="sm" />{{ amd(b.price) }}</span>
                            <a v-if="b.tel" class="tel" :href="`tel:${b.tel}`" data-test="tel" @click.stop><Icon name="phone" size="sm" />{{ b.phone }}</a>
                            <span v-if="b.sent_ago" class="faint">{{ $t('owner.dashboard.sent', { ago: b.sent_ago }) }}</span>
                        </div>
                        <!-- clashes outside the group: the same player asking for two courts at once -->
                        <div v-if="b.conflicts.filter((c) => !g.some((x) => x.id === c.id)).length" class="conf">
                            <span v-for="c in b.conflicts.filter((c) => !g.some((x) => x.id === c.id))" :key="c.id" class="cchip" data-test="conflict"
                                ><Icon name="clock" size="sm" />{{ conflictText(c) }}</span
                            >
                        </div>
                    </div>
                    <div class="acts">
                        <button type="button" class="btn" data-test="accept" @click="accept(b.id, b)"><Icon name="check" size="sm" />{{ $t('common.accept') }}</button>
                        <button type="button" class="btn danger" data-test="decline" @click="askDecline(b.id, whoOf(b))"><Icon name="x" size="sm" />{{ $t('common.decline') }}</button>
                    </div>
                </div>
            </section>
            <div v-if="!shown.length" class="card panel empty">
                <Icon name="check" size="lg" />
                <p style="margin: 8px 0 0">{{ $t('owner.dashboard.no_requests') }}</p>
            </div>
        </div>
    </OwnerLayout>
</template>

<style scoped>
.rqlist { display: flex; flex-direction: column; gap: 12px; max-width: 920px; }
.grp { border-color: color-mix(in srgb, var(--warn) 40%, transparent); padding: 14px; }
.grp-h { display: flex; flex-direction: column; gap: 2px; margin-bottom: 10px; }
.grp-h b { display: flex; align-items: center; gap: 6px; color: var(--warn); }
.grp-h small { font-size: 12px; }
.req.train { box-shadow: inset 3px 0 0 #ff7ad9; }
.empty { text-align: center; color: var(--muted); padding: 30px; }
</style>
