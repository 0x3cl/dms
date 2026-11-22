import { Head, Link, router } from '@inertiajs/vue3';
import { computed, defineComponent, reactive, ref, watch } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import DocumentFilterModal from '../../components/DocumentFilterModal';
import IndexControls from '../../components/IndexControls';
import Pagination from '../../components/Pagination';
import TrackDocumentCard from '../../components/TrackDocumentCard';
import UserAvatar from '../../components/UserAvatar';
import VisibilityBadge from '../../components/VisibilityBadge';
import RowActions from '../../components/RowActions';
import { pushToast } from '../../components/ToastStack';
import { formatTimestamp } from '../../utils/format-date';
import { rememberRegister } from '../../registerReturn';

/**
 * The Documents page — one screen for what used to be two sidebar entries.
 *
 * "My Documents" and "All Documents" listed the same table through different
 * filters, so they are three tabs over one paginated query here: All, Uploaded
 * by me, Shared with me. The tab lives in the query string, so a filtered list
 * can be linked and survives a back navigation.
 *
 * The All tab is a different *grant* rather than a different view — "every
 * document in the office" is not "the documents I am party to" — so it is
 * rendered only when the server says `can.all`. The server resolves the tab
 * too, and degrades to "Uploaded by me" rather than refusing, so a stale link
 * cannot 403 somebody off the page.
 *
 * Tracking is a modal rather than two more sidebar entries. It offers the two
 * things a person actually has in front of them: a printed code, or the label
 * itself. The scanner is the shared QrScanner component, which owns the
 * camera's lifetime — an Inertia visit unmounts this page without reloading the
 * document, so nothing else would ever release the stream.
 */
