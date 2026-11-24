import { defineComponent, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * The three-dot menu that hangs off a card or a row.
 *
 * Items are passed as data rather than slotted markup so every caller gets the
 * same keyboard target size, the same disabled styling and the same close-on-
 * choose behaviour. An item is `{ key, label, icon, hint, tone, disabled }`;
 * choosing one emits `select` with it.
 *
 * The panel is teleported to `<body>` and positioned in viewport coordinates
 * rather than absolutely inside the trigger. Every caller renders this inside
 * something that clips: a file card is `overflow-hidden` so it can round its
 * thumbnail, and a table scrolls horizontally. Absolutely positioned, the menu
 * was cut off at the card's edge — a raised `z-index` cannot escape an
 * ancestor's overflow, so the only fix that holds everywhere is to take the
 * panel out of that ancestor entirely.
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
        const panel = ref(null);
        const trigger = ref(null);
        const position = ref({ top: 0, left: 0 });

        const close = () => {
            open.value = false;
        };

        // Measured against the viewport, and flipped rather than allowed to
        // hang off it: a menu opened on the last row of a long page would
        // otherwise render below the fold with no way to scroll to it, since
        // the page scroll closes it.
        const place = () => {
            const button = trigger.value;
            const box = panel.value;

            if (!button || !box) {
                return;
            }

            const rect = button.getBoundingClientRect();
            const width = box.offsetWidth;
            const height = box.offsetHeight;
            const margin = 8;

            let left = props.align === 'left' ? rect.left : rect.right - width;
            left = Math.min(Math.max(margin, left), window.innerWidth - width - margin);

            let top = rect.bottom + margin;
            if (top + height > window.innerHeight - margin) {
                top = Math.max(margin, rect.top - height - margin);
            }

            position.value = { top, left };
        };

        // The card behind this menu is itself clickable, so every handler here
        // stops propagation: opening the menu must not also open the folder.
        const toggle = (event) => {
            event.stopPropagation();
            open.value = !open.value;

            if (open.value) {
                nextTick(place);
            }
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
            if (!root.value?.contains(event.target) && !panel.value?.contains(event.target)) {
                close();
            }
        };
        // A scroll moves the trigger out from under a fixed panel, so the menu
        // closes rather than floating somewhere that no longer means anything.
        const onScroll = () => close();
        const onResize = () => (open.value ? place() : undefined);

        onMounted(() => {
            document.addEventListener('click', onDocumentClick);
            window.addEventListener('scroll', onScroll, true);
            window.addEventListener('resize', onResize);
        });
        onBeforeUnmount(() => {
            document.removeEventListener('click', onDocumentClick);
            window.removeEventListener('scroll', onScroll, true);
            window.removeEventListener('resize', onResize);
        });

        return { choose, close, open, panel, position, root, toggle, trigger };
    },
    template: `
    <div v-if="items.length" class="relative" ref="root" @keydown.esc.stop="close">
        <button ref="trigger" type="button" class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-500 transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-800" :aria-label="label" :title="label" :aria-expanded="open" @click="toggle">
            <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
        </button>
        <Teleport to="body">
            <div v-if="open" ref="panel" class="fixed z-[70] w-64 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 shadow-lg" :style="{ top: position.top + 'px', left: position.left + 'px' }" @keydown.esc.stop="close">
                <p v-if="title" class="truncate px-4 pb-1 pt-2 text-[11px] font-bold uppercase tracking-[0.16em] text-slate-400">{{ title }}</p>
                <button v-for="item in items" :key="item.key" type="button" :disabled="item.disabled" class="flex min-h-11 w-full items-start gap-3 px-4 py-2.5 text-left text-sm transition disabled:cursor-not-allowed disabled:opacity-50" :class="item.tone === 'danger' ? 'text-rose-700 hover:bg-rose-50' : 'text-slate-700 hover:bg-sky-50 hover:text-[#07559e]'" @click="choose($event, item)">
                    <i :class="item.icon + ' mt-0.5 w-4 shrink-0 text-center'" aria-hidden="true"></i>
                    <span class="min-w-0">
                        <span class="block font-semibold">{{ item.label }}</span>
                        <span v-if="item.hint" class="mt-0.5 block text-xs text-slate-500">{{ item.hint }}</span>
                    </span>
                </button>
            </div>
        </Teleport>
    </div>
`,
});
