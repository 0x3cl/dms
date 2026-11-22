import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, defineComponent, ref } from 'vue';
import Card from '../../components/Card';
import FilePreviewModal from '../../components/FilePreviewModal';
import FilePreviewPane from '../../components/FilePreviewPane';
import TrackDocumentCard from '../../components/TrackDocumentCard';
import VisibilityBadge from '../../components/VisibilityBadge';

/**
 * The public tracking page — what a scanned label or a typed code resolves to
 * for somebody with no account.
 *
 * **Standalone, like the share landing page**, because whoever opens this may
 * have no account at all: no AdminShell, and its own `<main>` carries `flex-1`
 * since app.js wraps every page in a flex column and Vue cannot fall a class
 * through onto an Inertia page's fragment root.
 *
 * It renders exactly two answers, and the difference between them is the whole
 * point of the page:
 *
 * - A **public** document is described and previewed, through the same
 *   `FilePreviewPane` the document page and My Files use. Nothing here chooses
 *   the viewer — the server ships `FilePreview`'s descriptor, and for a
 *   signed-out reader it reports `can_download` false, so a code buys a look
 *   and not a copy.
 * - A **private or confidential** document is named by its code and its level
 *   and by nothing else. There is no title to read, no uploader, no type and
 *   no frame: the page says it exists, says it is restricted, and points at
 *   the two doors that are real — sign in, or ask for a share link.
 *
 * The lookup itself is `TrackDocumentCard`, the same component the Track page
 * and the Documents register's modal use, pointed at the public route. Type,
 * scan or upload a picture of a label; building a second chooser here is how
 * the third method ends up missing from one of them.
 */
