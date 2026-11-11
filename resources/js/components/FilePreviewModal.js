import { computed, defineComponent, onBeforeUnmount, nextTick, ref, watch } from 'vue';
import LoadingFrame from './LoadingFrame';
import useCloseOnTutorial from '../composables/useCloseOnTutorial';

/**
 * A document preview filling the whole viewport.
 *
 * What it renders is not decided here. The page asks the server
 * (`/files/preview`), which resolves the `view` ability, picks the viewer from
 * the file's own type and — for an Office document — mints the short-lived
 * signed URL that Microsoft's Office viewer fetches. A template that chose its own
 * viewer would be choosing whether a confidential file gets sent to a third
 * party; see App\Services\Files\FilePreview.
 *
 * Five modes: `document` (PDF, Word, Excel, PowerPoint) in an iframe, `image`
 * in an `<img>`, `video` and `audio` in their own players, and `none` — which
 * says so plainly rather than showing an empty frame.
 *
 * A document's frame is the Office viewer's URL for Word, Excel and PowerPoint,
 * and our own stream for a PDF — which the browser renders by itself, and which
 * is therefore the one document format that previews on an installation no
 * outside service can reach. An Office file gets no viewer URL when the item may
 * not be sent out, or when nothing outside this network could fetch from this
 * installation anyway; the second is the normal state on a laptop serving
 * localhost, and the empty state says so rather than blaming the file.
 *
 *
 * Three things here have no precedent in this design system, so they are built
 * rather than copied: none of the existing three modals traps focus, closes on
 * Escape, closes on the scrim, or locks body scroll. A fullscreen overlay that
 * leaves the page scrolling behind it and lets Tab wander into it is a worse
 * experience than no overlay, so this one does all four.
 *
 * `h-[100dvh]` rather than `h-screen`: on mobile Safari `100vh` is the viewport
 * *without* the browser chrome, so a full-height overlay is taller than the
 * screen and its close button sits under the address bar.
 *
 * **The iframe points at the dedicated preview route, never at the document
 * view route.** `documents.accessFile` writes a `viewed` trail entry on every
 * response, so an embedded frame there would forge a history: it would claim
 * the person opened the document on every re-render. The stream route debounces
 * a preview per person per five minutes — see FileEventRecorder.
 */
