<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { MENU_LEVELS, useOwner, type MenuKey, type OwnerMenus } from '@/composables/useOwner';
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

// Settings → Menus: each menu of the place is a small card with two switches, Owner and Staff. A menu with levels
// (Schedule, Shop, Tournaments, Money) shows "Staff can: View · Edit" once Staff is on. ZoneSpot fee, Team and
// Settings stay the owner's. Below: the menus staff will see. Its own Save (the server also closes what staff can't use).
const owner = useOwner();
const menus = computed(() => owner.value.menus);
const ICON: Record<MenuKey, string> = {
    now: 'clock',
    schedule: 'calendar',
    requests: 'bell',
    players: 'users',
    cards: 'wallet',
    shop: 'cube',
    tournaments: 'trophy',
    messages: 'chat',
    money: 'chart',
};
const LABEL: Record<MenuKey, string> = {
    now: 'owner.nav.now',
    schedule: 'owner.nav.schedule',
    requests: 'owner.nav.requests',
    players: 'owner.nav.our_players',
    cards: 'owner.nav.cards',
    shop: 'owner.nav.shop',
    tournaments: 'owner.nav.tournaments',
    messages: 'owner.nav.messages',
    money: 'owner.nav.money',
};
type LevelKey = keyof typeof MENU_LEVELS;
const hasLevels = (k: MenuKey): k is LevelKey => k in MENU_LEVELS;

const copy = (m: OwnerMenus) => ({ owner: { ...m.owner }, staff: { ...m.staff }, level: { ...m.level } });
const state = reactive(copy(menus.value));
const work = computed(() => menus.value.available.filter((k) => k !== 'money'));
const money = computed(() => menus.value.available.filter((k) => k === 'money'));
const staffSees = computed(() => menus.value.available.filter((k) => state.staff[k]));
const dirty = computed(() => JSON.stringify(state) !== JSON.stringify(copy(menus.value)));
const saving = ref(false);
const error = ref('');

function setLevel(k: LevelKey, l: string) {
    (state.level as Record<string, string>)[k] = l;
}
function save() {
    error.value = '';
    saving.value = true;
    router.put(route('owner.settings.menus'), state, {
        preserveScroll: true,
        onError: (e) => (error.value = Object.values(e)[0] ?? ''),
        onSuccess: () => Object.assign(state, copy(menus.value)),
        onFinish: () => (saving.value = false),
    });
}
</script>

<template>
    <section id="s-menus" class="card panel" data-test="menus">
        <div class="sec-h">
            <h2>{{ $t('owner.menus.title') }}</h2>
        </div>
        <p class="muted" style="font-size: 13px; margin: 0 0 6px">{{ $t('owner.menus.note') }}</p>

        <template
            v-for="grp in [
                { h: 'owner.menus.group_work', keys: work },
                { h: 'owner.nav.money', keys: money },
            ]"
            :key="grp.h"
        >
            <div v-if="grp.keys.length" class="mgrp">{{ $t(grp.h) }}</div>
            <div v-for="k in grp.keys" :key="k" :class="['mrow', { off: !state.owner[k] && !state.staff[k] }]" :data-test="'menu-' + k">
                <div class="mtop">
                    <span class="mn"
                        ><span class="ic"><Icon :name="ICON[k]" size="sm" /></span>{{ $t(LABEL[k]) }}</span
                    >
                    <span class="pair">
                        <label class="sw"
                            ><small>{{ $t('switch.owner_panel') }}</small
                            ><input v-model="state.owner[k]" type="checkbox" role="switch" :data-test="'owner-' + k"
                        /></label>
                        <label class="sw"
                            ><small>{{ $t('switch.staff_panel') }}</small
                            ><input v-model="state.staff[k]" type="checkbox" role="switch" :data-test="'staff-' + k"
                        /></label>
                    </span>
                </div>
                <!-- how much staff may do: only while staff see the menu -->
                <div v-if="hasLevels(k) && state.staff[k]" class="lvl">
                    <small>{{ $t('owner.menus.staff_can') }}</small>
                    <span class="lseg" role="group">
                        <button
                            v-for="l in MENU_LEVELS[k]"
                            :key="l"
                            type="button"
                            :aria-pressed="state.level[k] === l"
                            :data-test="`level-${k}-${l}`"
                            @click="setLevel(k, l)"
                        >
                            {{ $t('owner.menus.lvl_' + l) }}
                        </button>
                    </span>
                </div>
            </div>
        </template>

        <div class="mgrp">{{ $t('owner.menus.group_owner') }}</div>
        <div
            v-for="n in [
                { i: 'percent', l: 'owner.nav.fee' },
                { i: 'team', l: 'owner.nav.team' },
                { i: 'settings', l: 'owner.nav.settings' },
            ]"
            :key="n.l"
            class="mrow lock"
        >
            <div class="mtop">
                <span class="mn"
                    ><span class="ic"><Icon :name="n.i" size="sm" /></span>{{ $t(n.l) }}</span
                >
                <span class="lk">🔒 {{ $t('switch.owner_panel') }}</span>
            </div>
        </div>

        <div class="msee" data-test="staff-sees">
            <b>{{ $t('owner.menus.staff_see') }}</b>
            <div class="chips">
                <span v-for="k in staffSees" :key="k" :class="['chip', { lv: hasLevels(k) }]"
                    >{{ $t(LABEL[k]) }}<template v-if="hasLevels(k)"> · {{ $t('owner.menus.lvl_' + state.level[k]) }}</template></span
                >
                <span v-if="!staffSees.length" class="faint">{{ $t('owner.menus.staff_none') }}</span>
            </div>
        </div>
        <p v-if="error" class="err">{{ error }}</p>
        <button v-if="dirty" type="button" class="btn" style="width: 100%; margin-top: 12px" :disabled="saving" data-test="menus-save" @click="save">
            <Icon name="check" size="sm" />{{ $t('common.save') }}
        </button>
    </section>
