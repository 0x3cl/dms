import { Head } from '@inertiajs/vue3';
import { defineComponent, ref } from 'vue';
import Card from '../../components/Card';
import FeedbackModal from '../../components/FeedbackModal';

/**
 * The landing page for a share link.
 *
 * Standalone — no AdminShell, because whoever opens this may not have an
 * account at all. Its own <main> therefore carries `flex-1`: app.js wraps every
 * page in a `flex min-h-screen flex-col` column and Vue cannot fall a class
 * through onto an Inertia page's fragment root, so without it a short page
 * leaves a white gap above the footer.
 *
 * Both actions are plain <a> links, not router visits: they return file
 * responses, which an Inertia XHR visit cannot follow.
 */
export default defineComponent({
    name: 'ShareLinkShow',
    components: { Card, FeedbackModal, Head },
    props: {
        token: { type: String, required: true },
        item: { type: Object, required: true },
        can: { type: Object, required: true },
    },
    setup(props) {
        const preview = ref(false);

        const readableSize = () => {
            const bytes = props.item.size;
            if (!bytes) return '';
            const units = ['B', 'KB', 'MB', 'GB'];
            let value = bytes;
            let unit = 0;
            while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit += 1; }
            return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
        };

        return {
            preview,
            readableSize,
            previewUrl: `/s/${props.token}/file?disposition=inline`,
            downloadUrl: `/s/${props.token}/file`,
        };
    },
    template: `
    <Head :title="item.name" />
    <main class="flex-1 bg-slate-50 px-4 py-12 sm:px-6">
        <div class="mx-auto max-w-2xl">
            <Card class="rounded-2xl">
                <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Shared with you</p>
                <h1 class="mt-1 break-words text-2xl font-bold uppercase tracking-wide text-slate-900">{{ item.name }}</h1>
                <p v-if="item.shared_by" class="mt-2 text-slate-600">Shared by {{ item.shared_by }}.</p>

                <dl class="mt-7 grid grid-cols-1 gap-4 border-t border-slate-200 pt-6 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Type</dt>
                        <dd class="mt-1 font-semibold text-slate-800">{{ item.kind === 'folder' ? 'Folder' : (item.mime_type || 'File') }}</dd>
                    </div>
                    <div v-if="item.size">
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Size</dt>
                        <dd class="mt-1 font-semibold text-slate-800">{{ readableSize() }}</dd>
                    </div>
                </dl>

                <div class="mt-7 flex flex-wrap gap-3 border-t border-slate-200 pt-6">
                    <button v-if="can.preview" type="button" class="inline-flex items-center justify-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8]" @click="preview = true">
                        <i class="fa-solid fa-eye" aria-hidden="true"></i>Preview
                    </button>
                    <a v-if="can.download" :href="downloadUrl" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                        <i class="fa-solid fa-download" aria-hidden="true"></i>Download
                    </a>
                    <p v-if="!can.download && !can.preview" class="text-sm text-slate-500">This link does not permit opening the file.</p>
                </div>
                <p v-if="can.preview && !can.download" class="mt-3 text-sm text-slate-500">This link is view-only — no copy can be taken.</p>
            </Card>
        </div>

        <div v-if="preview" class="fixed inset-0 z-[70] flex flex-col bg-slate-950/90" role="dialog" aria-modal="true" aria-label="Document preview">
            <header class="flex items-center justify-between gap-4 px-4 py-3 text-white sm:px-6">
                <p class="truncate text-sm font-bold uppercase tracking-wide">{{ item.name }}</p>
                <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-full text-lg text-white transition hover:bg-white/10" aria-label="Close preview" @click="preview = false">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </header>
            <iframe :src="previewUrl" :title="item.name" class="min-h-0 w-full flex-1 border-0 bg-white"></iframe>
        </div>
    </main>
`,
});
