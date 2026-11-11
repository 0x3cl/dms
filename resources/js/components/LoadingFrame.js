import { defineComponent, ref, watch } from 'vue';

/**
 * An <iframe> that shows a loading state until the embedded document has
 * finished loading, and again whenever `src` changes.
 *
 * Ported from PROCMIS. Size it with classes on the component (e.g.
 * `min-h-0 flex-1`); the frame fills whatever box it is given.
 */
export default defineComponent({
    name: 'LoadingFrame',
    props: {
        src: { type: String, default: '' },
        title: { type: String, default: '' },
    },
    setup(props) {
        const loading = ref(Boolean(props.src));
        watch(() => props.src, (src) => { loading.value = Boolean(src); });

        return { loading };
    },
    template: `
        <div class="relative overflow-hidden">
            <iframe :src="src" :title="title" class="absolute inset-0 h-full w-full border-0 bg-white" @load="loading = false"></iframe>
            <div v-if="!src" class="absolute inset-0 flex items-center justify-center bg-slate-100 text-sm font-semibold text-slate-500">No document to preview.</div>
            <div v-if="loading" class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-slate-100 text-slate-500" role="status">
                <i class="fa-solid fa-spinner fa-spin text-2xl text-[#07559e]" aria-hidden="true"></i>
                <span class="text-sm font-semibold">Loading…</span>
            </div>
        </div>
    `,
});
