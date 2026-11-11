import { defineComponent, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * The three-dot menu that hangs off a card or a row.
 *
 * Items are passed as data rather than slotted markup so every caller gets the
 * same keyboard target size, the same disabled styling and the same close-on-
 * choose behaviour. An item is `{ key, label, icon, hint, tone, disabled }`;
 * choosing one emits `select` with it.
 */
export default defineComponent({
    name: 'ActionMenu',
    props: {
        items: { type: Array, required: true },
        label: { type: String, default: 'More actions' },
        /** Heading above the list, when the menu needs to say what it acts on. */
        title: { type: String, default: '' },
        align: { type: String, default: 'right' },
    },
    emits: ['select'],
    setup(props, { emit }) {
        const open = ref(false);
        const root = ref(null);
        const close = () => {
            open.value = false;
        };
        // The card behind this menu is itself clickable, so every handler here
        // stops propagation: opening the menu must not also open the folder.
        const toggle = (event) => {
            event.stopPropagation();
            open.value = !open.value;
        };
        const choose = (event, item) => {
            event.stopPropagation();

            if (item.disabled) {
                return;
            }

            close();
            emit('select', item);
        };
        const onDocumentClick = (event) => {
            if (!root.value?.contains(event.target)) {
                close();
            }
        };
        onMounted(() => document.addEventListener('click', onDocumentClick));
        onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick));

        return { choose, close, open, root, toggle };
    },
    template: `
    <div v-if="items.length" class="relative" ref="root" @keydown.esc.stop="close">
        <button type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-800" :aria-label="label" :title="label" :aria-expanded="open" @click="toggle">
            <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
        </button>
        <div v-if="open" class="absolute z-20 mt-2 w-64 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 shadow-lg" :class="align === 'left' ? 'left-0' : 'right-0'">
            <p v-if="title" class="truncate px-4 pb-1 pt-2 text-[11px] font-bold uppercase tracking-[0.16em] text-slate-400">{{ title }}</p>
            <button v-for="item in items" :key="item.key" type="button" :disabled="item.disabled" class="flex min-h-11 w-full items-start gap-3 px-4 py-2.5 text-left text-sm transition disabled:cursor-not-allowed disabled:opacity-50" :class="item.tone === 'danger' ? 'text-rose-700 hover:bg-rose-50' : 'text-slate-700 hover:bg-sky-50 hover:text-[#07559e]'" @click="choose($event, item)">
                <i :class="item.icon + ' mt-0.5 w-4 shrink-0 text-center'" aria-hidden="true"></i>
                <span class="min-w-0">
                    <span class="block font-semibold">{{ item.label }}</span>
                    <span v-if="item.hint" class="mt-0.5 block text-xs text-slate-500">{{ item.hint }}</span>
                </span>
            </button>
        </div>
    </div>
`,
});
