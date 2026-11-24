/**
 * The CSRF token for a raw `fetch()`.
 *
 * Inertia's own visits go through axios, which reads the `XSRF-TOKEN` cookie
 * and sets `X-XSRF-TOKEN` itself. A handful of places cannot use a visit — a
 * JSON endpoint whose answer must not become a page, a file posted as
 * `FormData` — and those use `fetch`, which sends nothing of the sort.
 *
 * **Prefer the cookie, because the meta tag goes stale.**
 * `<meta name="csrf-token">` is rendered by Blade once, on the first full page
 * load, and an Inertia visit never re-renders the layout — so the tag holds
 * whatever the token was when the tab was opened. Laravel rotates the token
 * whenever the session is regenerated, which a sign-in does, so a tab left
 * open across a sign-in elsewhere sends a token that was correct hours ago.
 * That is the `CSRF token mismatch` somebody meets creating a folder in a tab
 * they have had open all afternoon. The cookie is reissued on every response.
 *
 * **The two headers are not interchangeable, and sending both is wrong.**
 * `VerifyCsrfToken::getTokenFromRequest()` takes `_token`, then
 * `X-CSRF-TOKEN`, and only consults `X-XSRF-TOKEN` when neither produced
 * anything — decrypting it, because the cookie is encrypted. So the raw token
 * belongs in `X-CSRF-TOKEN` and the cookie's value belongs in
 * `X-XSRF-TOKEN`; putting the cookie in both means the first header is read,
 * fails to match, and the second is never reached.
 */

/** The encrypted `XSRF-TOKEN` cookie, if this response has set one. */
function cookieToken() {
    const entry = document.cookie
        .split('; ')
        .find((candidate) => candidate.startsWith('XSRF-TOKEN='));

    if (! entry) return null;

    try {
        return decodeURIComponent(entry.slice('XSRF-TOKEN='.length));
    } catch {
        // A malformed cookie falls through to the tag rather than throwing in
        // the middle of somebody's upload.
        return null;
    }
}

/**
 * The raw token, from the markup.
 *
 * For a real form post, which carries `_token` as a field — that value is
 * compared directly against the session, so it must be the unencrypted one.
 * It is the stale one in a long-lived tab, which is unavoidable: there is no
 * unencrypted token anywhere else in the page.
 */
export function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * Headers for a `fetch` that writes.
 *
 * One header, chosen by which token we actually have — see above for why
 * sending both would break every request rather than cover both cases.
 */
export function csrfHeaders() {
    const cookie = cookieToken();

    return cookie ? { 'X-XSRF-TOKEN': cookie } : { 'X-CSRF-TOKEN': csrfToken() };
}
