<script setup lang="ts">
import Avatar from '@/components/Avatar.vue';
import Icon from '@/components/Icon.vue';
import QrScanner from '@/components/owner/QrScanner.vue';
import { useOwner } from '@/composables/useOwner';
import { amd } from '@/composables/useShared';
import { SPORT_ICON } from '@/composables/usePlayer';
import OwnerLayout from '@/layouts/OwnerLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

// Players of the place: their cards (balance, discount, rating). Scan a card QR or find a player by name / phone;
// a player without a card yet gets one by phone (they must have a Bookball account).
interface Row {
    code: string;
    name: string | null;
    phone: string | null;
    avatar: string | null;
    userId: number;
    balance: number;
    discount: number;
    rating: number | null;
}
const props = defineProps<{ q: string; cards: Row[]; sport: string }>();
const owner = useOwner();
const has = (f: string) => (owner.value.features ?? []).includes(f);
const scan = ref(false);
const search = ref(props.q);
let t: number | undefined;
watch(search, (v) => {
    clearTimeout(t);
    t = window.setTimeout(() => router.get(route('owner.players'), v ? { q: v } : {}, { preserveState: true, replace: true, only: ['cards', 'q'] }), 300);
});
const add = useForm({ phone: '' });
const submitAdd = () => add.post(route('owner.cards.store'), { preserveScroll: true });
</script>

<template>
    <OwnerLayout :title="$t('owner.nav.players')">
        <div class="phead">
            <div>
                <div class="kicker">{{ $t('owner.club.players_kicker') }} · {{ owner.venue.name }}</div>
                <h1>{{ $t('owner.nav.players') }}</h1>
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
                    <span v-if="has('rating') && c.rating != null" class="pill lime">{{ SPORT_ICON[sport] }} {{ c.rating }}</span>
                    <span v-if="has('wallet') && c.discount" class="pill">−{{ c.discount }}%</span>
                    <b v-if="has('wallet')" class="bal">{{ amd(c.balance) }}</b>
                </Link>
                <p v-if="!cards.length" class="faint" style="margin: 14px 0 4px">{{ q ? $t('owner.schedule.none') : $t('owner.club.no_cards') }}</p>
            </div>
        </section>

        <section class="card panel">
            <div class="sec-h"><h2>{{ $t('owner.club.add_title') }}</h2></div>
            <p class="muted" style="font-size: 13px; margin: 0 0 10px">{{ $t('owner.club.add_sub') }}</p>
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