export default defineComponent({
    name: 'FilePreviewModal',
    components: { LoadingFrame },
    emits: ['close', 'navigate'],
    props: {
        open: { type: Boolean, default: false },
        /**
         * The server's descriptor:
         * { mode, name, src, viewer_url, viewer_unreachable, renders_locally,
         * download_url, can_download, size }.
         * Null while it is being fetched.
         */
        file: { type: Object, default: null },
        /** True while the descriptor request is in flight. */
        loading: { type: Boolean, default: false },
        hasPrevious: { type: Boolean, default: false },
        hasNext: { type: Boolean, default: false },
    },
    setup(props, { emit }) {
        const panel = ref(null);
        let restoreFocusTo = null;
        let previousOverflow = '';

        const close = () => emit('close');

        // A tour must never run underneath a fullscreen overlay.
        useCloseOnTutorial(close);

        const onKeydown = (event) => {
            if (!props.open) return;

            if (event.key === 'Escape') {
                event.preventDefault();
                close();
                return;
            }

            if (event.key === 'ArrowLeft' && props.hasPrevious) {
                emit('navigate', -1);
                return;
            }

            if (event.key === 'ArrowRight' && props.hasNext) {
                emit('navigate', 1);
                return;
            }

            // The focus trap. Without it Tab walks out of the overlay and into
            // the page behind, which is still rendered.
            if (event.key !== 'Tab' || !panel.value) return;

            const focusable = panel.value.querySelectorAll(
                'button:not([disabled]), a[href], iframe, [tabindex]:not([tabindex="-1"])',
            );
            if (focusable.length === 0) return;

            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        watch(() => props.open, (open) => {
            if (open) {
                restoreFocusTo = document.activeElement;
                previousOverflow = document.body.style.overflow;
                document.body.style.overflow = 'hidden';
                document.addEventListener('keydown', onKeydown);
                nextTick(() => panel.value?.querySelector('button')?.focus());
            } else {
                document.body.style.overflow = previousOverflow;
                document.removeEventListener('keydown', onKeydown);
                // Put the caret back where the person left it, or the next Tab
                // starts from the top of the document.
                restoreFocusTo?.focus?.();
            }
        });

        // A navigation can unmount this while it is open, which would otherwise
        // leave the body permanently unscrollable.
        onBeforeUnmount(() => {
            document.body.style.overflow = previousOverflow;
            document.removeEventListener('keydown', onKeydown);
        });

        const mode = computed(() => props.file?.mode ?? 'none');

        /*
         * A document is shown by the Office viewer when there is a URL for one,
         * and by the browser itself when there is not — which only a PDF can
         * do.
         *
         * `viewer_url` is null in two situations and the difference matters to
         * the person looking: the item may not be sent out (confidential), or
         * nothing outside this network can fetch from this installation at all,
         * which is the normal state on a laptop serving localhost. The server
         * says which through `viewer_unreachable`.
         */
        const frameSrc = computed(() => props.file?.viewer_url ?? props.file?.src ?? null);
        const framed = computed(() => mode.value === 'document'
            && Boolean(props.file?.viewer_url || props.file?.renders_locally));

        // Rendered by somebody else, from a URL that expires in minutes. Saying
        // so is not a disclaimer — it is the difference between a frame that
        // has stopped working and a frame that was never going to.
        const thirdParty = computed(() => Boolean(props.file?.viewer_url));
        const unreachable = computed(() => Boolean(props.file?.viewer_unreachable));

        return { close, framed, frameSrc, mode, panel, thirdParty, unreachable };
    },
    template: `
    <Transition
        enter-active-class="transition-opacity duration-200 ease-out" enter-from-class="opacity-0" enter-to-class="opacity-100"
        leave-active-class="transition-opacity duration-150 ease-in" leave-from-class="opacity-100" leave-to-class="opacity-0">
        <div v-if="open" class="fixed inset-0 z-[70] flex h-[100dvh] w-screen flex-col bg-slate-950/90" role="dialog" aria-modal="true" aria-label="Document preview" @click.self="close">
            <div ref="panel" class="flex min-h-0 flex-1 flex-col">
                <header class="flex items-center justify-between gap-3 px-4 py-3 sm:px-6">
                    <div class="flex min-w-0 items-center gap-3">
                        <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-full text-lg text-white transition hover:bg-white/10" aria-label="Close preview" @click="close">
                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                        <p class="truncate text-sm font-bold uppercase tracking-wide text-white">{{ file?.name ?? 'Loading…' }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-full text-white transition hover:bg-white/10 disabled:opacity-30" :disabled="!hasPrevious" aria-label="Previous file" @click="$emit('navigate', -1)">
                            <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-full text-white transition hover:bg-white/10 disabled:opacity-30" :disabled="!hasNext" aria-label="Next file" @click="$emit('navigate', 1)">
                            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                        </button>
                        <a v-if="file?.can_download" :href="file.download_url" class="ml-2 inline-flex items-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2 text-sm font-bold text-white transition hover:bg-[#009bd8]">
                            <i class="fa-solid fa-download" aria-hidden="true"></i>Download
                        </a>
                    </div>
                </header>

                <p v-if="!file" class="flex min-h-0 flex-1 items-center justify-center text-sm font-semibold text-white/70">Preparing the preview…</p>

                <!-- A PDF, Word, Excel or PowerPoint document: the external
                     viewer's frame, or the browser's own renderer for the one
                     format it can handle. -->
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

                <div v-else class="mx-4 mb-4 flex min-h-0 flex-1 flex-col items-center justify-center gap-4 rounded-2xl bg-white p-8 text-center sm:mx-6">
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
                        <template v-else>
                            <h2 class="text-lg font-bold uppercase tracking-wide text-slate-900">No in-browser preview</h2>
                            <p class="mt-2 max-w-md text-sm text-slate-600">Documents, images, video and audio can be shown here. This file type has to be opened in the application it belongs to.</p>
                        </template>
                    </div>
                    <a v-if="file.can_download" :href="file.download_url" class="inline-flex items-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8]">
                        <i class="fa-solid fa-download" aria-hidden="true"></i>Download to view
                    </a>
                </div>

                <p v-if="thirdParty" class="px-4 pb-3 text-center text-xs text-white/70 sm:px-6">
                    Rendered by Microsoft's Office viewer, which reads this file from a link that expires in a few minutes.
                </p>
            </div>
        </div>
    </Transition>
`,
});
