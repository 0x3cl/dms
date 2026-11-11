import { defineComponent } from 'vue';

const TONE_CLASSES = {
    slate: 'bg-slate-100 text-slate-600',
    sky: 'bg-sky-100 text-[#07559e]',
    emerald: 'bg-emerald-100 text-emerald-700',
    rose: 'bg-rose-100 text-rose-700',
    amber: 'bg-amber-100 text-amber-700',
};

/**
 * Shared status/type pill, replacing ad hoc `rounded-full px-3 py-1` spans.
 *
 * The tone is chosen by whoever knows what the value means — FileVisibility and
 * the file-manager's access badge both resolve it server-side or in
 * utils/file-rows.js, so one value cannot be amber on one screen and rose on
 * the next.
 */
export default defineComponent({
    name: 'Badge',
    props: {
        tone: { type: String, default: 'slate' },
    },
    computed: {
        toneClass() {
            return TONE_CLASSES[this.tone] ?? TONE_CLASSES.slate;
        },
    },
    template: `
        <span class="inline-flex items-center whitespace-nowrap rounded-full px-3 py-1 text-xs font-bold leading-5 uppercase" :class="toneClass">
            <slot />
        </span>
    `,
});
