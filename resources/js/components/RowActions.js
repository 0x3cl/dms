import { Link } from '@inertiajs/vue3';
import { computed, defineComponent } from 'vue';

/**
 * A row's actions, as icons.
 *
 * Every index table spelled these out as icon + label — "Edit", "Delete",
 * "Open" — which is the clearest form in isolation and the reason every table
 * in the app needed a `min-w-[…]` and scrolled sideways on anything smaller
 * than a laptop. The Actions column is the part of a row people actually
 * click, so it was the part that fell off the right-hand edge. Icons give the
 * column a fixed, small width, which is what lets the table itself stop
 * scrolling.
 *
 * **The label does not disappear, it moves.** Each button carries `title` —
 * the hover tooltip the office asked for — and `aria-label`, which is what a
 * screen reader announces. Dropping the text without doing both would make the
 * row unreadable to anyone not using a mouse.
 *
 * The one real cost is worth stating: a touch device has no hover, so the
 * tooltip never appears there and the icon has to carry the meaning alone.
 * That is survivable for this set — a pencil, an eye and a bin are about as
 * conventional as icons get — and it is the reason an unusual action should
 * keep a label rather than be squeezed in here.
 *
 * Actions are **data, not slots**, for the same reason `ActionMenu`'s are:
 * every caller then gets the same target size, spacing and tone, and a table
 * cannot quietly invent a fourth button style.
 */

const tones = {
    primary: 'text-[#07559e] hover:bg-sky-100',
    default: 'text-slate-600 hover:bg-slate-100',
    danger: 'text-rose-600 hover:bg-rose-50',
};

export default defineComponent({
    name: 'RowActions',
    components: { Link },
    emits: ['select'],
    props: {
        /**
         * `{ key, label, icon, href?, tone?, disabled? }[]`
         *
         * An entry with `href` renders a `<Link>`; without one it is a
         * `<button>` that emits `select` with the whole action. `label` is
         * required — it is the tooltip and the accessible name, not decoration.
         */
        actions: { type: Array, default: () => [] },
        /** Names the group for assistive technology, e.g. "Actions for Memo 12". */
        label: { type: String, default: 'Actions' },
    },
    setup(props) {
        const resolved = computed(() => props.actions
            .filter(Boolean)
            .map((action) => ({
                ...action,
                toneClass: tones[action.tone] ?? tones.default,
            })));

        return { resolved };
    },
    template: `
    <div class="flex items-center justify-end gap-0.5" :aria-label="label" role="group">
        <template v-for="action in resolved" :key="action.key">
            <Link
                v-if="action.href"
                :href="action.href"
                class="inline-flex h-9 w-9 items-center justify-center rounded-lg transition"
                :class="action.toneClass"
                :title="action.label"
                :aria-label="action.label">
                <i :class="action.icon" aria-hidden="true"></i>
            </Link>
            <button
                v-else
                type="button"
                class="inline-flex h-9 w-9 items-center justify-center rounded-lg transition disabled:cursor-not-allowed disabled:opacity-40"
                :class="action.toneClass"
                :disabled="action.disabled"
                :title="action.label"
                :aria-label="action.label"
                @click="$emit('select', action)">
                <i :class="action.icon" aria-hidden="true"></i>
            </button>
        </template>
    </div>
`,
});
