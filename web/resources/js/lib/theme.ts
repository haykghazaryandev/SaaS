import { ref } from 'vue';

// Dark / Light / Auto (the phone's setting), per device (localStorage 'zs.theme'). app.blade.php applies it before
// the page paints; this keeps it in step when it is changed (Profile) or when the phone switches in Auto.
export type ThemeChoice = 'dark' | 'light' | 'auto';
const KEY = 'zs.theme';

function read(): ThemeChoice {
    try {
        const v = localStorage.getItem(KEY);
        return v === 'light' || v === 'auto' ? v : 'dark';
    } catch {
        return 'dark';
    }
}
export const themeChoice = ref<ThemeChoice>(read());
const media = typeof window !== 'undefined' ? window.matchMedia('(prefers-color-scheme: light)') : null;

export function applyTheme() {
    const light = themeChoice.value === 'light' || (themeChoice.value === 'auto' && !!media?.matches);
    const root = document.documentElement;
    if (light) root.dataset.theme = 'light';
    else delete root.dataset.theme;
    document.querySelector('meta[name=theme-color]')?.setAttribute('content', light ? '#f6f8f3' : '#0a0d0b');
}

export function setTheme(t: ThemeChoice) {
    themeChoice.value = t;
    try {
        localStorage.setItem(KEY, t);
    } catch {
        /* private mode: for this visit only */
    }
    applyTheme();
}

export function watchSystemTheme() {
    media?.addEventListener?.('change', () => themeChoice.value === 'auto' && applyTheme());
}
