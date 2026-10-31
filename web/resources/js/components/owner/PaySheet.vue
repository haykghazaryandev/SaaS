<script setup lang="ts">
import OwnerSheet from '@/components/owner/OwnerSheet.vue';
import PayBox, { type PayBill } from '@/components/owner/PayBox.vue';
import { amd } from '@/composables/useShared';

// Now → Take money: a window over the desk (like + Item), not another page. What is still to pay: the time (or what
// was added to it after paying before), the items not paid yet; then PayBox (cash by default).
defineProps<{
    title: string;
    game: {
        id: number;
        purpose?: string;
        player: { id: number } | null;
        bill: PayBill & { minutes: number; items: { id: number; name: string; qty: number; sum: number; paid?: boolean }[] };
    };
    wallet: boolean;
}>();
const emit = defineEmits<{ close: [] }>();
</script>

<template>
    <OwnerSheet :title="title" @close="emit('close')">
        <div class="pbill" data-test="pay-sheet">
            <div v-if="game.bill.timeDue" class="bl">
                <span>{{ game.purpose === 'training' ? $t('club.training') : $t('owner.club.time') }} · {{ $t('owner.club.min_n', { n: game.bill.minutes }) }}</span
                ><b>{{ amd(game.bill.timeDue) }}</b>
            </div>
            <div v-for="i in game.bill.items.filter((x) => !x.paid)" :key="i.id" class="bl">
                <span>{{ i.qty }} × {{ i.name }}</span><b>{{ amd(i.sum) }}</b>
            </div>
            <div v-if="game.bill.paidSoFar" class="bl faint">
                <span>{{ $t('owner.club.paid_before') }}</span><b>{{ amd(game.bill.paidSoFar) }}</b>
            </div>
            <div class="bl total">
                <span>{{ $t('owner.club.to_take') }}</span><b data-test="due">{{ amd(game.bill.due) }}</b>
            </div>
        </div>
        <PayBox :booking-id="game.id" :bill="game.bill" :wallet="wallet" :has-player="!!game.player" @done="emit('close')" />
    </OwnerSheet>
</template>

<style scoped>
.pbill { margin-bottom: 12px; }
.bl { display: flex; justify-content: space-between; gap: 10px; padding: 8px 0; border-bottom: 1px solid var(--line); font-size: 14.5px; }
.bl b { font-family: var(--mono); }
.bl.total { border-bottom: 0; font-size: 18px; font-weight: 800; }
.bl.total b { font-size: 22px; color: var(--accent); }
</style>
