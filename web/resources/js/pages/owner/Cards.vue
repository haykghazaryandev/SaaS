<script setup lang="ts">
import EmptyState from '@/components/EmptyState.vue';
import Avatar from '@/components/Avatar.vue';
import Icon from '@/components/Icon.vue';
import QrScanner from '@/components/owner/QrScanner.vue';

import { amd } from '@/composables/useShared';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

// Cards of the place: balance and cashback level of each player's card. Looking is free; money on a card changes only
// after scanning its QR (the player at the desk). A player without a card yet gets one by phone.
interface Row {
    code: string;
    name: string | null;
    phone: string | null;
    avatar: string | null;
    userId: number;
    balance: number;
    cashback: number;
}
const props = defineProps<{ q: string; cards: Row[]; totals: { cards: number; balance: number } }>();
const scan = ref(false);
const search = ref(props.q);
let t: number | undefined;
watch(search, (v) => {
    clearTimeout(t);
    t = window.setTimeout(() => router.get(route('owner.cards'), v ? { q: v } : {}, { preserveState: true, replace: true, only: ['cards', 'q'] }), 300);
});
const add = useForm({ phone: '' });
const submitAdd = () => add.post(route('owner.cards.store'), { preserveScroll: true });
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.cards')">
        <div class="phead">
            <div>
                <div class="kicker">{{ $t('owner.club.cards_kicker', { n: totals.cards, a: amd(totals.balance) }) }}</div>
                <h1>{{ $t('owner.nav.cards') }}</h1>
            </div>
            <div class="phead-r">
                <button type="button" class="btn" data-test="scan-big" @click="scan = true"><Icon name="qr" size="sm" />{{ $t('owner.club.scan') }}</button>
            </div>
        </div>

        <section class="card panel">
            <div class="toolbar" style="margin: 0">
                <div class="search" style="flex: 1">
                    <Icon name="search" /><input v-model="search" class="input" :placeholder="$t('owner.club.search_ph')" data-test="search" />
                </div>
            </div>
            <div class="plist">
                <Link v-for="c in cards" :key="c.code" class="prow" :href="route('owner.cards.show', c.code)" data-test="player-row">
                    <Avatar :id="c.userId" :name="c.name" :src="c.avatar" size="sm" />
                    <span class="who"><b>{{ c.name || '—' }}</b><small>{{ c.phone }}</small></span>
                    <span v-if="c.cashback" class="pill green">{{ c.cashback }}%</span>
                    <b class="bal">{{ amd(c.balance) }}</b>
                </Link>
                <EmptyState v-if="!cards.length" compact icon="wallet" :title="q ? $t('owner.schedule.none') : $t('owner.empty.cards')" :text="q ? undefined : $t('owner.club.no_cards')" />
            </div>
        </section>

        <section class="card panel">
            <div class="sec-h"><h2>{{ $t('owner.club.add_title') }}</h2></div>
            <p class="muted" style="font-size: 13px; margin: 0 0 10px">{{ $t('owner.club.add_sub') }}</p>
            <p class="faint" style="font-size: 12.5px; margin: 0 0 10px">{{ $t('owner.club.scan_rule') }}</p>
            <form class="row" style="gap: 8px" @submit.prevent="submitAdd">
                <span class="input pre">+374</span>
                <input v-model="add.phone" class="input mono" inputmode="tel" placeholder="91 234 567" style="flex: 1" />
                <button class="btn" :disabled="add.processing">{{ $t('owner.club.open_card') }}</button>
            </form>
            <div v-if="add.errors.phone" class="err">{{ add.errors.phone }}</div>
        </section>
        <QrScanner v-if="scan" @close="scan = false" />
    </OwnerLayout>
</template>

<style scoped>
.plist { display: flex; flex-direction: column; margin-top: 10px; }
.prow { display: flex; align-items: center; gap: 12px; padding: 10px 4px; border-bottom: 1px solid var(--line); color: var(--ink); }
.prow:last-child { border-bottom: 0; }
.prow:hover { background: var(--surface-2); }
.prow .who { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.prow .who small { color: var(--muted); font-size: 12.5px; }
.prow .bal { font-family: var(--mono); font-size: 14px; }
</style>
