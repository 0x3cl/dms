import { defineComponent, onBeforeUnmount, nextTick, ref, watch } from 'vue';
import FilePreviewPane from './FilePreviewPane';
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
    components: { FilePreviewPane },
    emits: ['close', 'navigate', 'update:panelOpen'],
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
        /**
         * A tiled watermark laid over the file, as a style object.
         *
         * Passed in rather than decided here, for the same reason the
         * viewer is: this component renders what it is given. The document
         * page marks a superseded version; My Files has nothing to mark, so
         * it passes nothing and nothing is drawn.
         */
        watermark: { type: Object, default: null },
        /** A label for the `aside` slot, when a caller fills it. */
        asideLabel: { type: String, default: '' },
        /**
         * The toggled panel's button label and count.
         *
         * The slot alone cannot say what the button should read, and a
         * drawer that is always open would take a third of the screen away
         * from the file somebody opened full screen to look at. So it is
         * toggled, and the toggle lives in the header beside Download.
         */
        panelLabel: { type: String, default: 'Comments' },
        panelCount: { type: Number, default: 0 },
        /**
         * Whether that panel is open, as `v-model:panel-open`.
         *
         * It was state owned here, which was right while the only way to
         * open the panel was the button next to it. It stopped being right
         * once the panel became part of what a URL reproduces: the page
         * already owns `fullscreen` and `version` in the query string,
         * because they are part of what somebody is looking at, and a third
         * thing held in a child's `ref()` could not join them. So the state
         * is lifted and this component asks for changes instead of making
         * them.
         *
         * Left uncontrolled it behaves exactly as before — closed, toggled
         * by the button — because the three callers with no panel slot have
         * no reason to bind it.
         */
        panelOpen: { type: Boolean, default: false },
        /**
         * Where "Show versions" goes, or null for no such button.
         *
         * A URL rather than an event, because for a tracked file the answer
         * is a different page — the document, where the history sits beside
         * the trail, the recipients and the label — and a plain link is what
         * lets somebody middle-click it. The caller decides whether there is
         * one at all: an untracked file has versions too, but nowhere to send
         * anybody, so it keeps the kebab's own history panel instead.
         */
        versionsHref: { type: String, default: null },
    },
    setup(props, { emit }) {
        const panel = ref(null);

        /*
         * Closed until asked for. At full size the file *is* the screen, so
         * a panel that opens with it would be taking the width away from
         * the thing the overlay exists to show.
         */
        const togglePanel = () => emit('update:panelOpen', ! props.panelOpen);
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

        return { close, panel, togglePanel };
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
                        <!-- Hidden rather than disabled when there is nowhere
                             to go: a share link shows exactly one file, and two
                             dead arrows above it read as a broken gallery. -->
                        <button v-if="hasPrevious || hasNext" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-full text-white transition hover:bg-white/10 disabled:opacity-30" :disabled="!hasPrevious" aria-label="Previous file" @click="$emit('navigate', -1)">
                            <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
                        </button>
                        <button v-if="hasPrevious || hasNext" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-full text-white transition hover:bg-white/10 disabled:opacity-30" :disabled="!hasNext" aria-label="Next file" @click="$emit('navigate', 1)">
                            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
                        </button>
                        <button
                            v-if="$slots.panel"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-bold transition"
                            :class="panelOpen ? 'bg-white text-slate-900' : 'text-white hover:bg-white/10'"
                            :aria-expanded="panelOpen ? 'true' : 'false'"
                            @click="togglePanel">
                            <i class="fa-solid fa-comment" aria-hidden="true"></i>{{ panelLabel }}
                            <span v-if="panelCount" class="rounded-full px-1.5 text-[11px] font-bold" :class="panelOpen ? 'bg-slate-100 text-slate-600' : 'bg-white/20 text-white'">{{ panelCount }}</span>
                        </button>
                        <!-- Beside Download and before it: the history is
                             about the file somebody is already looking at,
                             and it is the cheaper of the two to press by
                             mistake. Outlined rather than filled, so the
                             action that takes a copy stays the one the eye
                             lands on. -->
                        <a v-if="versionsHref" :href="versionsHref" class="ml-2 inline-flex items-center gap-2 rounded-lg border border-white/30 px-3 py-2 text-sm font-bold text-white transition hover:bg-white/10">
                            <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>Show versions
                        </a>
                        <a v-if="file?.can_download" :href="file.download_url" class="ml-2 inline-flex items-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2 text-sm font-bold text-white transition hover:bg-[#009bd8]">
                            <i class="fa-solid fa-download" aria-hidden="true"></i>Download
                        </a>
                    </div>
                </header>

                <!--
                    A side panel, when a caller has one.

                    A slot rather than a prop, because what belongs beside a
                    file at full size is the caller's business: the document
                    page puts the version history here, and My Files has
                    nothing to put and gets the full width.

                    Hidden below lg. A 18rem column beside a document on a
                    phone leaves neither of them readable, and the drawer
                    behind the overlay is still the way to it.
                -->
                <div class="flex min-h-0 flex-1 gap-4 px-4 pb-4 sm:px-6">
                    <!--
                        An opaque panel, not a transparent column.

                        The overlay's scrim is translucent, so anything drawn
                        straight onto it reads against whatever the page
                        behind happens to have there — a sidebar's nav items
                        ran straight through the version rows. A surface of
                        its own is what makes this a panel rather than text
                        floating over a screenshot.

                        White, and the same surface the drawer uses. A dark
                        panel meant everything inside it needed a second set
                        of colours, which is a second set to keep correct for
                        a list that is the same list in both places.
                    -->
                    <!--
                        28rem rather than 21rem. A version row is not prose —
                        it is a date, an actor and three controls on one line
                        — so the narrow column spent its width wrapping the
                        controls under the name it belonged to, which reads
                        as two rows per version in a list whose whole job is
                        to be scanned down.
                    -->
                    <aside v-if="$slots.aside" class="hidden w-[28rem] shrink-0 flex-col overflow-hidden rounded-xl border border-slate-200 bg-white lg:flex" :aria-label="asideLabel">
                        <!--
                            The heading is the panel's, not the slot's, and it
                            is outside the scroll container.

                            It used to be the first thing the caller rendered
                            *inside* the scroller, so scrolling to version 6
                            scrolled the word "Version history" off the top and
                            left a column of dates belonging to nothing. A
                            panel's title is the one part of it that must still
                            be there at the bottom of the list.

                            Taking it from asideLabel also means the visible
                            title and the accessible name are the same string
                            rather than two copies of it in two files, which is
                            how a panel ends up announced as one thing and
                            labelled as another.
                        -->
                        <p v-if="asideLabel" class="shrink-0 border-b border-slate-200 px-4 py-3 text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">{{ asideLabel }}</p>
                        <div class="min-h-0 flex-1 overflow-y-auto p-4">
                            <slot name="aside" />
                        </div>
                    </aside>

                    <!--
                        The toggled panel, beside the reference column and on
                        the same white surface. Left, with it, because both
                        are panels *about* the file rather than part of it.

                        26% of the overlay, because its content is prose: a
                        column sized to the viewport keeps a sensible measure
                        on a laptop and on a wide monitor alike. Toggled
                        rather than always present, because at full size the
                        width belongs to the file.

                        It was 45%, which is a measure sized for a document
                        rather than for a margin note. With the version list
                        open beside it the two panels took two thirds of the
                        overlay between them and the file — the thing being
                        commented on — was left the narrowest column on
                        screen. A comment is read against the file, so the
                        file keeps the width.
                    -->
                    <aside v-if="$slots.panel && panelOpen" class="hidden w-[26%] min-w-[20rem] shrink-0 flex-col overflow-hidden rounded-xl border border-slate-200 bg-white lg:flex" :aria-label="panelLabel">
                        <slot name="panel" />
                    </aside>

                    <!-- What a preview looks like lives in one component, so
                         the page's inline preview and this modal cannot drift
                         apart on a format one of them learns about. -->
                    <!--
                        A flex column, and a surface of its own.

                        Two separate fixes. FilePreviewPane's root sizes
                        itself against a flex parent; this wrapper was a
                        plain relative box, so its flex-1 resolved against
                        nothing, the pane took its content's height and a
                        long document ran past the bottom of the overlay —
                        the padding was there and the content was simply
                        taller than the box meant to clip it.

                        And the surface: without one, an image or a PDF sat
                        directly on a translucent scrim with the page behind
                        showing around it. A preview is a thing being looked
                        at, so it gets a container to be looked at in —
                        slate, matching the pale card the in-page preview
                        uses, so a document does not change background between the
                        two places it is drawn.
                    -->
                    <div class="relative flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden rounded-xl border border-slate-200 bg-slate-100">
                        <FilePreviewPane :file="file" />
                        <!-- Over the frame, never inside it: an iframe's
                             content is another document. pointer-events-none
                             so the file underneath still scrolls and selects
                             through the layer. -->
                        <div v-if="watermark" class="pointer-events-none absolute inset-0 z-10" :style="watermark" aria-hidden="true"></div>
                    </div>

                </div>
            </div>
        </div>
    </Transition>
`,
});
