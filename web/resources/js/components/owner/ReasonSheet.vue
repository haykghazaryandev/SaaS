<script setup lang="ts">
import OwnerSheet from '@/components/owner/OwnerSheet.vue';
import { act, decide } from '@/composables/useOwner';
import { ref } from 'vue';

// Decline a request or cancel a booking, with an optional reason for the player.
const props = defineProps<{ kind: 'decline' | 'cancel'; id: number; who?: string }>();
const emit = defineEmits<{ close: [] }>();
const reason = ref('');
const busy = ref(false);
const QUICK = ['owner.reason.time_taken', 'owner.reason.maintenance', 'owner.reason.call'];

function go() {
    if (props.kind === 'decline') {
        // declines wait 5 s with an "Undo" toast, like accepts
        decide('decline', props.id, { reason: reason.value.trim() || null, who: props.who });
        emit('close');
        return;
    }
    busy.value = true;
    act(props.kind, props.id, { reason: reason.value.trim() || null }, () => emit('close'));
    setTimeout(() => (busy.value = false), 600);
}
</script>

<template>
    <OwnerSheet :title="$t(kind === 'decline' ? 'owner.reason.decline_title' : 'common.cancel_this_booking')" @close="emit('close')">
        <div class="field">
            <label for="rr">{{ $t('owner.reason.label') }}</label>
            <textarea id="rr" v-model="reason" class="input" rows="3" maxlength="255" :placeholder="$t('owner.reason.placeholder')" />
        </div>
        <div class="chips" style="margin: 10px 0 18px; flex-wrap: wrap">
            <button v-for="q in QUICK" :key="q" type="button" class="chip" @click="reason = $t(q)">{{ $t(q) }}</button>
        </div>
        <div class="row">
            <button type="button" class="btn ghost" style="flex: 1" @click="emit('close')">{{ $t('common.keep_it') }}</button>
            <button type="button" class="btn danger" style="flex: 1" :disabled="busy" data-test="reason-ok" @click="go">
                {{ $t(kind === 'decline' ? 'common.decline' : 'common.cancel_booking') }}
            </button>
        </div>
    </OwnerSheet>
</template>
