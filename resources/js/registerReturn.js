/**
 * Where "Back to documents" goes.
 *
 * The register's tab, filters and sort all live in the query string, so a
 * filtered list is a real URL — but the Back button on a document page wrote
 * a bare `/documents`, which throws that URL away and lands the reader on the
 * default tab. Somebody working through "Shared with me", or through a filter
 * they spent a dialog setting up, had to rebuild it after every document they
 * opened.
 *
 * So the register records the query it is currently showing and the pages
 * reachable from it read it back. `sessionStorage` rather than `localStorage`,
 * because this is where you were a moment ago and not a preference: a tab
 * opened tomorrow should start on the default, and two tabs open at once
 * should not fight over one value.
 *
 * It is deliberately only a *link target*. Nothing navigates on its own, the
 * register still resolves its own tab server-side — a stale or hostile value
 * degrades to "Uploaded by me" there rather than refusing — and a missing or
 * unreadable store is answered by the plain `/documents` it replaced.
 */
const KEY = 'dms.documents.register';

/** Kept out because they are not where you were: a page number is a position
 *  in a list that may have changed, and a half-typed search is not a place. */
const TRANSIENT = ['page'];

export const rememberRegister = (filters) => {
    const query = Object.entries(filters ?? {})
        .filter(([key, value]) => !TRANSIENT.includes(key)
            && value !== null && value !== undefined && value !== '')
        .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value)}`)
        .join('&');

    try {
        sessionStorage.setItem(KEY, query);
    } catch {
        /** Private browsing can refuse to write; the default tab is a fine answer. */
    }
};

export const registerHref = () => {
    let query = '';

    try {
        query = sessionStorage.getItem(KEY) || '';
    } catch {
        query = '';
    }

    return query ? `/documents?${query}` : '/documents';
};
