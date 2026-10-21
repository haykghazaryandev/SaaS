import type { Config, RouteParams } from 'ziggy-js';

// Ziggy's route() helper is a global (rendered by @routes in app.blade.php).
// route() without arguments returns the router: route().current('games.*'), route().has('messages.show').
type Router = Config & { current: (name?: string, params?: unknown) => boolean | string | undefined; has: (name: string) => boolean; params: Record<string, string> };

declare global {
    function route(): Router;
    function route(name: string, params?: RouteParams<string> | number | string | (number | string)[] | Record<string, unknown>, absolute?: boolean): string;
}

declare module 'vue' {
    interface ComponentCustomProperties {
        route: typeof route;
    }
}

export {};
