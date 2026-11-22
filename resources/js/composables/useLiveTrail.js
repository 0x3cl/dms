import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';
import { listen } from '../echo';

/**
 * Keep an audit trail current without reloading the page.
 *
 * The server broadcasts a **signal**, not the row (see
 * `App\Events\DocumentTrailUpdated`), so this decides what to do with it — and
 * what it does depends on where the reader is standing:
 *
 * - **On page one, unfiltered**, it reloads just that prop. The server
 *   re-paginates, so `links`, `total` and `from`/`to` all stay internally
 *   consistent. There is no client-side splice and therefore no drift.
 * - **Anywhere else**, it counts and shows a pill: *"3 new entries —
 *   refresh"*. A viewer on page two must not have page-one rows injected
 *   under them, and a filtered viewer must not be told a new row matched
 *   their filter when nobody has checked.
 *
 * Two guards keep it from becoming a refetch storm. Events inside a short
 * window are coalesced into one reload, and nothing happens at all while the
 * tab is hidden — ten people with a trail open should not mean ten reloads per
 * event.
 */

/** How long events are gathered before one reload goes out. */
const COALESCE_MS = 1500;

export default function useLiveTrail(channel, { prop, isLive }) {
    const pending = ref(0);
    let timer = null;

    const reload = () => {
        pending.value = 0;
        router.reload({
            only: Array.isArray(prop) ? prop : [prop],
            preserveScroll: true,
            preserveState: true,
        });
    };

    const stop = listen(channel, 'trail.updated', () => {
        // A trail nobody is looking at does not need fetching.
        if (typeof document !== 'undefined' && document.visibilityState !== 'visible') {
            pending.value += 1;

            return;
        }

        if (!isLive()) {
            pending.value += 1;

            return;
        }

        clearTimeout(timer);
        timer = setTimeout(reload, COALESCE_MS);
    });

    onBeforeUnmount(() => {
        clearTimeout(timer);
        // Inertia keeps the app alive across visits, so a missed unsubscribe
        // accumulates one subscription per navigation.
        stop();
    });

    return { pending, refresh: reload };
}
