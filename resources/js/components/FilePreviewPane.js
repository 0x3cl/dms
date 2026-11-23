import { computed, defineComponent } from 'vue';
import LoadingFrame from './LoadingFrame';
import OfficeRenderer from './OfficeRenderer';

/**
 * The thing a preview actually renders, without any chrome around it.
 *
 * Lifted out of `FilePreviewModal` when the document page began rendering its
 * file in the page rather than behind a button. Two implementations of "what
 * does a docx look like" is how one of them quietly stops handling a format
 * the other learned about — the same reasoning that produced `AccessResolver`
 * and `DocumentLog::record()`. The modal keeps everything that is *modal*
 * about it: the scrim, the focus trap, Escape, the navigation arrows.
 *
 * **It still decides nothing.** The server says which viewer through
 * `App\Services\Files\FilePreview` — a template choosing between a renderer
 * we run and a URL we hand to Microsoft would be a template deciding whether
 * a confidential document leaves the building.
 *
 * Five modes: `document` (a PDF or an Office file), `image`, `video`, `audio`
 * and `none`, which says so plainly rather than showing an empty frame.
 */
export default defineComponent({
    name: 'FilePreviewPane',
    components: { LoadingFrame, OfficeRenderer },
    props: {
        /**
         * The server's descriptor:
         * { mode, name, src, viewer_url, client_renderer, viewer_unreachable,
         * renders_locally, download_url, can_download, size }.
         * Null while it is being fetched.
         */
        file: { type: Object, default: null },
    },
    setup(props) {
        const mode = computed(() => props.file?.mode ?? 'none');

        /*
         * A document is shown by the Office viewer when there is a URL for
         * one, and by the browser itself when there is not — which only a PDF
         * can do.
         *
         * `viewer_url` is null in three situations and the difference
         * matters to the person looking: the item may not be sent out
         * (confidential), this reader may not take a copy and a viewer URL
         * is one, or nothing outside this network can fetch from this
         * installation at all, which is the normal state on a laptop serving
         * localhost. The server says which through `viewer_unreachable` and
         * `viewer_needs_download`; it never leaves the page to guess.
         */
        const frameSrc = computed(() => props.file?.viewer_url ?? props.file?.src ?? null);

        /*
         * Word and Excel are drawn here now rather than framed, so they are
         * checked first: `framed` would otherwise claim them through
         * `renders_locally`'s sibling branch.
         */
        const clientRenderer = computed(() => props.file?.client_renderer ?? null);
        const framed = computed(() => mode.value === 'document'
            && clientRenderer.value === null
            && Boolean(props.file?.viewer_url || props.file?.renders_locally));

        const thirdParty = computed(() => Boolean(props.file?.viewer_url));
        const unreachable = computed(() => Boolean(props.file?.viewer_unreachable));
        /*
         * The format is renderable and this reader may not have it rendered,
         * because the only renderer for it is somebody else's and sending it
         * there is a copy leaving the server. A third sentence rather than
         * reusing "this format cannot be shown", which is false — it can be,
         * for somebody holding a copy.
         */
        const needsDownload = computed(() => Boolean(props.file?.viewer_needs_download));

        return { clientRenderer, framed, frameSrc, mode, needsDownload, thirdParty, unreachable };
    },
    template: `
    <div class="flex min-h-0 flex-1 flex-col">
        <!-- A chip rather than bare text: this pane sits on a pale card in
             the page and on a near-black scrim in the modal, and one colour
             cannot read on both. -->
        <div v-if="!file" class="flex min-h-0 flex-1 items-center justify-center p-6">
            <p class="inline-flex items-center gap-3 rounded-xl bg-white px-5 py-3 text-sm font-semibold text-slate-600 shadow-sm">
                <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>Preparing the preview…
            </p>
        </div>

        <!-- A PDF, Word, Excel or PowerPoint document: the external
             viewer's frame, or the browser's own renderer for the one
             format it can handle. -->
        <!-- Word and Excel, drawn in this browser from our own stream:
             no outside service, works on a LAN, and a confidential
             document previews for the first time. -->
        <!-- size and download-url are passed because the renderer's
             first decision is whether to render at all: a 60 MB workbook
             has no in-browser drawing that beats opening it in Excel, and
             the gate has to offer that door rather than describe it. -->
        <OfficeRenderer
            v-else-if="clientRenderer"
            :src="file.src"
            :renderer="clientRenderer"
            :name="file.name"
            :size="file.size || 0"
            :download-url="file.can_download ? file.download_url : null"
            class="min-h-0 flex-1" />

        <LoadingFrame v-else-if="framed" :src="frameSrc" :title="file.name" class="min-h-0 flex-1" />

        <div v-else-if="mode === 'image'" class="flex min-h-0 flex-1 items-center justify-center p-4 sm:p-6">
            <!-- object-contain, so a tall scan and a wide diagram both
                 fit without cropping or stretching. -->
            <img :src="file.src" :alt="file.name" class="max-h-full max-w-full rounded-xl bg-white object-contain shadow-2xl" />
        </div>

        <div v-else-if="mode === 'video'" class="flex min-h-0 flex-1 items-center justify-center p-4 sm:p-6">
            <!-- controls only: autoplay in a modal somebody opened by
                 mistake is a sound nobody asked for. preload metadata,
                 so the duration and the scrubber are right before a
                 byte of the video itself is fetched. -->
            <video :src="file.src" controls playsinline preload="metadata" class="max-h-full max-w-full rounded-xl bg-black shadow-2xl"></video>
        </div>

        <div v-else-if="mode === 'audio'" class="flex min-h-0 flex-1 items-center justify-center p-4 sm:p-6">
            <div class="w-full max-w-xl rounded-2xl bg-white p-8 text-center">
                <span class="inline-flex h-16 w-16 items-center justify-center rounded-full bg-sky-100 text-2xl text-[#07559e]" aria-hidden="true"><i class="fa-solid fa-music"></i></span>
                <p class="mt-4 truncate font-bold uppercase tracking-wide text-slate-800">{{ file.name }}</p>
                <audio :src="file.src" controls preload="metadata" class="mt-4 w-full"></audio>
            </div>
        </div>

        <div v-else class="flex min-h-0 flex-1 flex-col items-center justify-center gap-4 rounded-2xl bg-white p-8 text-center">
            <span class="inline-flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 text-2xl text-slate-500" aria-hidden="true">
                <i class="fa-solid fa-file-circle-question"></i>
            </span>
            <div>
                <!-- Two different answers. "This format cannot be shown"
                     is about the file; "no viewer can reach this
                     installation" is about the network, and is the
                     normal state on a laptop serving localhost. Telling
                     somebody the first when the second is true sends
                     them looking for a problem in the wrong place. -->
                <template v-if="unreachable">
                    <h2 class="text-lg font-bold uppercase tracking-wide text-slate-900">Preview unavailable here</h2>
                    <p class="mt-2 max-w-md text-sm text-slate-600">This format is rendered by Microsoft's Office viewer, which fetches the file from this server — and this installation is not reachable from the internet, so it has nothing to fetch. Download it to read it, or set a public <span class="font-mono text-xs">APP_URL</span> on a deployed server.</p>
                </template>
                <template v-else-if="needsDownload">
                    <h2 class="text-lg font-bold uppercase tracking-wide text-slate-900">Preview not available on this access</h2>
                    <p class="mt-2 max-w-md text-sm text-slate-600">This format can only be rendered by Microsoft's Office viewer, which reads the file from this server — so previewing it means sending a copy out. That is not permitted here, where no copy of this file may leave the server.</p>
                </template>
                <template v-else>
                    <h2 class="text-lg font-bold uppercase tracking-wide text-slate-900">No in-browser preview</h2>
                    <p class="mt-2 max-w-md text-sm text-slate-600">Documents, images, video and audio can be shown here. This file type has to be opened in the application it belongs to.</p>
                </template>
            </div>
            <a v-if="file.can_download" :href="file.download_url" class="inline-flex items-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8]">
                <i class="fa-solid fa-download" aria-hidden="true"></i>Download to view
            </a>
        </div>


        <!-- Rendered by somebody else, from a URL that expires in minutes.
             Saying so is not a disclaimer — it is the difference between a
             frame that has stopped working and one that was never going to. -->
        <p v-if="thirdParty" class="pt-3 text-center text-xs text-slate-500">
            Rendered by Microsoft's Office viewer, which reads this file from a link that expires in a few minutes.
        </p>
    </div>
`,
});
