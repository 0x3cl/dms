import { computed, defineComponent, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * One choice out of a long list: a select that opens a searchable panel
 * rather than a wall of cards.
 *
 * A list only becomes hard to use once it is too long to read at a glance —
 * which is exactly when a grid of equally-weighted tiles stops helping. The
 * trigger shows what is chosen, the panel shows what is choosable, and typing
 * narrows it. Options may carry an `icon`, which is shown in both places.
 *
 * **Options may be grouped.** Pass `[{ label, options: [...] }]` and the panel
 * renders a heading before each run. That is the first question a long list
 * asks — what kind of thing is this? — so the second one is asked of six
 * options rather than twenty. Grouping is a property of the *list*, not of
 * this component: the server sends the arrangement (see
 * `DocumentType::grouped()`), because two screens offering the same list must
 * not group it two ways.
 *
 * Flat arrays keep working untouched, and search still runs across every
 * option — a heading narrows the eye, it must never hide a match from the
 * keyboard. A group's own name matches too, so typing "video" finds the group.
 *
 * Keyboard: type to filter, Up/Down to move, Enter to take the highlighted
 * option, Escape to close without changing anything.
 */
export default defineComponent({
    name: 'SearchableSelect',
    props: {
        modelValue: { type: [String, Number], default: '' },
        // [{ value, label, icon? }]
        options: { type: Array, default: () => [] },
        placeholder: { type: String, default: 'Select an option' },
        searchPlaceholder: { type: String, default: 'Search…' },
        emptyText: { type: String, default: 'Nothing matches that search.' },
        disabled: { type: Boolean, default: false },
        invalid: { type: Boolean, default: false },
    },
    emits: ['update:modelValue'],
    setup(props, { emit }) {
        const open = ref(false);
        const search = ref('');
        const highlighted = ref(0);
        const root = ref(null);
        const searchInput = ref(null);

        /*
         * One flat list underneath, whatever shape came in.
         *
         * Everything else here — matching, the keyboard cursor, the selected
         * label — works on options, not on headings, so a grouped list is
         * flattened once and each option remembers which heading it came
         * from. The template puts the headings back by watching where `group`
         * changes, which also means a search that matches two options from
         * different groups still renders both headings correctly.
         */
        const flat = computed(() => props.options.flatMap((entry) => (Array.isArray(entry.options)
            ? entry.options.map((option) => ({ ...option, group: entry.label }))
            : [entry])));

        const selected = computed(() => flat.value.find((option) => option.value === props.modelValue) ?? null);
        const matches = computed(() => {
            const needle = search.value.trim().toLowerCase();

            return needle === ''
                ? flat.value
                : flat.value.filter((option) => `${option.label} ${option.group ?? ''}`.toLowerCase().includes(needle));
        });

        const close = () => { open.value = false; search.value = ''; };
        const toggle = () => {
            if (props.disabled) {
                return;
            }

            open.value = !open.value;

            if (open.value) {
                search.value = '';
                highlighted.value = Math.max(0, matches.value.findIndex((option) => option.value === props.modelValue));
                nextTick(() => searchInput.value?.focus());
            }
        };
        const choose = (option) => {
            if (!option) {
                return;
            }

            emit('update:modelValue', option.value);
            close();
        };
        const move = (step) => {
            if (matches.value.length === 0) {
                return;
            }

            highlighted.value = (highlighted.value + step + matches.value.length) % matches.value.length;
        };

        // Reset the cursor whenever the list under it changes.
        watch(matches, () => { highlighted.value = 0; });

        const onDocumentClick = (event) => {
            if (open.value && root.value && !root.value.contains(event.target)) {
                close();
            }
        };

        onMounted(() => document.addEventListener('mousedown', onDocumentClick));
        onBeforeUnmount(() => document.removeEventListener('mousedown', onDocumentClick));

        return { choose, close, highlighted, matches, move, open, root, search, searchInput, selected, toggle };
    },
    template: `
    <div ref="root" class="relative">
        <button
            type="button"
            :disabled="disabled"
            class="flex min-h-[50px] w-full items-center gap-3 rounded-lg border bg-white px-4 py-3 text-left transition disabled:cursor-not-allowed disabled:bg-slate-50"
            :class="[invalid ? 'border-rose-500' : 'border-slate-300', open ? 'border-[#00aeef] ring-4 ring-sky-100' : 'hover:border-sky-300']"
            role="combobox"
            :aria-expanded="open"
            aria-haspopup="listbox"
            @click="toggle">
            <span v-if="selected?.icon" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-sky-100 text-[#07559e]">
                <i :class="selected.icon" aria-hidden="true"></i>
            </span>
            <span class="min-w-0 flex-1 truncate text-sm" :class="selected ? 'font-semibold text-slate-800' : 'text-slate-400'">{{ selected ? selected.label : placeholder }}</span>
            <i class="fa-solid fa-chevron-down shrink-0 text-xs text-slate-400 transition" :class="{ 'rotate-180': open }" aria-hidden="true"></i>
        </button>

        <div v-if="open" class="absolute z-30 mt-2 w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
            <div class="border-b border-slate-100 p-2">
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400" aria-hidden="true"></i>
                    <input
                        ref="searchInput"
                        v-model="search"
                        type="text"
                        :placeholder="searchPlaceholder"
                        class="w-full rounded-lg border border-slate-200 py-2 pl-9 pr-3 text-sm outline-none focus:border-[#00aeef] focus:ring-4 focus:ring-sky-100"
                        @keydown.down.prevent="move(1)"
                        @keydown.up.prevent="move(-1)"
                        @keydown.enter.prevent="choose(matches[highlighted])"
                        @keydown.esc.prevent="close" />
                </div>
            </div>
            <ul class="max-h-72 overflow-y-auto py-1" role="listbox">
                <template v-for="(option, index) in matches" :key="option.value">
                    <!-- A heading wherever the group changes, which is every
                         boundary in an unfiltered list and only the real ones
                         in a filtered one. -->
                    <li v-if="option.group && option.group !== matches[index - 1]?.group" class="px-3 pb-1 pt-3 text-[11px] font-bold uppercase tracking-[.14em] text-slate-400">
                        {{ option.group }}
                    </li>
                    <li>
                    <button
                        type="button"
                        role="option"
                        :aria-selected="option.value === modelValue"
                        class="flex w-full items-center gap-3 px-3 py-2.5 text-left transition"
                        :class="[index === highlighted ? 'bg-sky-50' : 'hover:bg-slate-50', option.value === modelValue ? 'text-[#07559e]' : 'text-slate-700']"
                        @mouseenter="highlighted = index"
                        @click="choose(option)">
                        <span v-if="option.icon" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm" :class="option.value === modelValue ? 'bg-[#07559e] text-white' : 'bg-sky-100 text-[#07559e]'">
                            <i :class="option.icon" aria-hidden="true"></i>
                        </span>
                        <span class="min-w-0 flex-1 text-sm font-semibold leading-5">{{ option.label }}</span>
                        <i v-if="option.value === modelValue" class="fa-solid fa-circle-check shrink-0 text-[#00aeef]" aria-hidden="true"></i>
                    </button>
                    </li>
                </template>
                <li v-if="matches.length === 0" class="px-4 py-6 text-center text-sm text-slate-500">{{ emptyText }}</li>
            </ul>
        </div>
    </div>
`,
});