export default defineComponent({
    name: 'PublicTrack',
    components: { Card, FilePreviewModal, FilePreviewPane, Head, Link, TrackDocumentCard, VisibilityBadge },
    props: {
        code: { type: String, default: '' },
        notFound: { type: Boolean, default: false },
        /** The input could not be a tracking code at all — a separate miss. */
        malformed: { type: Boolean, default: false },
        /** Which lookup method opens first — `code`, `scan` or `upload`. */
        method: { type: String, default: 'code' },
        /** The answer, or null when nothing has been looked up yet. */
        result: { type: Object, default: null },
    },
    setup(props) {
        const page = usePage();
        const isAuthenticated = computed(() => Boolean(page.props.auth?.user));

        /** The preview at full size, for a scanned page of small print. */
        const enlarged = ref(false);

        // `none` is a real answer — an archive, or a format no browser draws —
        // and it should say so rather than offer a button onto an empty frame.
        const previewable = computed(() => Boolean(props.result?.preview)
            && props.result.preview.mode !== 'none');

        /*
         * The refusal panel takes the level's own colour — orange for
         * private, red for confidential — matching the VisibilityBadge at the
         * top of the same card. It was a fixed amber, so a confidential
         * document was stated in warning tones beside a red badge on the one
         * page that exists to state its classification. Only a restricted
         * document reaches this panel, and an unknown value takes the most
         * restrictive tint for the same reason the badge does.
         */
        const restrictedTint = computed(() => (props.result?.visibility === 'private'
            ? { panel: 'border-orange-200 bg-orange-50', ink: 'text-orange-900' }
            : { panel: 'border-red-200 bg-red-50', ink: 'text-red-900' }));

        return { enlarged, isAuthenticated, previewable, restrictedTint };
    },
    template: `
    <Head :title="result && result.open ? result.title : 'Track a document'" />
    <main class="flex-1 bg-slate-50 px-4 py-8 sm:px-6 sm:py-10">
        <!--
            Two widths, because this page is two pages.

            With an answer on screen it carries a file preview and a details
            card, which want the room. With no answer it is a single short
            form, and 4xl made that form a wide expanse of card around one
            input — it read as a page still loading rather than as a thing to
            type in. Narrowing only the empty state leaves the answer at the
            width it needs.
        -->
        <div class="mx-auto w-full" :class="result ? 'max-w-4xl' : 'max-w-2xl'">

            <!-- The answer, when there is one. -->
            <Card v-if="result" class="rounded-2xl">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Tracked document</p>
                        <p class="mt-1 break-all font-mono text-lg font-bold text-slate-900">{{ result.code }}</p>
                    </div>
                    <VisibilityBadge :visibility="result.visibility" />
                </div>

                <!-- Public: the document, described and shown. -->
                <template v-if="result.open">
                    <h1 class="mt-5 break-words text-2xl font-bold uppercase tracking-wide text-slate-900">{{ result.title }}</h1>

                    <dl class="mt-6 grid grid-cols-1 gap-4 border-t border-slate-200 pt-6 sm:grid-cols-3">
                        <div v-if="result.type">
                            <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Type</dt>
                            <dd class="mt-1 font-semibold text-slate-800">{{ result.type }}</dd>
                        </div>
                        <div v-if="result.uploader">
                            <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Uploaded by</dt>
                            <dd class="mt-1 font-semibold text-slate-800">{{ result.uploader }}</dd>
                        </div>
                        <div v-if="result.uploaded_at">
                            <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Uploaded</dt>
                            <dd class="mt-1 font-semibold text-slate-800">{{ result.uploaded_at }}</dd>
                        </div>
                    </dl>

                    <div v-if="previewable" class="mt-7 border-t border-slate-200 pt-6">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Preview</p>
                            <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold uppercase tracking-wide text-slate-700 transition hover:bg-slate-50" @click="enlarged = true">
                                <i class="fa-solid fa-expand" aria-hidden="true"></i>Open full size
                            </button>
                        </div>

                        <!--
                            A window onto the file, not the file at full height
                            — the same definite height the document page gives
                            it, for the same reason: an iframe and
                            OfficeRenderer both fill their container, so
                            without one a long document grows the page to its
                            own length.
                        -->
                        <div class="mt-4 flex h-[70vh] min-h-[26rem] flex-col overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
                            <FilePreviewPane :file="result.preview" />
                        </div>

                        <!-- Said plainly, because a visitor holding a printed
                             code is given a look and not a copy. -->
                        <p class="mt-3 text-sm text-slate-500">
                            <i class="fa-solid fa-circle-info mr-1.5" aria-hidden="true"></i>
                            <template v-if="isAuthenticated">Open this document from the register to download it or read its trail.</template>
                            <template v-else>This is a view only. <Link href="/login" class="font-semibold text-[#07559e] hover:text-[#043d78]">Sign in</Link> to download it or follow where it has been.</template>
                        </p>
                    </div>

                    <p v-else class="mt-7 border-t border-slate-200 pt-6 text-sm text-slate-600">
                        This document has no file that a browser can show. Sign in, or ask whoever filed it for a share link.
                    </p>
                </template>

                <!-- Restricted: the level, and nothing that describes the document. -->
                <template v-else>
                    <div class="mt-6 rounded-xl border px-4 py-4" :class="restrictedTint.panel">
                        <p class="text-sm font-bold uppercase tracking-wide" :class="restrictedTint.ink">
                            <i class="fa-solid fa-lock mr-1.5" aria-hidden="true"></i>You cannot access this document
                        </p>
                        <!-- The level, from the row rather than written out.
                             This said "private" literally, so a confidential
                             document was described as private on the one page
                             that exists to state its classification — the
                             badge beside it said otherwise, and the two
                             disagreed. -->
                        <p class="mt-2 text-sm" :class="restrictedTint.ink">This document is {{ (result.visibility_label || 'restricted').toLowerCase() }}, and you do not have access rights to open it.</p>
                    </div>

                    <p class="mt-5 text-sm text-slate-600">
                        If you need to see this document, sign in with your staff account or ask the person who filed it to give you access.
                    </p>

                    <div class="mt-6 flex flex-wrap gap-3 border-t border-slate-200 pt-6">
                        <Link v-if="!isAuthenticated" href="/login" class="inline-flex items-center justify-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8]">
                            <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i>Staff sign in
                        </Link>
                        <Link v-else :href="'/documents/view/' + result.code" class="inline-flex items-center justify-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8]">
                            <i class="fa-solid fa-hand" aria-hidden="true"></i>Request access
                        </Link>
                        <Link href="/track" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>Track another
                        </Link>
                    </div>
                </template>
            </Card>

            <!-- The lookup. Below an answer, on its own when there is none. -->
            <Card v-if="!result" class="rounded-2xl">
                <TrackDocumentCard
                    :code="code"
                    :not-found="notFound"
                    :malformed="malformed"
                    :initial-method="method"
                    target="/track" />
                <!--
                    The other door.

                    A code opens a public document and states the level of a
                    restricted one; everything past that — the trail, a
                    download, a private document somebody tagged you on — is
                    an account. So the way to one is a button here rather
                    than a sentence, because it is the next thing a visitor
                    who did not find what they came for will want, and the
                    only other thing this page can offer them.
                -->
                <div class="mt-6 flex flex-wrap items-center justify-between gap-4 border-t border-slate-200 pt-5">
                    <p class="text-sm text-slate-600">
                        <template v-if="isAuthenticated">Signed in — a document you may read opens in full, with its trail.</template>
                        <template v-else>Staff see the full record, the audit trail and downloads.</template>
                    </p>
                    <Link
                        v-if="!isAuthenticated"
                        href="/login"
                        class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-[#07559e] px-4 py-2.5 text-sm font-bold uppercase tracking-wide text-white transition hover:bg-[#043d78]">
                        <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i>Login as staff
                    </Link>
                </div>
            </Card>

            <p v-else-if="result.open" class="mt-6 text-center text-sm text-slate-600">
                <Link href="/track" class="font-semibold text-[#07559e] hover:text-[#043d78]">Track another document</Link>
            </p>
        </div>

        <FilePreviewModal :open="enlarged" :file="result && result.preview" @close="enlarged = false" />
    </main>
`,
});
