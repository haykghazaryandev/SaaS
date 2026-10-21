<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import BookingDetail from '@/components/owner/BookingDetail.vue';
import BookingForm from '@/components/owner/BookingForm.vue';
import ReasonSheet from '@/components/owner/ReasonSheet.vue';
import { sheets } from '@/composables/useOwner';
</script>

<template>
    <BookingDetail v-if="sheets.detail" :id="sheets.detail" :key="'d' + sheets.detail" @close="sheets.detail = null" />
    <BookingForm v-if="sheets.form" :opts="sheets.form" @close="sheets.form = null" />
    <ReasonSheet v-if="sheets.reason" :kind="sheets.reason.kind" :id="sheets.reason.id" @close="sheets.reason = null" />
    <div v-if="sheets.toast" class="otoast" role="alert"><Icon name="x" />{{ sheets.toast }}</div>
</template>
