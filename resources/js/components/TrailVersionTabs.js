import { computed, defineComponent } from 'vue';

/**
 * A document's trail, read one file version at a time.
 *
 * One component for both places the trail is rendered — the panel on the
 * document page and the full-history page — for the same reason `TrailFilters`
 * is one dialog and `DocumentLog::scopeTrail()` one builder: they are the same
 * list at two lengths, and a control on one screen and not the other is a
 * control somebody will look for and not find.
 *
 * **It is the version filter, not a second one.** Selecting a tab sets exactly
 * the `version` the dialog used to set, so the server answers through the one
 * clause it always did and a narrowed trail stays a linkable thing. The select
 * went out of the dialog when these arrived: two controls over one filter is
 * how a screen ends up contradicting itself.
 *
 * **It renders nothing until a file has been replaced.** One version is not a
 * choice, so a document whose file has never changed — which is most of them —
 * sees the trail it always saw, with no chrome above it.
 *
 * The tabs come from the rows, counted on the server under every filter in
 * force except the version itself, so a count states what choosing that tab
 * would show. `All versions` is the sum rather than a separate query: it is
 * the same rows, and asking twice is how two numbers over one fact disagree.
 */
export default defineComponent({
    name: 'TrailVersionTabs',
    emits: ['select'],
    props: {
        /**
         * `[{version, entries}]`, newest first, the unversioned rows last.
         *
         * A version with no entries is absent by construction: a file replaced
         * three times may have nothing recorded against version 2, and
         * offering it would be offering an empty answer.
         */
        tabs: { type: Array, default: () => [] },
        /** The version in force: a number, `'none'`, or `''` for all of them. */
        current: { type: [Number, String], default: '' },
    },
    setup(props, { emit }) {
        const total = computed(() => props.tabs.reduce((sum, tab) => sum + tab.entries, 0));

        // Two tabs is the first point at which there is something to choose
        // between; below that the bar would only restate the whole trail.
        const shown = computed(() => (props.tabs.length > 1 ? props.tabs : []));

        const label = (version) => (version === null ? 'No version' : 'Version ' + version);

        // `''` is every version. The key is what goes in the query string, so
        // the unversioned bucket travels as `none` — a number cannot name it,
        // and null would read as "all" to the filter.
        const key = (version) => (version === null ? 'none' : version);

        const isCurrent = (version) => String(key(version)) === String(props.current);

        return { isCurrent, key, label, shown, total, select: (value) => emit('select', value) };
    },
    template: `
    <div v-if="shown.length" class="mt-5">
        <div class="flex gap-1 overflow-x-auto rounded-lg bg-slate-100 p-1" role="tablist" aria-label="File version">
            <button
                type="button"
                role="tab"
                :aria-selected="current === '' ? 'true' : 'false'"
                class="shrink-0 rounded-md px-4 py-2 text-xs font-bold uppercase tracking-wide transition"
                :class="current === '' ? 'bg-white text-[#07559e] shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                @click="select('')">
                All versions ({{ total }})
            </button>
            <button
                v-for="tab in shown"
                :key="key(tab.version)"
                type="button"
                role="tab"
                :aria-selected="isCurrent(tab.version) ? 'true' : 'false'"
                class="shrink-0 rounded-md px-4 py-2 text-xs font-bold uppercase tracking-wide transition"
                :class="isCurrent(tab.version) ? 'bg-white text-[#07559e] shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                @click="select(key(tab.version))">
                {{ label(tab.version) }} ({{ tab.entries }})
            </button>
        </div>
    </div>
`,
});
