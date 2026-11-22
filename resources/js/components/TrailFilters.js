import { computed, defineComponent, reactive, watch } from 'vue';
import ConfirmActionModal from './ConfirmActionModal';

/**
 * Narrowing a document's tracking history.
 *
 * One component for both places the trail is rendered — the panel on the
 * document page and the full-history page — for the same reason
 * `DocumentLog::scopeTrail()` is one builder and `DocumentLogController::filters()`
 * is one parser: they are the same list at two lengths, and a filter added to
 * one screen and not the other is a filter somebody will look for and not find.
 *
 * **A dialog rather than a row of selects**, matching `FileFilterModal`. Five
 * controls that each reload the page is five reloads to describe one question,
 * and five controls sitting permanently above a short panel is a filter bar
 * taller than the thing it filters. The modal holds a *draft*: closing it
 * discards, only Apply navigates.
 *
 * **The version is not here.** It is the tab bar above the list — see
 * `TrailVersionTabs` — because which version of the file a trail is being read
 * against is the first question somebody asks of a replaced document, not the
 * fifth. One filter with two controls is how a screen contradicts itself, so
 * the select went out when the tabs arrived; `Clear all filters` still clears
 * it, because "all" has to mean all.
 *
 * Search stays outside, on the page. It is the control people actually use,
 * it is debounced rather than applied, and burying it behind a button would
 * make the common case the slow one.
 *
 * Every control here reads a **snapshot column** on `document_logs`. Nothing
 * joins — which is what the snapshots are for, and what makes the office
 * filter truthful: it narrows by where the actor sat *when it happened*, not
 * by where they sit now.
 */
export default defineComponent({
    name: 'TrailFilters',
    components: { ConfirmActionModal },
    emits: ['close', 'apply'],
    props: {
        open: { type: Boolean, default: false },
        /** The filters currently in the query string. */
        filters: { type: Object, required: true },
        /** Every action a trail can contain — not only the postable ones. */
        actions: { type: Array, default: () => [] },
        /** Only the offices this trail actually contains. */
        offices: { type: Array, default: () => [] },
        /** Page sizes the server will honour; anything else falls back. */
        sizes: { type: Array, default: () => [12, 25, 50] },
    },
    setup(props, { emit }) {
        /*
         * The version is **not** here, and that is the half of the version
         * filter this dialog no longer owns: it is the tab bar on the page,
         * which the reader can see. Two controls over one filter is how a
         * screen ends up contradicting itself — and a draft re-seeded on open
         * would have reverted a tab clicked since.
         *
         * `Clear all filters` still clears it, because "all" has to mean all;
         * see `reset()`.
         */
        const blank = { action: '', office: '', from: '', to: '', entries: 12 };

        const draft = reactive({ ...blank });

        // Re-seeded every time it opens, so a cancelled edit cannot leak into
        // the next one.
        watch(() => props.open, (open) => {
            if (!open) return;

            Object.keys(blank).forEach((key) => {
                draft[key] = props.filters[key] ?? blank[key];
            });
        }, { immediate: true });

        const apply = () => emit('apply', { ...draft });
        const reset = () => {
            Object.assign(draft, blank);
            // The version goes with it: the tab bar is a filter like any
            // other, so a control labelled "clear all" that left the trail
            // on one version would be making a false statement.
            emit('apply', { ...blank, version: '' });
        };

        return { apply, draft, reset };
    },
    template: `
    <ConfirmActionModal
        :open="open"
        title="Filter the trail"
        message="Narrow this history, then apply. What you choose stays in the address bar, so a narrowed trail can be bookmarked or sent to somebody."
        confirm-label="Apply filters"
        icon="fa-solid fa-filter"
        wider
        @close="$emit('close')"
        @confirm="apply">
        <div class="space-y-4 text-left">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Action</span>
                    <select v-model="draft.action" class="field">
                        <option value="">All actions</option>
                        <option v-for="option in actions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </label>
                <!-- Offices come from the rows themselves, so this can never
                     offer a choice that matches nothing — and a division
                     renamed since cannot hide the rows recorded under its old
                     name. -->
                <label v-if="offices.length" class="block">
                    <span class="text-sm font-semibold text-slate-700">Office at the time</span>
                    <select v-model="draft.office" class="field">
                        <option value="">Any office</option>
                        <option v-for="office in offices" :key="office" :value="office">{{ office }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">From</span>
                    <input v-model="draft.from" type="date" class="field" />
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Until</span>
                    <input v-model="draft.to" type="date" class="field" />
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Entries per page</span>
                    <select v-model.number="draft.entries" class="field">
                        <option v-for="size in sizes" :key="size" :value="size">{{ size }} entries</option>
                    </select>
                </label>
            </div>

            <div class="flex justify-end border-t border-slate-200 pt-4">
                <button type="button" class="text-sm font-bold text-slate-600 transition hover:text-[#07559e]" @click="reset">
                    <i class="fa-solid fa-rotate-left mr-1.5" aria-hidden="true"></i>Clear all filters
                </button>
            </div>
        </div>
    </ConfirmActionModal>
`,
});
