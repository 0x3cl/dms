import { Link } from '@inertiajs/vue3';
import { defineComponent } from 'vue';

export default defineComponent({
    name: 'IndexControls',
    components: { Link },
    emits: ['update:search', 'update:entries', 'search'],
    props: {
        addHref: { type: String, default: '' },
        addLabel: { type: String, default: 'Add' },
        entries: { type: Number, required: true },
        entriesOptions: { type: Array, default: () => [10, 25, 50] },
        filterColumns: { type: Number, default: 4 },
        search: { type: String, required: true },
        searchPlaceholder: { type: String, required: true },
    },
    template: `
    <div data-admin-index-controls class="flex flex-col gap-5">
        <div class="flex flex-wrap items-center gap-3 sm:flex-nowrap">
            <label data-tour="index-search" class="relative min-w-0 flex-1 basis-full sm:basis-0">
                <span class="sr-only">Search</span>
                <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"></i>
                <input :value="search" class="h-[42px] w-full rounded-lg border border-slate-300 py-0 pl-11 pr-4 outline-none focus:border-[#00aeef] focus:ring-4 focus:ring-sky-100" :placeholder="searchPlaceholder" @input="$emit('update:search', $event.target.value); $emit('search')" />
            </label>
            <span class="hidden h-8 w-px shrink-0 bg-slate-200 sm:block" aria-hidden="true"></span>
            <label data-tour="index-entries" class="inline-flex items-center gap-3 whitespace-nowrap text-sm font-bold text-slate-700 [&_[data-searchable-select]_button]:h-[42px]">Show <select :value="entries" class="rounded-lg border border-slate-300 px-3 py-2 text-slate-700" @change="$emit('update:entries', Number($event.target.value)); $emit('search')"> <option v-for="option in entriesOptions" :key="option" :value="option">{{ option }}</option> </select> </label>
            <Link v-if="addHref" :href="addHref" data-tour="add-button" class="inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-lg bg-[#00aeef] px-4 h-[42px] text-sm font-bold text-white transition hover:bg-[#009bd8] sm:ml-3"><i class="fa-solid fa-plus" aria-hidden="true"></i>{{ addLabel }}</Link>
        </div>
        <div data-admin-index-filter-slot data-tour="index-filters" class="grid grid-cols-1 items-end gap-x-4 gap-y-4 empty:hidden sm:grid-cols-2 [&>button]:col-span-full [&>button]:justify-self-start [&_label]:flex [&_label]:flex-col [&_label]:items-stretch [&_label]:gap-1.5 [&_label]:text-[13px] [&_label]:font-bold [&_label]:uppercase [&_label]:tracking-wide [&_label]:text-slate-500 [&_label_input]:!ml-0 [&_label_input]:h-[42px] [&_label_input]:w-full [&_label_input]:text-sm [&_label_input]:font-medium [&_label_input]:normal-case [&_label_input]:tracking-normal [&_label_input]:text-slate-700 [&_[data-searchable-select]_button]:h-[42px] [&_[data-searchable-select]]:!ml-0 [&_[data-searchable-select]]:!block [&_[data-searchable-select]]:!w-full [&_[data-searchable-select]]:!max-w-none [&_[data-searchable-select]]:text-sm [&_[data-searchable-select]]:font-medium [&_[data-searchable-select]]:normal-case [&_[data-searchable-select]]:tracking-normal [&_[data-searchable-select]]:text-slate-700" :class="filterColumns === 5 ? 'lg:grid-cols-5' : 'lg:grid-cols-4'">
            <slot name="filters" />
        </div>
    </div>
`,
});
