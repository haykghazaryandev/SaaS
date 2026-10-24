<script setup lang="ts">
import { useShared } from '@/composables/useShared';
import { computed, ref } from 'vue';

// The map of one place: Google Maps by default, with a switch to Yandex Maps (both free embeds, no API key).
// <PlaceMap :lat="40.2079" :lng="44.4953" :name="'Zangak Football Park'" :height="260" />
const props = withDefaults(defineProps<{ lat: number; lng: number; name?: string; height?: number }>(), { name: '', height: 260 });
const { locale } = useShared();

const KEY = 'bb.map';
const read = () => {
    try {
        return localStorage.getItem(KEY) === 'yandex' ? 'yandex' : 'google';
    } catch {
        return 'google';
    }
};
const provider = ref<'google' | 'yandex'>(read());
function use(p: 'google' | 'yandex') {
    provider.value = p;
    try {
        localStorage.setItem(KEY, p); // remember the choice on this device
    } catch {
        /* private mode */
    }
}

const lang = computed(() => (locale.value === 'hy' ? 'hy' : locale.value === 'ru' ? 'ru' : 'en'));
const src = computed(() =>
    provider.value === 'google'
        ? `https://maps.google.com/maps?q=${props.lat},${props.lng}&z=16&hl=${lang.value}&output=embed`
        : `https://yandex.com/map-widget/v1/?ll=${props.lng},${props.lat}&z=16&pt=${props.lng},${props.lat},pm2gnm&lang=${lang.value === 'en' ? 'en_US' : lang.value === 'ru' ? 'ru_RU' : 'en_US'}`,
);
// directions in the chosen app
const routeUrl = computed(() =>
    provider.value === 'google'
        ? `https://www.google.com/maps/dir/?api=1&destination=${props.lat},${props.lng}`
        : `https://yandex.com/maps/?rtext=~${props.lat},${props.lng}&rtt=auto`,
);
defineExpose({ routeUrl });
</script>

<template>
    <div class="pmap">
        <div class="pmap-top">
            <div class="seg" role="group" :aria-label="$t('common.map')">
                <button type="button" :aria-pressed="provider === 'google'" @click="use('google')">Google</button>
                <button type="button" :aria-pressed="provider === 'yandex'" @click="use('yandex')">Yandex</button>
            </div>
            <a class="btn sm" :href="routeUrl" target="_blank" rel="noopener">{{ $t('common.directions') }}</a>
        </div>
        <iframe
            :key="provider"
            :src="src"
            :title="name || $t('common.map')"
            :style="{ height: height + 'px' }"
            loading="lazy"
            referrerpolicy="no-referrer-when-downgrade"
            allowfullscreen
        ></iframe>
    </div>
</template>

<style scoped>
.pmap { display: flex; flex-direction: column; gap: 10px; }
.pmap-top { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
.pmap .seg { width: auto; }
iframe { width: 100%; border: 0; border-radius: var(--r-md, 14px); background: var(--surface-2); display: block; }
</style>
