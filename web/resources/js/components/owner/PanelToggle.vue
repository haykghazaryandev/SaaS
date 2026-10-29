<script setup lang="ts">
import { useOwner } from '@/composables/useOwner';
import { computed } from 'vue';

// The owner works in two panels: the owner panel (everything) and the staff panel (what their staff see and do). One
// tap switches (full page loads: the menu changes). Staff never see it: they only have the staff panel.
const owner = useOwner();
const isOwner = computed(() => owner.value.realRole === 'owner');
const inStaff = computed(() => owner.value.role === 'staff');
const href = (to: 'owner' | 'staff') => `${route('switch')}?to=${to}`;
</script>

<template>
    <div v-if="isOwner" class="seg ptog" role="group" :aria-label="$t('switch.panels')" data-test="panel-toggle">
        <a :href="href('owner')" :aria-pressed="!inStaff" data-test="panel-owner">{{ $t('switch.owner_panel') }}</a>
        <a :href="href('staff')" :aria-pressed="inStaff" data-test="panel-staff">{{ $t('switch.staff_panel') }}</a>
    </div>
</template>

<style scoped>
.ptog { display: grid; grid-template-columns: 1fr 1fr; width: 100%; }
.ptog a { text-align: center; text-decoration: none; color: inherit; font-size: 13px; font-weight: 700; padding: 8px 4px; border-radius: 10px; line-height: 1.2; }
.ptog a[aria-pressed='true'] { background: var(--accent); color: #0b0f0c; }
</style>