</template>

<style scoped>
.mgrp {
    color: var(--muted);
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    padding: 12px 2px 6px;
}
.mrow {
    background: var(--surface-2);
    border-radius: 14px;
    padding: 9px 12px;
    margin-bottom: 8px;
    transition: opacity 0.15s;
}
.mrow.off {
    opacity: 0.6;
}
/* not enough room for the name next to the switches (long words, Armenian): the switches go under it, on the right */
.mtop {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px 10px;
}
.mn {
    display: flex;
    align-items: center;
    gap: 9px;
    font-weight: 700;
    font-size: 14.5px;
    flex: 1 1 150px;
    min-width: 0;
}
.ic {
    width: 28px;
    height: 28px;
    border-radius: 9px;
    background: var(--surface-3);
    display: grid;
    place-items: center;
    flex: none;
}
.pair {
    display: flex;
    gap: 12px;
    flex: none;
    margin-left: auto;
}
.sw {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 3px;
    cursor: pointer;
}
.sw small {
    font-size: 10px;
    color: var(--faint);
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    white-space: nowrap;
}
.sw input {
    appearance: none;
    width: 38px;
    height: 22px;
    border-radius: 99px;
    background: var(--surface-3);
    position: relative;
    margin: 0;
    cursor: pointer;
    transition: background 0.15s;
}
.sw input::after {
    content: '';
    position: absolute;
    left: 3px;
    top: 3px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: var(--muted);
    transition:
        transform 0.15s,
        background 0.15s;
}
.sw input:checked {
    background: var(--accent);
}
.sw input:checked::after {
    transform: translateX(16px);
    background: var(--on-accent);
}
.sw input:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.lvl {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid var(--line-2);
}
.lvl small {
    color: var(--faint);
    font-size: 12px;
    font-weight: 700;
}
.lseg {
    display: inline-grid;
    grid-auto-flow: column;
    grid-auto-columns: minmax(64px, auto);
    background: var(--surface);
    border: 1px solid var(--line-2);
    border-radius: 11px;
    padding: 2px;
}
.lseg button {
    border: 0;
    background: none;
    color: var(--muted);
    font: 700 12.5px var(--body);
    padding: 5px 10px;
    border-radius: 8px;
    cursor: pointer;
    white-space: nowrap;
}
.lseg button[aria-pressed='true'] {
    background: var(--accent);
    color: var(--on-accent);
}
.mrow.lock {
    opacity: 0.75;
}
.lk {
    margin-left: auto;
    font-size: 11.5px;
    color: var(--faint);
    font-weight: 800;
    white-space: nowrap;
}
.msee {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px dashed var(--line-2);
}
.msee b {
    display: block;
    font-size: 13.5px;
    margin-bottom: 6px;
}
.chips {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
}
.chip {
    font-size: 12px;
    font-weight: 700;
    background: var(--surface-3);
    padding: 3px 9px;
    border-radius: 8px;
}
.chip.lv {
    color: var(--accent);
    background: color-mix(in srgb, var(--accent) 12%, transparent);
}
.err {
    color: var(--hot);
    font-weight: 700;
    font-size: 13px;
    margin: 10px 0 0;
}
</style>
