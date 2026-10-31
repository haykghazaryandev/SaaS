import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';

/**
 * True while a page is opened and it takes a moment (slow mobile internet): the layouts show grey placeholders
 * (PageSkeleton) instead of the old page. Only full page visits (a menu, a link), not the quick reloads of a part
 * (polls, the day strip), forms or prefetching; shown after 250 ms so fast pages never blink.
 */
export function useNavLoading() {
    const loading = ref(false);
    let t: number | undefined;
    const offStart = router.on('start', (e) => {
        const v = e.detail.visit;
        if (v.method !== 'get' || v.only.length || v.except.length || v.prefetch || v.preserveState === true) return;
        clearTimeout(t);
        t = window.setTimeout(() => (loading.value = true), 250);
    });
    const offFinish = router.on('finish', () => {
        clearTimeout(t);
        loading.value = false;
    });
    onBeforeUnmount(() => {
        clearTimeout(t);
        offStart();
        offFinish();
    });
    return loading;
}
