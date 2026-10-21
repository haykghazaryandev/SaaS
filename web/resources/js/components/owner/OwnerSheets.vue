<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import BookingDetail from '@/components/owner/BookingDetail.vue';
import BookingForm from '@/components/owner/BookingForm.vue';
import ReasonSheet from '@/components/owner/ReasonSheet.vue';
import { decisions, sheets, undo, UNDO_MS } from '@/composables/useOwner';
import { computed } from 'vue';

const d = computed(() => decisions.current);
</script>

<template>
    <BookingDetail v-if="sheets.detail" :id="sheets.detail" :key="'d' + sheets.detail" @close="sheets.detail = null" />
    <BookingForm v-if="sheets.form" :opts="sheets.form" @close="sheets.form = null" />
    <ReasonSheet v-if="sheets.reason" :kind="sheets.reason.kind" :id="sheets.reason.id" :who="sheets.reason.who" @close="sheets.reason = null" />
    <div v-if="sheets.toast" class="otoast" role="alert"><Icon name="x" />{{ sheets.toast }}</div>
    <div v-else-if="d" :key="d.id" :class="['otoast undo', d.kind]" role="status" aria-live="polite" data-test="undo-toast">
        <Icon :name="d.kind === 'accept' ? 'check' : 'x'" size="sm" />
        <span class="ut">{{ $t(`owner.decided.${d.kind === 'accept' ? 'accepted' : 'declined'}`, { who: d.who }) }}</span>
        <button type="button" data-test="undo" @click="undo">{{ $t('owner.undo') }}</button>
        <i class="ubar" :style="{ animationDuration: UNDO_MS + 'ms' }" />
    </div>
</template>
