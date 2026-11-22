import { computed, defineComponent, reactive, watch } from 'vue';
import ConfirmActionModal from './ConfirmActionModal';

/**
 * Narrowing the document register.
 *
 * The same dialog the file manager and both audit trails use, for the reason
 * given there: a filter that reloads the page on every change is one reload
 * per decision, and a row of selects above a table pushes the table itself
 * below the fold. The draft lives here and only Apply navigates, so closing
 * with the X discards it.
 *
 * It holds exactly what `DocumentController::index()` reads, which today is
 * the document type. That is deliberately not padded out with controls the
 * server would ignore — a filter that changes nothing is worse than a filter
 * that is missing, because the reader believes they have narrowed the list.
 * The grid is already two columns wide so a second filter drops in beside
 * this one without a layout change.
 *
 * The type is matched by **id**, not by name: a type is a row, so a filter
 * saved in somebody's bookmark survives that type being renamed.
 */
export default defineComponent({
    name: 'DocumentFilterModal',
    components: { ConfirmActionModal },
    emits: ['close', 'apply'],
    props: {
        open: { type: Boolean, default: false },
        /** The filters currently in the query string. */
        filters: { type: Object, required: true },
        /** The document types the register can be narrowed to. */
        types: { type: Array, default: () => [] },
        /** The type groups — the office's own categories. */
        groups: { type: Array, default: () => [] },
    },
    setup(props, { emit }) {
        const blank = {
            group: '',
            type: '',
            visibility: '',
            from: '',
            to: '',
            sort: 'created',
            direction: 'desc',
        };

        const draft = reactive({ ...blank });

        // Re-seeded every time it opens, so a cancelled edit cannot leak into
        // the next one.
        watch(() => props.open, (open) => {
            if (! open) return;

            Object.keys(blank).forEach((key) => {
                draft[key] = props.filters[key] ?? blank[key];
            });
        }, { immediate: true });

        /**
         * How many filters are actually narrowing the list.
         *
         * Counted from the *applied* filters rather than the draft, because
         * it is drawn on the button that opens this dialog — it has to say
         * what the table is showing, not what somebody is in the middle of
         * typing.
         */
        const active = computed(() => Object.keys(blank)
            .filter((key) => String(props.filters[key] ?? blank[key]) !== String(blank[key])).length);

        /*
         * Only the types in the chosen category are offered, and choosing a
         * category clears a type that is not in it. Leaving a stale type
         * selected would hand the server two filters that cannot both be
         * true and return nothing, which reads as "there are no documents"
         * rather than "those two choices disagree".
         */
        const typeOptions = computed(() => (draft.group
            ? props.types.filter((type) => String(type.group_id ?? '') === String(draft.group))
            : props.types));

        watch(() => draft.group, () => {
            if (draft.type && ! typeOptions.value.some((type) => String(type.id) === String(draft.type))) {
                draft.type = '';
            }
        });

        const visibilities = [
            { value: 'public', label: 'Public' },
            { value: 'private', label: 'Private' },
            { value: 'confidential', label: 'Confidential' },
        ];

        const sorts = [
            { value: 'created', label: 'Date filed' },
            { value: 'updated', label: 'Last updated' },
            { value: 'title', label: 'Title' },
            { value: 'code', label: 'Tracking code' },
        ];

        const apply = () => emit('apply', { ...draft });
        const reset = () => {
            Object.assign(draft, blank);
            emit('apply', { ...blank });
        };

        return { active, apply, draft, reset, sorts, typeOptions, visibilities };
    },
    template: `
    <ConfirmActionModal
        :open="open"
        title="Filter documents"
        message="Narrow the register, then apply. What you choose stays in the address bar, so the view can be bookmarked or sent to somebody."
        confirm-label="Apply filters"
        icon="fa-solid fa-filter"
        wider
        @close="$emit('close')"
        @confirm="apply">
        <div class="space-y-4 text-left">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Category</span>
                    <select v-model="draft.group" class="field">
                        <option value="">All categories</option>
                        <option v-for="group in groups" :key="group.id" :value="group.id">{{ group.name }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Document type</span>
                    <select v-model="draft.type" class="field">
                        <option value="">All types</option>
                        <option v-for="type in typeOptions" :key="type.id" :value="type.id">{{ type.name }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Visibility</span>
                    <select v-model="draft.visibility" class="field">
                        <option value="">Any visibility</option>
                        <option v-for="option in visibilities" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Filed from</span>
                    <input v-model="draft.from" type="date" class="field" />
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Filed until</span>
                    <input v-model="draft.to" type="date" class="field" />
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Sort by</span>
                    <select v-model="draft.sort" class="field">
                        <option v-for="option in sorts" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Order</span>
                    <select v-model="draft.direction" class="field">
                        <option value="desc">Newest or Z to A first</option>
                        <option value="asc">Oldest or A to Z first</option>
                    </select>
                </label>
            </div>

            <div v-if="active" class="flex justify-start">
                <button type="button" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold uppercase tracking-wide text-slate-700 transition hover:bg-slate-50" @click="reset">
                    <i class="fa-solid fa-rotate-left mr-1.5" aria-hidden="true"></i>Clear filters
                </button>
            </div>
        </div>
    </ConfirmActionModal>
`,
});
