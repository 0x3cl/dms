/**
 * What a file-manager row shows and what it offers.
 *
 * FileGrid and FileTable render the same `files` paginator page and the same
 * `folders` collection, and they used to carry their own copies of both of
 * these — byte formatting and a thirteen-line action list, duplicated verbatim.
 * That is exactly the pair of functions that drifts: a new action added to one
 * view and not the other is invisible until somebody switches views looking for
 * it.
 *
 * Both are pure functions of the server's own row payload. Per-row abilities
 * come from the `can` object the controller resolved, never from
 * `auth.permissions` in a template: a module permission says the capability
 * exists for this user, and only AccessResolver knows whether it applies to
 * this item.
 */

/** Bytes, in the shortest unit that keeps one decimal meaningful. */
export function readableSize(bytes) {
    if (!bytes) return '—';

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

/**
 * The badge that says why a row is on the page.
 *
 * `access` is resolved server-side from the grant's own source, so "shared with
 * you" on a card is the same answer the resolver gave — not an inference from
 * ownership, which would call an administrator's view a gift.
 *
 * Owned rows get no badge in the default case: almost everything in My Files is
 * yours, and a badge on every card is a badge that says nothing.
 */
export function accessBadge(item) {
    const tones = {
        owner: { tone: 'sky', icon: 'fa-solid fa-user' },
        shared: { tone: 'emerald', icon: 'fa-solid fa-share-nodes' },
        public: { tone: 'amber', icon: 'fa-solid fa-globe' },
        administrative: { tone: 'slate', icon: 'fa-solid fa-user-shield' },
    };

    const style = tones[item.access];

    if (!style) return null;

    return {
        ...style,
        label: item.access_label,
        // "Shared with you" is only half the sentence — by whom is the half
        // that decides whether you trust it.
        hint: item.shared_by ? `Shared by ${item.shared_by}` : null,
        highlight: item.access === 'shared',
    };
}

/**
 * The kebab menu for a row.
 *
 * `mode` is 'browse' or 'trash'. The trash offers two things and nothing else:
 * a trashed item cannot be renamed, moved, shared or previewed, and offering
 * any of those would mean either restoring it silently or failing.
 */
export function rowActions(item, kind, mode = 'browse') {
    const can = item.can ?? {};
    const items = [];

    if (mode === 'trash') {
        if (can.restore) {
            items.push({
                key: 'restore',
                label: 'Restore',
                icon: 'fa-solid fa-rotate-left',
                hint: item.restores_home
                    ? 'Its original folder is gone — restores to your storage root'
                    : `Back to ${item.restores_to}`,
            });
        }

        if (can.purge) {
            items.push({
                key: 'purge',
                label: 'Delete permanently',
                icon: 'fa-solid fa-fire',
                tone: 'danger',
            });
        }

        return items;
    }

    if (kind === 'file' && can.view) items.push({ key: 'preview', label: 'Preview', icon: 'fa-solid fa-eye' });
    if (kind === 'file' && can.download) items.push({ key: 'download', label: 'Download', icon: 'fa-solid fa-download' });

    // "Copy", not "Copy to my storage": a copy now asks where it should land,
    // the same way a move does, so it is no longer only a way of taking
    // something out of somebody else's tree.
    if (can.copy) items.push({ key: 'copy', label: 'Copy', icon: 'fa-solid fa-copy', hint: 'Choose a destination folder' });

    // Extracting writes new files, so it is offered on an archive the viewer
    // can both read and write into.
    if (kind === 'file' && can.download && can.rename && /zip/.test(item.mime_type ?? '')) {
        items.push({ key: 'extract', label: 'Extract here', icon: 'fa-solid fa-box-open' });
    }

    if (can.rename) items.push({ key: 'rename', label: 'Rename', icon: 'fa-solid fa-pen' });
    if (can.move) items.push({ key: 'move', label: 'Move', icon: 'fa-solid fa-arrow-right-arrow-left', hint: 'Choose a destination folder' });
    if (can.share) items.push({ key: 'share', label: 'Share a link', icon: 'fa-solid fa-link' });
    if (can.share) items.push({ key: 'privacy', label: 'Change privacy', icon: 'fa-solid fa-lock' });
    if (can.delete) items.push({ key: 'delete', label: 'Delete', icon: 'fa-solid fa-trash', tone: 'danger' });

    return items;
}
