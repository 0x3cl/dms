import { ref, watch } from 'vue';

/**
 * A tab whose value lives in the query string, so a link or a reload lands on
 * it. Written with history.replaceState rather than an Inertia visit —
 * switching a tab never needs new data, and a round trip per click would also
 * re-record anything the page logs on load.
 *
 * **A page's tab set is `?tab=`.** That is the name to reach for, and every
 * tab set in this application uses it today. The parameter is an argument
 * rather than a constant for one reason: a page carrying **two** tab sets at
 * once cannot have them both write `?tab=`, or each click would overwrite the
 * other's position. In that case `?tab=` stays with the set that owns the page
 * and the second takes a name of its own.
 *
 * A filter *within* a tab — a chip row, a category selector — is not a second
 * tab set and does not belong in the URL at all.
 *
 * The default value is kept out of the URL, so an untouched page has a clean
 * address and only a deliberate choice shows up in it.
 *
 * `always` opts out of that. A page whose tabs are the page — an account being
 * edited across Personal Information and Security — reads better with the tab
 * named in the address at all times: the URL in the bar is then the one to
 * paste to a colleague, with no "click the second tab first" attached. It is a
 * flag rather than the new rule because the clean default is still right for a
 * page that merely happens to have tabs, and changing it globally would
 * rewrite the address of every page that has them today.
 *
 * @param {string} param the query-string key this tab set owns
 * @param {string[]} allowed every value it may take; anything else is ignored
 * @param {string} [fallback] the value used when the URL says nothing usable
 * @param {{always?: boolean}} [options] `always` keeps the parameter in the URL
 *   even on the default tab, and writes it on first load
 */
export default function useUrlTab(param, allowed, fallback = allowed[0], { always = false } = {}) {
    const fromUrl = () => new URLSearchParams(window.location.search).get(param);
    const tab = ref(allowed.includes(fromUrl()) ? fromUrl() : fallback);

    const write = (value) => {
        const url = new URL(window.location.href);

        if (!always && (value === fallback || !allowed.includes(value))) {
            url.searchParams.delete(param);
        } else {
            url.searchParams.set(param, allowed.includes(value) ? value : fallback);
        }

        window.history.replaceState(window.history.state, '', url);
    };

    watch(tab, write);

    // The default is only in the URL if it is put there, and a page arriving
    // without the parameter has not been through the watcher.
    if (always) {
        write(tab.value);
    }

    return tab;
}
