import { reactive, watch } from 'vue';

/**
 * Per-device preferences, in localStorage rather than on the user: which theme
 * you want is a property of the screen in front of you, not of the account.
 *
 * The storage key and the `theme` shape are mirrored by an inline script in
 * `resources/views/app.blade.php`, which applies the theme before first paint.
 * Change one and change the other.
 */
const KEY = 'dms.preferences';

const DEFAULTS = {
    theme: 'light',
};

const stored = () => {
    try {
        return JSON.parse(localStorage.getItem(KEY) || '{}');
    } catch {
        /** Private browsing can refuse to read; the defaults are a fine answer. */
        return {};
    }
};

export const preferences = reactive({ ...DEFAULTS, ...stored() });

let applied = null;

const swap = () => {
    document.documentElement.classList.toggle('dark', preferences.theme === 'dark');
};

export const applyTheme = () => {
    const changed = applied !== null && applied !== preferences.theme;
    applied = preferences.theme;

    /**
     * Only a deliberate change animates. The first call settles the theme the inline script in
     * app.blade.php already applied, and animating that would fade every page load in from the
     * wrong theme.
     *
     * A View Transition cross-fades snapshots of the page taken either side of the swap, so no
     * element animates its own colors and nothing can fall out of step with anything else. Where
     * the API is missing the swap is instant, which has no intermediate state to flash either.
     */
    if (!changed || typeof document.startViewTransition !== 'function'
        || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        swap();

        return;
    }

    const transition = document.startViewTransition(swap);

    /** Interrupting one swap with another rejects these; the swap itself still applies. */
    transition.ready.catch(() => {});
    transition.finished.catch(() => {});
};

watch(preferences, () => {
    try {
        localStorage.setItem(KEY, JSON.stringify({ ...preferences }));
    } catch {
        /** Nothing to do but keep the choice for this page load. */
    }
    applyTheme();
}, { deep: true });

applyTheme();