export default defineComponent({
    name: 'DocumentsIndex',
    components: { AdminShell, Card, ConfirmActionModal, DocumentFilterModal, Head, IndexControls, Link, Pagination, RowActions, TrackDocumentCard, UserAvatar, VisibilityBadge },
    props: {
        documents: { type: Object, required: true },
        types: { type: Array, default: () => [] },
        groups: { type: Array, default: () => [] },
        filters: { type: Object, required: true },
        counts: { type: Object, required: true },
        can: { type: Object, required: true },
    },
    setup(props) {
        const filters = reactive({ ...props.filters });
        let searchTimer;

        /*
         * Remember where the reader is, so Back comes back here.
         *
         * The *server's* answer is recorded rather than the local `filters`
         * object, because the server is what resolved the tab — a reader
         * without `documents-all.read` asking for All is shown "Uploaded by
         * me", and storing the ask would send them back to a tab they are
         * not on. It is written on arrival as well as on every change, so
         * the register's own URL is what a document opened from it returns to.
         */
        watch(() => props.filters, (current) => rememberRegister(current), { deep: true, immediate: true });

        /*
         * The filter dialog, and how many filters are narrowing the list.
         *
         * `tab`, `search` and `entries` are deliberately not counted: they
         * are the tabs, the search box and the page size, each of which is
         * already visible on screen saying what it is doing. The badge is
         * for the ones that are only visible once the dialog is opened.
         */
        const filtering = ref(false);
        const blankFilters = { group: '', type: '', visibility: '', from: '', to: '', sort: 'created', direction: 'desc' };
        const activeFilters = computed(() => Object.keys(blankFilters)
            .filter((key) => String(filters[key] ?? blankFilters[key]) !== String(blankFilters[key])).length);

        const applyFilters = (draft) => {
            Object.assign(filters, draft);
            filtering.value = false;
            load();
        };

        const load = () => router.get('/documents', filters, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
        const search = () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(load, 350);
        };
        const page = (url) => url && router.get(url, {}, { preserveScroll: true });
        const switchTab = (tab) => {
            filters.tab = tab;
            load();
        };

        const tabs = computed(() => [
            ...(props.can.all ? [{ key: 'all', label: 'All', count: props.counts.all }] : []),
            { key: 'mine', label: 'Uploaded by me', count: props.counts.mine },
            { key: 'shared', label: 'Shared with me', count: props.counts.shared },
        ]);

        // ----------------------------------------------------------- tracking
        /*
         * The chooser, the code form, the scanner and the URL-vs-bare-code
         * normalisation all moved into TrackDocumentCard, which the
         * /documents/track page renders too. This is only the modal's open
         * state now; closing it unmounts the card and with it QrScanner, which
         * releases the camera in onBeforeUnmount.
         */
        const tracking = ref(false);

        /**
         * The row a person asked to see in full.
         *
         * Uploader and Origin were columns, which is what forced this table to
         * a `min-w-[980px]` and pushed the Actions column — the part of a row
         * anybody clicks — off the right of a laptop screen.
         *
         * Origin still belongs here. The uploader came back, but *folded into
         * the Uploaded column* rather than standing beside it: a face and a
         * name over the date costs no width at all, where a fifth column cost
         * the Actions column its place on screen.
         */
        const inspecting = ref(null);

        const openTracking = () => { tracking.value = true; };
        const closeTracking = () => { tracking.value = false; };

        // ------------------------------------------------------------- delete
        const deleting = ref(null);
        const processing = ref(false);
        const confirmDelete = () => {
            processing.value = true;
            router.delete(`/documents/${deleting.value.id}`, {
                preserveScroll: true,
                onFinish: () => {
                    processing.value = false;
                    deleting.value = null;
                },
            });
        };

        /**
         * A row's actions, as data.
         *
         * Copy link is offered for every document the viewer can open, but
         * **what it copies depends on the classification.** A public or private
         * document has a tracking URL that opens without an account, so that is
         * what goes on the clipboard. A confidential one has no such URL by
         * definition — `DocumentVisibility::linkable()` is false for it — so
         * the action copies the tracking *code* instead and says so. Silently
         * copying an in-app URL and calling it a link is how somebody pastes it
         * to a colleague who cannot open it.
         */
        const rowActions = (document) => {
            const actions = [
                { key: 'open', label: 'Open', icon: 'fa-solid fa-eye', tone: 'primary', href: `/documents/view/${document.code}` },
                { key: 'details', label: 'View details', icon: 'fa-solid fa-circle-info' },
                { key: 'copy', label: document.linkable ? 'Copy link' : 'Copy tracking code', icon: 'fa-solid fa-copy' },
                { key: 'qr', label: 'View QR label', icon: 'fa-solid fa-qrcode' },
            ];

            if (props.filters.tab === 'mine') {
                actions.push(
                    { key: 'edit', label: 'Edit', icon: 'fa-solid fa-pen', href: `/documents/${document.id}/edit` },
                    { key: 'delete', label: 'Delete', icon: 'fa-solid fa-trash', tone: 'danger' },
                );
            }

            return actions;
        };

        /*
         * The QR label, fetched when it is asked for.
         *
         * Not a prop on the listing: a symbol is an SVG with the seal
         * embedded, and for the uploader it is a live credential — ten of
         * them on every navigation, nine never looked at, is the same
         * mistake FilePreview exists to avoid. The server also decides what
         * the symbol encodes, so nothing here chooses between a share link
         * and a tracking URL.
         */
        const label = ref(null);
        const labelLoading = ref(false);

        const openLabel = async (document) => {
            label.value = { code: document.code, svg: '', target: '', can_download: false, download_url: null };
            labelLoading.value = true;

            try {
                const answer = await fetch(`/documents/${document.id}/qr-label`, {
                    headers: { Accept: 'application/json' },
                });

                if (!answer.ok) throw new Error(String(answer.status));

                label.value = await answer.json();
            } catch {
                label.value = null;
                pushToast({ title: 'Could not load the label', message: 'The QR label for this document could not be fetched.', tone: 'warning' });
            } finally {
                labelLoading.value = false;
            }
        };

        /*
         * Download leaves Inertia alone: the response is a file, which an
         * XHR visit cannot follow.
         */
        const downloadLabel = () => {
            if (label.value?.download_url) {
                window.location = label.value.download_url;
            }

            label.value = null;
        };

        /*
         * The classification, as a stripe down the left of the row.
         *
         * The same three colours the badge uses, because the same word must
         * not be two colours in one application — and the same fallback: an
         * unrecognised level is drawn as the most restrictive one, since a
         * row that defaults to green on a value it does not know is a row
         * that will eventually paint a restricted document as open.
         *
         * It is an addition to the badge rather than a replacement for it.
         * Colour alone is not an answer for a reader who cannot tell these
         * three apart; the badge still says the word.
         */
        const accents = {
            public: 'border-l-emerald-500',
            private: 'border-l-amber-400',
            confidential: 'border-l-rose-500',
        };
        const accent = (visibility) => accents[visibility] ?? accents.confidential;

        /*
         * The whole row opens the document.
         *
         * Guarded on what was actually clicked: the Actions cell is full of
         * controls that mean something else, and a row-level handler that
         * swallowed them would open the document behind the dialog somebody
         * just asked for. Anything inside a button, a link or an open menu
         * is that control's click and not the row's.
         */
        const openRow = (event, document) => {
            if (event.target.closest('button, a, input, [role="menu"], [role="dialog"]')) return;

            router.visit(`/documents/view/${document.code}`);
        };

        const act = async (document, action) => {
            if (action.key === 'qr') {
                await openLabel(document);

                return;
            }

            if (action.key === 'delete') {
                deleting.value = document;

                return;
            }

            if (action.key === 'details') {
                inspecting.value = document;

                return;
            }

            if (action.key !== 'copy') return;

            const text = document.linkable
                ? `${window.location.origin}/documents/view/${document.code}`
                : document.code;

            try {
                await navigator.clipboard.writeText(text);
                pushToast({
                    title: 'Copied',
                    message: document.linkable
                        ? 'The tracking link is on your clipboard.'
                        : 'This document is confidential, so it has no shareable link. Its tracking code is on your clipboard instead.',
                    tone: document.linkable ? 'success' : 'info',
                });
            } catch {
                pushToast({ title: 'Could not copy', message: text, tone: 'warning' });
            }
        };

        return {
            act, activeFilters, applyFilters, closeTracking, confirmDelete, deleting, filtering, filters, formatTimestamp, load,
            accent, downloadLabel, label, labelLoading, openRow,
            openTracking, page, processing, rowActions, search, switchTab, tabs, tracking,
            inspecting,
        };
    },
    template: `
    <Head title="Documents" />
    <AdminShell active="documents" title="Documents">
        <Card>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Documents</p>
                    <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">Document register</h2>
                    <p class="mt-1 text-slate-600">What you uploaded, what was shared with you, and — where your role allows it — everything the office has logged.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50" data-tour="documents-track" @click="openTracking">
                        <i class="fa-solid fa-magnifying-glass-location" aria-hidden="true"></i>Track document
                    </button>
                    <Link v-if="can.create" href="/documents/upload" class="inline-flex items-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8]" data-tour="documents-upload">
                        <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>Upload
                    </Link>
                </div>
            </div>

            <div class="mt-6 flex gap-1 rounded-lg bg-slate-100 p-1">
                <button v-for="tab in tabs" :key="tab.key" type="button" class="flex-1 rounded-md px-4 py-2 text-xs font-bold uppercase tracking-wide transition" :class="filters.tab === tab.key ? 'bg-white text-[#07559e] shadow-sm' : 'text-slate-500 hover:text-slate-700'" @click="switchTab(tab.key)">
                    {{ tab.label }} ({{ tab.count }})
                </button>
            </div>

            <div class="mt-6">
                <IndexControls
                    v-model:entries="filters.entries"
                    v-model:search="filters.search"
                    search-placeholder="Search title or tracking code"
                    @search="search">
                    <!-- The same Filter button the file manager and the audit
                         trails carry, in the same place: a lone Type select
                         sitting under the search box was a second row of
                         chrome above the table, and it was the only index in
                         the application that filtered that way. -->
                    <template #actions>
                        <button type="button" class="relative inline-flex h-[42px] shrink-0 items-center gap-2 whitespace-nowrap rounded-lg border border-slate-300 px-4 text-sm font-bold text-slate-700 transition hover:bg-slate-50" data-tour="documents-filter" @click="filtering = true">
                            <i class="fa-solid fa-filter" aria-hidden="true"></i>Filter
                            <span v-if="activeFilters" class="ml-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-[#00aeef] px-1.5 text-xs font-bold text-white">{{ activeFilters }}</span>
                        </button>
                    </template>
                </IndexControls>
            </div>

            <div class="mt-6 overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="border-y border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-4">Title</th>
                            <th class="px-4 py-4">Tracking code</th>
                            <th class="px-4 py-4">Type</th>
                            <!-- One column, because the two facts are one
                                 answer: a date on its own says when but not by
                                 whom, and that was the question the removed
                                 Uploader column was answering badly in a
                                 column of its own. -->
                            <th class="px-4 py-4">Uploaded by</th>
                            <th class="px-4 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- The row is the open affordance; the eye in Actions
                             stays, because a row that only opens on click is a
                             target nothing announces. -->
                        <tr
                            v-for="document in documents.data"
                            :key="document.id"
                            class="cursor-pointer border-b border-b-slate-100 border-l-[3px] transition hover:bg-sky-50/50"
                            :class="accent(document.visibility)"
                            @click="openRow($event, document)">
                            <td class="px-4 py-4">
                                <!--
                                    Capped and ellipsised, not wrapped.

                                    A title is free text and the office files
                                    paragraphs: the table's cells do not wrap,
                                    so one long title stretched this column
                                    until the tracking code, the type and the
                                    actions were pushed off the right of the
                                    screen — on every row of the register, for
                                    the sake of one of them. The cap is a
                                    maximum rather than a width, so short
                                    titles still sit in a narrow column, and
                                    the full string is a tooltip and the first
                                    thing on the document's own page.
                                -->
                                <span class="block max-w-[14rem] truncate font-bold text-slate-800 sm:max-w-[22rem] lg:max-w-[32rem]" :title="document.title">{{ document.title }}</span>
                                <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                    <VisibilityBadge :visibility="document.visibility" />
                                    <!--
                                        Beside the level, not instead of it:
                                        they answer different questions. The
                                        level is what the document is; this is
                                        what it is to *you*.

                                        Only on All, where it tells you
                                        something — on the other two tabs the
                                        tab has already said it, and a badge on
                                        every row is noise. The server only
                                        computes it there for the same reason.
                                    -->
                                    <!-- The symbol alone, because it sits
                                         beside a badge that is already a
                                         word: two labelled pills on one line
                                         read as two classifications. The
                                         sentence is still there for a
                                         pointer and for a screen reader,
                                         which is the only form in which most
                                         people will meet it. -->
                                    <!-- The 1.75rem square is not a round
                                         number picked by eye: it is exactly
                                         the height Badge resolves to (py-1
                                         over leading-5), so the circle and
                                         the pill beside it sit on one line
                                         with matching caps rather than one
                                         riding a pixel high. Dropping the
                                         line-height is what centres the
                                         glyph — a font's line box is taller
                                         than the mark inside it, so without
                                         that the icon sits low in a box that
                                         looks correct.

                                         Padding rather than a fixed width:
                                         equal padding on both sides is what
                                         *keeps* the mark centred, where a
                                         square and a glyph of some other
                                         width leaves the gaps uneven and no
                                         single number fixes it for every
                                         icon. -->
                                    <span
                                        v-if="filters.tab === 'all' && document.shared_with_me"
                                        class="inline-flex h-7 shrink-0 items-center justify-center rounded-lg bg-sky-100 px-2.5 text-[13px] leading-none text-[#07559e]"
                                        title="Shared with you — you were tagged as a recipient of this document">
                                        <i class="fa-solid fa-user-check" aria-hidden="true"></i>
                                        <span class="sr-only">Shared with you</span>
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-4 font-mono text-xs text-slate-600">{{ document.code }}</td>
                            <td class="px-4 py-4 text-slate-600">{{ document.type }}</td>
                            <td class="px-4 py-4">
                                <!--
                                    The face comes from the live account and the
                                    name from the row's own snapshot, which is
                                    the same split the audit trail makes: a
                                    document filed by an account since closed
                                    still says who filed it, and avatar.js
                                    derives a stable face from that name alone
                                    when there is no account left to read.
                                -->
                                <div class="flex items-center gap-3">
                                    <UserAvatar :name="document.uploader_name" :image="document.user?.profile_image" :seed="document.user?.avatar_seed" />
                                    <div class="min-w-0">
                                        <span class="block truncate text-sm font-semibold text-slate-800">{{ document.uploader_name || '—' }}</span>
                                        <span class="mt-0.5 block whitespace-nowrap text-xs text-slate-500">{{ formatTimestamp(document.created_at) }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 text-right">
                                <RowActions
                                    :label="'Actions for ' + document.title"
                                    :actions="rowActions(document)"
                                    @select="act(document, $event)" />
                            </td>
                        </tr>
                        <tr v-if="documents.data.length === 0">
                            <td colspan="5" class="px-4 py-12 text-center text-slate-500">
                                <span v-if="filters.tab === 'shared'">Nothing has been shared with you yet.</span>
                                <span v-else-if="filters.tab === 'mine'">You have not uploaded any documents yet.</span>
                                <span v-else>No documents match the selected filters.</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :pagination="documents" @page="page" />
        </Card>

        <!--
            Tracking, through the same card the /documents/track page renders.

            It used to be its own two-method chooser here and a single text
            input there — two designs for one question, which meant the third
            method (uploading a picture of a label) would have had to be built
            twice and the office would meet whichever they happened to open.
            QrScanner is mounted only while a scanning method is chosen, so the
            camera is opened on request and released when the modal closes.
        -->
        <ConfirmActionModal
            :open="tracking"
            title="Track a document"
            :cancel-label="null"
            confirm-label="Close"
            icon="fa-solid fa-magnifying-glass-location"
            wide
            @close="closeTracking"
            @confirm="closeTracking">
            <div class="text-left">
                <TrackDocumentCard v-if="tracking" :heading="false" @found="closeTracking" />
            </div>
        </ConfirmActionModal>

        <!--
            Everything the table no longer shows.

            A register row identifies a document; this says where it came from.
            Origin is the uploader's office **as it stands now** — unlike a
            trail entry, which snapshots it — because this is a live record,
            not a fact about a moment.
        -->
        <!--
            The QR label, as a dialog on the row.

            What the symbol encodes is the server's answer, not this page's:
            the uploader is shown the share link's label — which is what makes
            it a credential and why Download is offered only to them — and
            everybody else a symbol for the tracking URL, which opens nothing
            without an account.
        -->
        <ConfirmActionModal
            :open="Boolean(label)"
            :title="label ? 'QR label · ' + label.code : 'QR label'"
            :message="label && label.can_download
                ? 'Print this and stick it on the physical folder. Scanning it opens this document through its share link.'
                : 'Scanning this opens the document for anyone signed in who may read it.'"
            :cancel-label="label && label.can_download ? 'Close' : null"
            :confirm-label="label && label.can_download ? 'Download PNG' : 'Close'"
            icon="fa-solid fa-qrcode"
            @close="label = null"
            @confirm="downloadLabel">
            <div v-if="label" class="text-center">
                <div v-if="labelLoading" class="py-10 text-sm text-slate-500">
                    <i class="fa-solid fa-circle-notch fa-spin mr-2" aria-hidden="true"></i>Drawing the label…
                </div>
                <template v-else>
                    <div class="mx-auto w-fit rounded-2xl border border-slate-200 bg-[#ffffff] p-4 [&>svg]:h-56 [&>svg]:w-56" v-html="label.svg"></div>
                    <p class="mt-4 font-mono text-sm font-bold text-slate-800">{{ label.code }}</p>
                    <!-- Spelled out rather than implied: somebody about to
                         print this onto a folder in a corridor should be able
                         to read what they are putting there. -->
                    <p class="mx-auto mt-3 max-w-md break-all rounded-xl bg-slate-50 px-3 py-2 font-mono text-[11px] text-slate-500">{{ label.target }}</p>
                </template>
            </div>
        </ConfirmActionModal>

        <ConfirmActionModal
            :open="Boolean(inspecting)"
            :title="inspecting ? inspecting.title : 'Document'"
            :cancel-label="null"
            confirm-label="Close"
            icon="fa-solid fa-file-lines"
            wide
            @close="inspecting = null"
            @confirm="inspecting = null">
            <div v-if="inspecting" class="text-left">
                <div class="flex flex-wrap items-center gap-3 rounded-xl bg-slate-50 px-4 py-3">
                    <span class="font-mono text-sm font-bold text-slate-800">{{ inspecting.code }}</span>
                    <VisibilityBadge :visibility="inspecting.visibility" />
                </div>

                <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Type</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">{{ inspecting.type || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Uploaded</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">{{ formatTimestamp(inspecting.created_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Uploaded by</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">{{ inspecting.user?.name ?? inspecting.uploader_name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Origin</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">
                            {{ inspecting.user?.division?.name ?? '—' }}<span v-if="inspecting.user?.section?.name"> · {{ inspecting.user.section.name }}</span>
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">File</dt>
                        <dd class="mt-1 truncate text-sm font-semibold text-slate-800">{{ inspecting.file_name || '—' }}</dd>
                    </div>
                </dl>

                <div class="mt-6 flex flex-wrap gap-3">
                    <Link :href="'/documents/view/' + inspecting.code" class="inline-flex items-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8]">
                        <i class="fa-solid fa-eye" aria-hidden="true"></i>Open document
                    </Link>
                </div>
            </div>
        </ConfirmActionModal>

        <ConfirmActionModal
            :open="deleting !== null"
            :processing="processing"
            title="Delete this document?"
            :message="'Deleting ' + (deleting?.title ?? '') + ' cannot be undone. Its trail stays on record.'"
            confirm-label="Delete"
            tone="danger"
            icon="fa-solid fa-trash"
            @close="deleting = null"
            @confirm="confirmDelete" />

        <DocumentFilterModal
            :open="filtering"
            :filters="filters"
            :types="types"
            :groups="groups"
            @close="filtering = false"
            @apply="applyFilters" />
    </AdminShell>
`,
});
