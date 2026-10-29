<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { useShared } from '@/composables/useShared';
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// One switch for people with several roles: the admin panel, the place panel (as owner), the same panel as the staff
// see it (owners: to check what staff can do), the player app. Plain links (full page loads: the admin panel is not
// part of this app). Shown only when there is something to switch to.
const props = defineProps<{ inline?: boolean }>();
const { user } = useShared();
const page = usePage();
const owner = computed(() => (page.props.owner as { role?: string; realRole?: string } | undefined) ?? null);
const open = ref(false);
const now = computed(() => (owner.value ? (owner.value.role === 'staff' && owner.value.realRole === 'owner' ? 'staff' : 'owner') : 'player'));
const items = computed(() => {
    const out: { to: string; label: string; icon: string }[] = [];
    if (user.value?.isAdmin) out.push({ to: 'admin', label: 'switch.admin', icon: 'settings' });
    if (user.value?.isOwner) {
        out.push({ to: 'owner', label: owner.value?.realRole === 'staff' ? 'switch.panel' : 'switch.owner', icon: 'home' });
        if (owner.value?.realRole !== 'staff') out.push({ to: 'staff', label: 'switch.staff', icon: 'team' });
    }
    out.push({ to: 'player', label: 'switch.player', icon: 'user' });
    return out;
});
const show = computed(() => items.value.length > 1);
const href = (to: string) => `${route('switch')}?to=${to}`;
</script>

<template>
    <div v-if="show" :class="['rsw', { inline: props.inline }]">
        <button v-if="!props.inline" type="button" class="olink rsw-b" :aria-expanded="open" data-test="role-switch" @click="open = !open">
            <Icon name="swap" size="sm" />{{ $t('switch.title') }} · <b>{{ $t('switch.now_' + now) }}</b>
        </button>
        <div v-if="open || props.inline" class="rsw-l" role="menu">
            <a v-for="i in items" :key="i.to" :href="href(i.to)" role="menuitem" :class="{ on: i.to === now }" :data-test="'switch-' + i.to"
                ><Icon :name="i.icon" size="sm" /><span>{{ $t(i.label) }}</span><Icon v-if="i.to === now" name="check" size="sm" class="ok"
            /></a>
        </div>
    </div>
</template>

<style scoped>
.rsw-b { width: 100%; border: 0; background: none; cursor: pointer; font: inherit; text-align: left; }
.rsw-b b { color: var(--accent); font-weight: 800; }
.rsw-l { display: flex; flex-direction: column; gap: 2px; margin: 4px 0 6px; padding: 4px; border-radius: 12px; background: var(--surface-2); border: 1px solid var(--line); }
.rsw-l a { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 9px; color: var(--ink); text-decoration: none; font-weight: 700; font-size: 14px; }
.rsw-l a:hover { background: var(--surface-3); }
.rsw-l a.on { color: var(--accent); }
.rsw-l .ok { margin-left: auto; }
.inline .rsw-l { margin: 0; background: none; border: 0; padding: 0; }
</style>
