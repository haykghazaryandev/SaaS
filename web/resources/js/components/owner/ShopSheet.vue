<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import OwnerSheet from '@/components/owner/OwnerSheet.vue';
import { amd } from '@/composables/useShared';
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

// What a game took from the shop: every product with its photo and price, − / + to change how many are on
// the check. Each + is one line on the check; − takes the last one of that product off.
interface Item {
    id: number;
    product_id: number | null;
    name: string;
    qty: number;
    sum: number;
}
const props = defineProps<{ bookingId: number; items: Item[]; products: { id: number; name: string; price: number; photo: string | null }[]; total: number }>();
const emit = defineEmits<{ close: [] }>();
const opts = { preserveScroll: true, preserveState: true };
const count = (pid: number) => props.items.filter((i) => i.product_id === pid).reduce((s, i) => s + i.qty, 0);
const plus = (pid: number) => router.post(route('owner.bookings.items.store', props.bookingId), { product_id: pid, qty: 1 }, opts);
function minus(pid: number) {
    const last = [...props.items].reverse().find((i) => i.product_id === pid);
    if (last) router.delete(route('owner.bookings.items.destroy', last.id), opts);
}
const other = computed(() => props.items.filter((i) => !props.products.some((p) => p.id === i.product_id)));
</script>

<template>
    <OwnerSheet :title="$t('owner.club.shop_title')" @close="emit('close')">
        <div class="plist">
            <div v-for="p in products" :key="p.id" :class="['prow', { on: count(p.id) }]">
                <div class="img"><img v-if="p.photo" :src="p.photo" alt="" /><Icon v-else name="cube" /></div>
                <span class="nm"><b>{{ p.name }}</b><small>{{ amd(p.price) }}</small></span>
                <div class="qty">
                    <button type="button" :disabled="!count(p.id)" :aria-label="$t('common.remove')" data-test="minus" @click="minus(p.id)"><Icon name="minus" size="sm" /></button>
                    <b>{{ count(p.id) }}</b>
                    <button type="button" class="add" :aria-label="$t('owner.club.add_one')" data-test="plus" @click="plus(p.id)"><Icon name="plus" size="sm" /></button>
                </div>
            </div>
            <!-- lines whose product is gone from the shop -->
            <div v-for="i in other" :key="'o' + i.id" class="prow">
                <div class="img"><Icon name="cube" /></div>
                <span class="nm"><b>{{ i.qty }} × {{ i.name }}</b><small>{{ amd(i.sum) }}</small></span>
            </div>
            <p v-if="!products.length" class="faint">{{ $t('owner.club.no_products') }}</p>
        </div>
        <div class="tot">
            <span>{{ $t('owner.club.shop_total') }}</span><b>{{ amd(total) }}</b>
        </div>
        <button type="button" class="btn block" @click="emit('close')">{{ $t('owner.club.done') }}</button>
    </OwnerSheet>
</template>

<style scoped>
.plist { display: flex; flex-direction: column; gap: 8px; max-height: 55vh; overflow-y: auto; }
.prow { display: flex; align-items: center; gap: 12px; padding: 8px; border-radius: 14px; background: var(--surface-2); border: 1px solid transparent; }
.prow.on { border-color: color-mix(in srgb, var(--accent) 45%, transparent); }
.img { width: 56px; height: 56px; border-radius: 12px; overflow: hidden; flex: none; background: var(--surface-3); display: grid; place-items: center; color: var(--faint); }
.img img { width: 100%; height: 100%; object-fit: cover; }
.nm { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.nm small { color: var(--muted); font-family: var(--mono); }
.qty { display: flex; align-items: center; gap: 10px; }
.qty b { min-width: 18px; text-align: center; font-family: var(--mono); font-size: 16px; }
.qty button { width: 36px; height: 36px; border-radius: 10px; border: 1px solid var(--line-2); background: var(--surface); color: var(--ink); display: grid; place-items: center; }
.qty button:disabled { opacity: .35; }
.qty .add { background: var(--accent); color: var(--on-accent); border-color: var(--accent); }
.tot { display: flex; justify-content: space-between; align-items: baseline; margin: 14px 0 10px; font-weight: 800; }
.tot b { font-family: var(--mono); font-size: 20px; color: var(--accent); }
</style>
