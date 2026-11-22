import { computed, defineComponent, ref } from 'vue';

/**
 * What this upload field accepts, said in the field.
 *
 * The limits are a row an administrator edits, and both upload forms were
 * stating them as literals instead — the document form's picker was
 * `accept=".pdf,.doc,.docx"` long after the policy had been widened, so the
 * browser's own dialog hid the spreadsheet somebody had been told to upload,
 * and the one error message they could reach named a MIME type.
 *
 * So this renders the policy the server is actually enforcing: the extension
 * list, the size for each, and the number of files. It is a component rather
 * than a template fragment because the two upload surfaces are different pages
 * and the one thing that must not drift between them is the statement of what
 * is permitted.
 *
 * It takes the `limits` payload from `UploadPolicy::limits()` verbatim.
 */
export default defineComponent({
    name: 'UploadLimitsHint',
    props: {
        limits: { type: Object, default: null },
    },
    setup(props) {
        const expanded = ref(false);

        const readable = (kb) => {
            const value = Number(kb) || 0;

            if (value <= 0) {
                return '—';
            }

            return value >= 1024 ? `${Number((value / 1024).toFixed(1))} MB` : `${value} KB`;
        };

        /*
         * Grouped by ceiling rather than listed per extension: a dozen rows
         * each saying "25 MB" is the same sentence twelve times, and what
         * somebody scanning the hint needs is "documents 25, video 200".
         */
        const bands = computed(() => {
            const byCeiling = new Map();

            (props.limits?.types ?? []).forEach((type) => {
                const ceiling = type.max_kb || props.limits?.default_max_kb || 0;

                if (!byCeiling.has(ceiling)) {
                    byCeiling.set(ceiling, []);
                }

                byCeiling.get(ceiling).push(type.extension);
            });

            return [...byCeiling.entries()]
                .sort((a, b) => a[0] - b[0])
                .map(([ceiling, extensions]) => ({ ceiling, extensions }));
        });

        return { bands, expanded, readable };
    },
    template: `
    <div v-if="limits" class="mt-2 text-xs text-slate-500">
        <p>
            <span v-if="limits.max_files > 1">Up to {{ limits.max_files }} files. </span>
            <span v-for="(band, index) in bands" :key="band.ceiling">
                <span v-if="index > 0"> · </span>
                <span class="font-semibold text-slate-600">{{ band.extensions.slice(0, 6).map((extension) => '.' + extension).join(' ') }}</span>
                <span v-if="band.extensions.length > 6"> +{{ band.extensions.length - 6 }}</span>
                up to {{ readable(band.ceiling) }}
            </span>
        </p>
        <button
            v-if="(limits.extensions || []).length > 6"
            type="button"
            class="mt-1 font-bold text-[#07559e] hover:underline"
            @click="expanded = !expanded">
            {{ expanded ? 'Hide' : 'Show' }} all {{ limits.extensions.length }} permitted types
        </button>
        <p v-if="expanded" class="mt-1 leading-relaxed">
            {{ limits.extensions.map((extension) => '.' + extension).join(', ') }}
        </p>
    </div>
`,
});
