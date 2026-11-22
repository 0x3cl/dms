import { Head, useForm } from '@inertiajs/vue3';
import { computed, defineComponent, ref } from 'vue';
import Card from '../../components/Card';
import FilePreviewModal from '../../components/FilePreviewModal';
import VisibilityBadge from '../../components/VisibilityBadge';

/**
 * The landing page for a share link.
 *
 * Standalone — no AdminShell, because whoever opens this may not have an
 * account at all. Its own <main> therefore carries `flex-1`: app.js wraps every
 * page in a `flex min-h-screen flex-col` column and Vue cannot fall a class
 * through onto an Inertia page's fragment root, so without it a short page
 * leaves a white gap above the footer.
 *
 * **It previews through the same component My Files does.** The viewer is not
 * chosen here — the server ships the descriptor FilePreview built, resolved
 * through the link instead of through a session, so a shared Word document is
 * drawn by the same in-browser renderer and a PDF by the same frame. This page
 * used to ask its own narrower question (is the MIME type inline-able?) and so
 * refused every Office document, which sniffs as `application/zip`; on a
 * view-only link that left a card offering nothing at all.
 *
 * Download is a plain <a>, not a router visit: it returns a file response,
 * which an Inertia XHR visit cannot follow.
 */
export default defineComponent({
    name: 'ShareLinkShow',
    components: { Card, FilePreviewModal, Head, VisibilityBadge },
    props: {
        token: { type: String, required: true },
        item: { type: Object, required: true },
        /** FilePreview's descriptor, or null when the link grants no view. */
        preview: { type: Object, default: null },
        can: { type: Object, required: true },
        /** Who the trail will say opened this, or null for a signed-in reader. */
        visitor: { type: Object, default: null },
        /** True when nobody is signed in and nobody has said who they are yet. */
        needsIdentity: { type: Boolean, default: false },
    },
    setup(props) {
        const open = ref(false);

        /*
         * Who opened this.
         *
         * Asked once per device, and declining is a real answer — "Stay
         * anonymous" posts no name and the server mints a stable handle, so
         * the trail can say the same visitor came back twice without ever
         * learning who they are. Nothing is recorded and no view is spent
         * until one of the two buttons is pressed; see GuestIdentity.
         */
        const identity = useForm({ name: '' });
        const identify = (anonymous) => {
            if (anonymous) identity.name = '';
            identity.post(`/s/${props.token}/identify`, { preserveScroll: true });
        };

        // `none` is a real answer — an archive or an unknown format — and the
        // card should say so rather than offer a button onto an empty frame.
        const canPreview = computed(() => Boolean(props.preview) && props.preview.mode !== 'none');

        const readableSize = () => {
            const bytes = props.item.size;
            if (!bytes) return '';
            const units = ['B', 'KB', 'MB', 'GB'];
            let value = bytes;
            let unit = 0;
            while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit += 1; }
            return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
        };

        const kindLabel = computed(() => {
            if (props.item.kind === 'folder') return 'Folder';
            const extension = props.preview?.extension;
            return extension ? `${String(extension).toUpperCase()} file` : (props.item.mime_type || 'File');
        });

        return {
            identify,
            identity,
            canPreview,
            kindLabel,
            open,
            readableSize,
            downloadUrl: `/s/${props.token}/file`,
        };
    },
    template: `
    <Head :title="item.name" />
    <main class="flex-1 bg-slate-50 px-4 py-12 sm:px-6">
        <div v-if="needsIdentity" class="mx-auto max-w-lg">
            <Card class="rounded-2xl">
                <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-sky-100 text-xl text-[#07559e]" aria-hidden="true">
                    <i class="fa-solid fa-hand"></i>
                </span>
                <h1 class="mt-4 text-xl font-bold uppercase tracking-wide text-slate-900">Before you open this</h1>
                <p class="mt-2 text-slate-600">
                    <span class="font-semibold text-slate-800">{{ item.shared_by || 'Someone' }}</span> shared
                    <span class="font-semibold text-slate-800">{{ item.name }}</span> with you. Who should the record say opened it?
                </p>

                <form class="mt-6" @submit.prevent="identify(false)">
                    <label class="block">
                        <span class="text-xs font-bold uppercase tracking-wide text-slate-500">Your name</span>
                        <input v-model="identity.name" class="field" placeholder="e.g. Maria Santos" maxlength="60" autocomplete="name" />
                    </label>
                    <p v-if="identity.errors.name" class="error">{{ identity.errors.name }}</p>

                    <!-- Not verified, and said so plainly. A name typed into a
                         box decides nothing here — it labels an audit row. -->
                    <p class="mt-2 text-xs text-slate-500">This is only recorded in the document's history. It is not an account and it is not checked.</p>

                    <div class="mt-6 flex flex-wrap gap-3">
                        <button type="submit" :disabled="identity.processing || !identity.name.trim()" class="inline-flex items-center justify-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8] disabled:opacity-50">
                            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>Continue
                        </button>
                        <button type="button" :disabled="identity.processing" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 disabled:opacity-50" @click="identify(true)">
                            <i class="fa-solid fa-user-secret" aria-hidden="true"></i>Stay anonymous
                        </button>
                    </div>
                </form>
            </Card>
        </div>

        <div v-else class="mx-auto max-w-2xl">
            <Card class="rounded-2xl">
                <!-- The classification beside the eyebrow, not buried in
                     the details grid: it is the thing that decides whether
                     the reader may pass this link on, so it belongs where
                     they are already looking. -->
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Shared with you</p>
                    <VisibilityBadge v-if="item.visibility" :visibility="item.visibility" />
                </div>
                <h1 class="mt-1 break-words text-2xl font-bold uppercase tracking-wide text-slate-900">{{ item.name }}</h1>
                <p v-if="item.shared_by" class="mt-2 text-slate-600">Shared by {{ item.shared_by }}.</p>
                <p v-if="item.code" class="mt-1 font-mono text-sm font-bold text-slate-500">{{ item.code }}</p>

                <dl class="mt-7 grid grid-cols-1 gap-4 border-t border-slate-200 pt-6 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Type</dt>
                        <dd class="mt-1 font-semibold text-slate-800">{{ kindLabel }}</dd>
                    </div>
                    <div v-if="item.size">
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Size</dt>
                        <dd class="mt-1 font-semibold text-slate-800">{{ readableSize() }}</dd>
                    </div>
                </dl>

                <div class="mt-7 flex flex-wrap gap-3 border-t border-slate-200 pt-6">
                    <button v-if="canPreview" type="button" class="inline-flex items-center justify-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8]" @click="open = true">
                        <i class="fa-solid fa-eye" aria-hidden="true"></i>Open
                    </button>
                    <a v-if="can.download" :href="downloadUrl" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                        <i class="fa-solid fa-download" aria-hidden="true"></i>Download
                    </a>
                    <p v-if="!can.download && !canPreview" class="text-sm text-slate-500">
                        {{ preview ? 'This file type cannot be shown in a browser, and this link is view-only — ask whoever shared it for a copy.' : 'This link does not permit opening the file.' }}
                    </p>
                </div>
                <p v-if="canPreview && !can.download" class="mt-3 text-sm text-slate-500">This link is view-only — no copy can be taken.</p>

                <!-- Shown back, so an anonymous handle reads as a handle
                     rather than as a failure to be recognised. -->
                <p v-if="visitor" class="mt-4 border-t border-slate-200 pt-4 text-xs text-slate-500">
                    <i class="fa-solid fa-user-pen mr-1.5" aria-hidden="true"></i>
                    Recorded in this document's history as <span class="font-bold text-slate-700">{{ visitor.name }}</span>{{ visitor.anonymous ? ' — a handle generated for this device.' : '.' }}
                </p>
            </Card>
        </div>

        <FilePreviewModal :open="open" :file="preview" @close="open = false" />
    </main>
`,
});
