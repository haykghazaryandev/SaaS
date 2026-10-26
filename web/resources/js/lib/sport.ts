import { onBeforeUnmount, watch, type WatchSource } from 'vue';

/**
 * The look of the sport on screen: <html data-sport="pingpong"> switches the accent colours (bookball.css).
 * Call in a page's setup with the sport it shows; leaving the page goes back to the default look.
 */
export function useSportTheme(sport: WatchSource<string | null | undefined>): void {
    const set = (s: string | null | undefined) => {
        if (typeof document === 'undefined') return;
        if (s && s !== 'football') document.documentElement.dataset.sport = s;
        else delete document.documentElement.dataset.sport;
    };
    watch(sport, set, { immediate: true });
    onBeforeUnmount(() => set(null));
}
