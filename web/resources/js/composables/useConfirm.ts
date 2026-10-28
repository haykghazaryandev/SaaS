import { reactive } from 'vue';

// "Are you sure?" before money is taken, a player is removed, a game is stopped, a result is saved …
// const ok = await ask({ title, text, ok, danger }); one dialog for the whole app (ConfirmDialog in the layouts).
export interface Ask {
    title: string;
    text?: string;
    /** the label of the "yes" button (default: Yes) */
    ok?: string;
    /** a red "yes": removing, stopping, refunding */
    danger?: boolean;
}

export const confirmState = reactive<{ open: Ask | null; resolve: ((v: boolean) => void) | null }>({ open: null, resolve: null });

export function ask(a: Ask): Promise<boolean> {
    confirmState.resolve?.(false);
    return new Promise((resolve) => {
        confirmState.open = a;
        confirmState.resolve = resolve;
    });
}

export function answer(v: boolean) {
    const r = confirmState.resolve;
    confirmState.open = null;
    confirmState.resolve = null;
    r?.(v);
}
