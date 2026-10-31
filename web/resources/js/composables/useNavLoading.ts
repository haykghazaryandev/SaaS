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
    const done = () => {
        clearTimeout(t);
        loading.value = false;
    };
    const offFinish = router.on('finish', done);
    // back / forward: the page comes from the browser's history (no "finish"), so never keep the placeholders
    const offNavigate = router.on('navigate', done);
    window.addEventListener('popstate', done);
    onBeforeUnmount(() => {
        clearTimeout(t);
        offStart();
        offFinish();
        offNavigate();
        window.removeEventListener('popstate', done);
    });
    return loading;
}
