import { defineComponent } from 'vue';

/**
 * Grid or table.
 *
 * `modelValue` is driven from the query string, not from local state, which is
 * what makes the current page, search, sort and filters survive a switch by
 * construction — and what makes a shared link reproduce what the sender saw.
 *
 * Pinned to h-[42px] to line up with IndexControls' search box and page-size
 * select; the design system fixes that row's height.
 */
export default defineComponent({
    name: 'ViewToggle',
    emits: ['update:modelValue'],
    props: {
        modelValue: { type: String, default: 'grid' },
    },
    setup() {
        const options = [
            { value: 'grid', icon: 'fa-solid fa-table-cells-large', label: 'Thumbnail view' },
            { value: 'table', icon: 'fa-solid fa-list', label: 'Table view' },
        ];

        return { options };
    },
    template: `
    <div data-tour="view-toggle" class="inline-flex h-[42px] shrink-0 overflow-hidden rounded-lg border border-slate-300" role="group" aria-label="Change view">
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            class="inline-flex h-full w-11 items-center justify-center text-sm transition"
            :class="modelValue === option.value ? 'bg-sky-100 text-[#07559e]' : 'text-slate-500 hover:bg-slate-50'"
            :aria-pressed="modelValue === option.value"
            :title="option.label"
            @click="$emit('update:modelValue', option.value)">
            <span class="sr-only">{{ option.label }}</span>
            <i :class="option.icon" aria-hidden="true"></i>
        </button>
    </div>
`,
});
