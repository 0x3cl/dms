import { nextTick, watch } from 'vue';

/**
 * A refusal on a tab you are not looking at.
 *
 * A form split across tabs can be rejected for a field that is not on screen:
 * the page scrolls back to the top, the submit button says nothing useful,
 * and the one sentence explaining what went wrong is behind a tab the person
 * has no reason to click. From the reader's side the form simply refused to
 * save. This moves them to the tab holding the first error and puts the
 * cursor in the field.
 *
 * **The tab is decided by a declared map, not by the DOM.** A hidden tab's
 * fields may not be rendered at all — `v-if` removes them — so a search for
 * the first `.error` element would miss exactly the case this exists for.
 * The map is `{ tabKey: ['field', ...] }` and **its order is the tab order**,
 * so "the first error" means the first one a person reading left to right
 * would reach, not whichever key the server happened to serialise first.
 *
 * **The focus half is DOM-driven, and only after the switch.** By then the
 * right tab is rendered, so the first `.error` on the page is the first error
 * in it — which is also why this runs after `nextTick()`. It focuses the
 * control that error belongs to and scrolls it into view; a field that gets
 * focus without being scrolled to is a cursor somebody cannot see.
 *
 * A field no tab claims leaves the tab alone. That is deliberate: a form-wide
 * error, or one from a field somebody forgot to add to the map, must not move
 * the person to an arbitrary tab and imply the fault is there.
 *
 * @param {object} form an Inertia `useForm` instance, watched by its `errors`
 * @param {import('vue').Ref<string>} tab the tab ref, usually from `useUrlTab`
 * A field name also claims everything **under** it, Laravel's own dotted
 * convention: naming `allowed_types` covers `allowed_types.3.min_size_kb`,
 * which is how a repeater's rows are reported and is not something a map
 * could list in advance.
 *
 * @param {Record<string, string[]>} map tab key → the fields that tab holds,
 *   in tab order
 */
export default function useTabbedErrors(form, tab, map) {
    watch(() => form.errors, async (errors) => {
        const failed = Object.keys(errors ?? {});

        if (failed.length === 0) return;

        const owner = Object.entries(map)
            .find(([, fields]) => fields.some((field) => failed.some((key) => owns(field, key))));

        // Nothing claims it: a form-wide refusal, or a field missing from the
        // map. Moving somebody to a guess is worse than leaving them put.
        if (!owner) return;

        if (tab.value !== owner[0]) {
            tab.value = owner[0];
        }

        await nextTick();
        focusFirstError();
    }, { deep: true });
}

/** Does this mapped field claim this error key, itself or nested under it? */
function owns(field, key) {
    return key === field || key.startsWith(`${field}.`);
}

/**
 * Put the cursor on the first error showing, and bring it into view.
 *
 * `.error` is the one class every field's message uses — see
 * `resources/css/app.css` — so this needs no per-field markup and cannot
 * drift out of step with a form that grows a field.
 *
 * Private, because a single-tab form has no tab to switch and nothing here
 * calls it that way yet. Export it the day one does rather than now: an
 * exported helper nobody imports is capability pretending to exist.
 */
function focusFirstError() {
    /*
     * The first *visible* one. Tabs here are `v-show`, so a hidden tab's
     * fields are still in the document — and an error in one, from a field
     * no map claims, would otherwise capture the focus and scroll the person
     * to a control they cannot see.
     */
    const message = [...document.querySelectorAll('.error')]
        .find((candidate) => candidate.offsetParent !== null);

    if (!message) return;

    // The control the message belongs to: its own label or wrapper first, and
    // the element immediately before it as the fallback, which is the shape
    // every field in this application is written in.
    const container = message.closest('label, .field-group, div');
    const control = container?.querySelector('input, select, textarea')
        ?? message.previousElementSibling;

    const target = control instanceof HTMLElement ? control : message;

    target.scrollIntoView({ block: 'center', behavior: 'smooth' });

    // Without `preventScroll` the browser jumps to the field itself, which
    // undoes the centred scroll above and lands it under a sticky header.
    if (typeof target.focus === 'function') {
        target.focus({ preventScroll: true });
    }
}
