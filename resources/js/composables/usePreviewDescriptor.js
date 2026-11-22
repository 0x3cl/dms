import { ref } from 'vue';

/**
 * Fetching the preview descriptor, with a cache and a read-ahead.
 *
 * `/files/preview` is a server decision — which viewer the type needs, whether
 * the bytes may be handed to one, and the short-lived signed URL if they may —
 * so the page has to ask for it rather than deciding. That request was the
 * whole delay before a Word or Excel preview could even *begin*: the modal
 * opened empty, waited a round trip, and only then handed a URL to Microsoft's
 * viewer, which then started its own fetch of the file. Three serial waits, the
 * first of which was ours.
 *
 * Both file-manager pages had their own copy of that fetch, and neither did
 * anything but wait. This adds the three things that make the wait mostly
 * disappear:
 *
 * - **A cache.** Reopening a file, or arrowing back to one, is answered from
 *   memory. Held well inside the signed URL's own lifetime (see
 *   `FilePreview::VIEWER_URL_TTL_MINUTES`) so a cached descriptor can never
 *   hand the viewer a URL that has already expired — that would trade a slow
 *   preview for a broken one.
 * - **In-flight de-duplication.** A double click, or a hover followed
 *   immediately by a click, made two identical requests, each of which also
 *   minted a credential and wrote a debounce entry.
 * - **A read-ahead.** When a preview opens, its neighbours are fetched while
 *   the person is reading, so arrow navigation through a folder is instant.
 *   Only the immediate neighbours: prefetching a folder would mint forty live
 *   URLs, which is exactly what the per-file descriptor exists to avoid.
 *
 * The cache is per page-load and in memory. It holds no bytes — only the
 * descriptor, which the server already decided this person may have.
 */

/** Comfortably inside the ten minutes a signed viewer URL is valid for. */
const CACHE_TTL_MS = 4 * 60 * 1000;

export default function usePreviewDescriptor() {
    /** @type {Map<string, {descriptor: object, at: number}>} */
    const cache = new Map();
    /** @type {Map<string, Promise<object>>} */
    const inFlight = new Map();

    const descriptor = ref(null);
    const loading = ref(false);

    /*
     * The version is part of the key.
     *
     * Two versions of one file are two different sets of bytes, with their own
     * mode, their own renderer and their own signed URL — so keying on the
     * file alone would serve whichever was fetched first for both, and arrowing
     * between them would show the same document twice.
     */
    const keyFor = (file) => `${file.owner_public_id}/${file.public_id}/${file.version ?? ''}`;

    const fetchDescriptor = (file) => {
        const key = keyFor(file);
        const cached = cache.get(key);

        if (cached && Date.now() - cached.at < CACHE_TTL_MS) {
            return Promise.resolve(cached.descriptor);
        }

        if (inFlight.has(key)) {
            return inFlight.get(key);
        }

        const version = file.version ? `&version=${file.version}` : '';

        const request = fetch(`/files/preview?owner=${file.owner_public_id}&file=${file.public_id}${version}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok
                ? response.json()
                // A refusal is a descriptor too: the modal has to be able to
                // say something, and `none` is what it says.
                : { mode: 'none', name: file.name, can_download: false }))
            .catch(() => ({ mode: 'none', name: file.name, can_download: false }))
            .then((result) => {
                cache.set(key, { descriptor: result, at: Date.now() });

                return result;
            })
            .finally(() => inFlight.delete(key));

        inFlight.set(key, request);

        return request;
    };

    /**
     * Fetch without showing anything — for a hover, or for the file after the
     * one being read.
     */
    const prefetch = (file) => {
        if (!file) {
            return;
        }

        const key = keyFor(file);
        const cached = cache.get(key);

        if ((cached && Date.now() - cached.at < CACHE_TTL_MS) || inFlight.has(key)) {
            return;
        }

        fetchDescriptor(file);
    };

    /**
     * Show this file, and read ahead to its neighbours.
     *
     * A cache hit sets the descriptor synchronously, so the modal never flashes
     * its "preparing" state for a file it already has.
     *
     * Each call takes a ticket, and a response is adopted only if it is still
     * the newest one. Arrowing quickly through five files otherwise ends with
     * the first response — arriving last — overwriting the fifth.
     */
    let sequence = 0;

    const describe = async (file, neighbours = []) => {
        const ticket = ++sequence;
        const cached = cache.get(keyFor(file));
        const fresh = Boolean(cached) && Date.now() - cached.at < CACHE_TTL_MS;

        descriptor.value = fresh ? cached.descriptor : null;
        loading.value = !fresh;

        const result = await fetchDescriptor(file);

        if (ticket !== sequence) {
            return result;
        }

        descriptor.value = result;
        loading.value = false;

        // After the modal has what it needs, never before it.
        neighbours.forEach(prefetch);

        return result;
    };

    const forget = () => {
        descriptor.value = null;
        loading.value = false;
    };

    return { describe, descriptor, forget, loading, prefetch };
}
