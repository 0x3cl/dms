import { Link } from '@inertiajs/vue3';
import { defineComponent } from 'vue';

/**
 * Where you are in the tree.
 *
 * The root is never shown by its stored name — it is `root-{id}` and an
 * implementation detail — so the first crumb is the owner's label instead.
 */
export default defineComponent({
    name: 'FileBreadcrumbs',
    components: { Link },
    props: {
        rootLabel: { type: String, default: 'My Files' },
        rootHref: { type: String, required: true },
        crumbs: { type: Array, default: () => [] },
    },
    template: `
    <nav class="flex flex-wrap items-center gap-1 text-sm" aria-label="Breadcrumb">
        <Link :href="rootHref" class="inline-flex items-center gap-2 rounded-lg px-2 py-1 font-bold text-[#07559e] hover:bg-sky-100">
            <i class="fa-solid fa-hard-drive" aria-hidden="true"></i>{{ rootLabel }}
        </Link>
        <template v-for="(crumb, index) in crumbs" :key="crumb.public_id">
            <i class="fa-solid fa-chevron-right text-xs text-slate-400" aria-hidden="true"></i>
            <Link
                v-if="index < crumbs.length - 1"
                :href="rootHref + (rootHref.includes('?') ? '&' : '?') + 'folder=' + crumb.public_id"
                class="rounded-lg px-2 py-1 font-semibold text-[#07559e] hover:bg-sky-100">{{ crumb.name }}</Link>
            <span v-else class="rounded-lg px-2 py-1 font-bold text-slate-700" aria-current="page">{{ crumb.name }}</span>
        </template>
    </nav>
`,
});
