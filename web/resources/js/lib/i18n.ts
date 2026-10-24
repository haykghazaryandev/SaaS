// Interface texts in the browser, the Laravel way, without a library.
//
// The texts come from GET /lang/<locale>.json?v=<version> (LangController: the `translations` table, flat keys such as
// "player.size.small"). loadTexts() runs before the app mounts, so no page ever shows raw keys; the URL version changes
// with every edit, so the browser caches the file (app.blade.php preloads it).
//
//   trans('common.players_count', { n: 10 })            → ":n" placeholders, also :Name (ucfirst) and :NAME (upper)
//   transChoice('games.spotsLeft', 3, { n: 3 })   → "{1} 1 spot left|[2,*] :n spots left" (Laravel's trans_choice rules)
//   $t() and $tChoice() in templates (the i18n plugin)
import type { App } from 'vue';
import { reactive } from 'vue';

type Replacements = Record<string, string | number>;

const state = reactive({ locale: '', version: '', texts: {} as Record<string, string> });

/** Loads the texts of a language (once per locale + version). */
export async function loadTexts(locale: string, version: string): Promise<void> {
    if (state.locale === locale && state.version === version) return;
    try {
        const res = await fetch(`/lang/${locale}.json?v=${encodeURIComponent(version)}`, { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        state.texts = (await res.json()) as Record<string, string>;
        state.locale = locale;
        state.version = version;
    } catch {
        // offline or the server is down: keep what we have (keys are shown, the page still works)
    }
}

export function trans(key: string, replacements: Replacements = {}): string {
    return replace(state.texts[key] ?? key, replacements);
}

/** Laravel's trans_choice: "{0} none|{1} one|[2,4] few|[5,*] many", or plain "one|many" picked by the language's plural rule. */
export function transChoice(key: string, count: number, replacements: Replacements = {}): string {
    const segments = (state.texts[key] ?? key).split('|');
    const exact = segments.map(extract).find((s) => s && matches(s.condition, count));
    const line = exact
        ? exact.text
        : plural(
              segments.map((s) => extract(s)?.text ?? s),
              count,
          );

    return replace(line.trim(), { count, ...replacements });
}

// ---- Laravel's MessageSelector, in short ----

/** "{1} text" → {condition: "{1}", text}; "[2,*] text" alike; null when the segment has no condition. */
function extract(segment: string): { condition: string; text: string } | null {
    const m = segment.match(/^\s*([{[][^[\]{}]*[}\]])(.*)$/s);

    return m ? { condition: m[1], text: m[2] } : null;
}

function matches(condition: string, n: number): boolean {
    const body = condition.slice(1, -1);
    if (condition.startsWith('{')) return body.split(',').some((v) => v.trim() !== '*' && Number(v) === n);
    const [from, to] = body.split(',').map((v) => v.trim());

    return (from === '*' || n >= Number(from)) && (to === '*' || n <= Number(to));
}

/** The form by the language's plural rule when no explicit condition matched (en, hy: one|other; ru: one|few|many). */
function plural(forms: string[], n: number): string {
    let index: number;
    if (state.locale === 'ru') {
        index = n % 10 === 1 && n % 100 !== 11 ? 0 : n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20) ? 1 : 2;
    } else if (state.locale === 'hy') {
        index = n === 0 || n === 1 ? 0 : 1;
    } else {
        index = n === 1 ? 0 : 1;
    }

    return forms.length === 1 || forms[index] === undefined ? forms[0] : forms[index];
}

/** ":name" → value, ":Name" → Value, ":NAME" → VALUE; longer names first, so :name never eats :n. */
function replace(text: string, replacements: Replacements): string {
    for (const key of Object.keys(replacements).sort((a, b) => b.length - a.length)) {
        const v = String(replacements[key]);
        text = text
            .split(':' + key)
            .join(v)
            .split(':' + key.charAt(0).toUpperCase() + key.slice(1))
            .join(v.charAt(0).toUpperCase() + v.slice(1))
            .split(':' + key.toUpperCase())
            .join(v.toUpperCase());
    }

    return text;
}

/** app.use(i18n): $t() and $tChoice() in every template. */
export const i18n = {
    install(app: App) {
        app.config.globalProperties.$t = trans;
        app.config.globalProperties.$tChoice = transChoice;
    },
};

declare module 'vue' {
    interface ComponentCustomProperties {
        $t: typeof trans;
        $tChoice: typeof transChoice;
    }
}
