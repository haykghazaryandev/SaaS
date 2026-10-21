import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/** Shared Inertia props: current user, language, flash messages. */
export function useShared() {
    const page = usePage<SharedData>();
    return {
        user: computed(() => page.props.auth.user),
        locale: computed(() => page.props.locale),
        flash: computed(() => page.props.flash),
    };
}

/** "21.5" -> "21:30" */
export function hhmm(h: number): string {
    const hh = Math.floor(h);
    const mm = Math.round((h - hh) * 60);
    return `${String(hh).padStart(2, '0')}:${String(mm).padStart(2, '0')}`;
}

/** 25000 -> "25 000 ֏" */
export function amd(n: number): string {
    return Math.round(n).toLocaleString('en-US').replace(/,/g, ' ') + ' ֏';
}
