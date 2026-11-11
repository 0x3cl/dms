import { computed, defineComponent, reactive, watch } from 'vue';
import ConfirmActionModal from './ConfirmActionModal';

/**
 * Finding one file among a few thousand.
 *
 * Everything here writes to the query string when it is applied, like every
 * other control in this file manager — so a filtered view is linkable, survives
 * a view switch and reproduces for whoever is sent it. The modal holds a draft
 * of those values and nothing else: closing it with the X discards the draft,
 * and only Apply navigates.
 *
 * The draft is why this is a dialog rather than a row of selects. Six filters
 * that each reload the page is six reloads to describe one search.
 */
export default defineComponent({
    name: 'FileFilterModal',
    components: { ConfirmActionModal },
    emits: ['close', 'apply'],
    props: {
        open: { type: Boolean, default: false },
        /** The filters currently in the query string. */
        filters: { type: Object, required: true },
        /** FileCategory::options() from the server. */
        categories: { type: Array, default: () => [] },
        /** The people whose items are reachable from this view. */
        owners: { type: Array, default: () => [] },
    },
    setup(props, { emit }) {
        const blank = {
            access: 'all',
            kind: 'all',
            type: '',
            owned_by: '',
            from: '',
            to: '',
            sort: 'name',
            direction: 'asc',
        };

        const draft = reactive({ ...blank });

        // Re-seeded every time it opens, so a cancelled edit cannot leak into
        // the next one.
        watch(() => props.open, (open) => {
            if (!open) return;

            Object.keys(blank).forEach((key) => {
                draft[key] = props.filters[key] ?? blank[key];
            });
        }, { immediate: true });

        const sorts = [
            { value: 'name', label: 'Name' },
            { value: 'created', label: 'Date uploaded' },
            { value: 'updated', label: 'Last modified' },
            { value: 'size', label: 'Size' },
        ];

        const accessOptions = [
            { value: 'all', label: 'Everything I can see' },
            { value: 'mine', label: 'Owned by me' },
            { value: 'shared', label: 'Shared with me' },
        ];

        const kindOptions = [
            { value: 'all', label: 'Files and folders' },
            { value: 'file', label: 'Files only' },
            { value: 'folder', label: 'Folders only' },
        ];

        const active = computed(() => Object.keys(blank)
            .filter((key) => (props.filters[key] ?? blank[key]) !== blank[key]).length);

        const apply = () => emit('apply', { ...draft });
        const reset = () => {
            Object.assign(draft, blank);
            emit('apply', { ...blank });
        };

        return { accessOptions, active, apply, draft, kindOptions, reset, sorts };
    },
    template: `
    <ConfirmActionModal
        :open="open"
        title="Filter and sort"
        message="Narrow this folder down, then apply. The filters you choose stay in the address bar, so the view can be bookmarked or sent to somebody."
        confirm-label="Apply filters"
        icon="fa-solid fa-filter"
        wider
        @close="$emit('close')"
        @confirm="apply">
        <div class="space-y-4 text-left">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Sharing</span>
                    <select v-model="draft.access" class="field">
                        <option v-for="option in accessOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Show</span>
                    <select v-model="draft.kind" class="field">
                        <option v-for="option in kindOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">File type</span>
                    <select v-model="draft.type" class="field">
                        <option value="">Any type</option>
                        <option v-for="category in categories" :key="category.value" :value="category.value">{{ category.label }}</option>
                    </select>
                    <!-- A folder has no type, so choosing one is a statement
                         that you are looking for files. -->
                    <span v-if="draft.type" class="mt-1 block text-xs text-slate-500">Folders are hidden while a file type is chosen.</span>
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Owner</span>
                    <select v-model="draft.owned_by" class="field">
                        <option value="">Anyone</option>
                        <option v-for="owner in owners" :key="owner.public_id" :value="owner.public_id">{{ owner.name }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Uploaded from</span>
                    <input v-model="draft.from" type="date" class="field" />
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Uploaded until</span>
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
                        <option value="asc">Ascending</option>
                        <option value="desc">Descending</option>
                    </select>
                </label>
            </div>

            <button type="button" class="text-sm font-bold text-[#07559e] hover:text-[#043d78]" @click="reset">
                <i class="fa-solid fa-rotate-left mr-1.5" aria-hidden="true"></i>Clear all filters
            </button>
        </div>
    </ConfirmActionModal>
`,
});
