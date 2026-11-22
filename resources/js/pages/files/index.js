import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, defineComponent, reactive, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import ConfirmDeleteModal from '../../components/ConfirmDeleteModal';
import FileBreadcrumbs from '../../components/FileBreadcrumbs';
import FileFilterModal from '../../components/FileFilterModal';
import FileGrid from '../../components/FileGrid';
import FilePreviewModal from '../../components/FilePreviewModal';
import FileVersionsModal from '../../components/FileVersionsModal';
import ReplaceFileModal from '../../components/ReplaceFileModal';
import FileTable from '../../components/FileTable';
import FolderPickerModal from '../../components/FolderPickerModal';
import IndexControls from '../../components/IndexControls';
import Pagination from '../../components/Pagination';
import RecipientPicker from '../../components/RecipientPicker';
import ShareDrawer from '../../components/ShareDrawer';
import UploadLimitsHint from '../../components/UploadLimitsHint';
import ViewToggle from '../../components/ViewToggle';
import SearchableSelect from '../../components/SearchableSelect';
import usePreviewDescriptor from '../../composables/usePreviewDescriptor';

/**
 * My Files — the whole of a person's storage, their own and what has been
 * shared with them.
 *
 * "Shared with Me" was a separate page on a separate permission, which meant a
 * file somebody sent you lived somewhere you had to remember to go and look.
 * The items shared with this person are listed at the top of their own storage
 * now, each carrying a badge that says so, and from there they behave like
 * anything else: open the folder, preview the file, take a copy.
 *
 * Every control writes to the query string and reloads. That is what makes the
 * current folder, page, search, sort, filters and view survive each other — and
 * what makes the URL shareable, so a colleague opening it sees what the sender
 * saw. The filter *dialog* holds a draft and only applies on confirm, because
 * six filters that each reload the page is six reloads to describe one search.
 */
export default defineComponent({
    name: 'FilesIndex',
    components: { AdminShell, Card, ConfirmActionModal, ConfirmDeleteModal, FileBreadcrumbs, FileFilterModal, FileGrid, FilePreviewModal, FileVersionsModal, ReplaceFileModal, FileTable, FolderPickerModal, Head, IndexControls, Pagination, RecipientPicker, SearchableSelect, ShareDrawer, UploadLimitsHint, ViewToggle },
    props: {
        files: { type: Object, required: true },
        folders: { type: Array, required: true },
        folder: { type: Object, required: true },
        breadcrumbs: { type: Array, required: true },
        filters: { type: Object, required: true },
        entriesOptions: { type: Array, required: true },
        visibilities: { type: Array, required: true },
        categories: { type: Array, required: true },
        // The administrator's upload policy for personal storage, so the
        // dialog offers what the server will accept rather than everything.
        limits: { type: Object, default: null },
        owners: { type: Array, default: () => [] },
        people: { type: Array, default: () => [] },
        documentTypes: { type: Array, default: () => [] },
        documentVisibilities: { type: Array, default: () => [] },
        canTrackDocuments: { type: Boolean, default: false },
        can: { type: Object, required: true },
    },
    setup(props) {
        const filters = reactive({ ...props.filters });
        let searchTimer;

        const load = () => router.get('/files', filters, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
        const search = () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(load, 350);
        };
        const page = (url) => url && router.get(url, {}, { preserveScroll: true });

        const setView = (view) => { filters.view = view; load(); };
        const sortBy = (column) => {
            filters.direction = filters.sort === column && filters.direction === 'asc' ? 'desc' : 'asc';
            filters.sort = column;
            load();
        };

        // ------------------------------------------------------------ filters
        const filtering = ref(false);
        const applyFilters = (draft) => {
            Object.assign(filters, draft);
            filtering.value = false;
            load();
        };
        /** How many filters are narrowing the list, for the button's badge. */
        const activeFilters = computed(() => [
            filters.access !== 'all',
            filters.kind !== 'all',
            Boolean(filters.type),
            Boolean(filters.owned_by),
            Boolean(filters.from),
            Boolean(filters.to),
        ].filter(Boolean).length);

        const folderHref = (publicId) => `/files?folder=${publicId}&view=${filters.view}`;
        const streamUrl = (file) => `/files/${file.owner_public_id}/${file.public_id}`;

        // ---------------------------------------------------------- selection
        const selected = ref([]);
        const toggleSelect = (publicId) => {
            const at = selected.value.indexOf(publicId);
            if (at === -1) selected.value.push(publicId); else selected.value.splice(at, 1);
        };
        const clearSelection = () => { selected.value = []; };
        /*
         * Folders count too.
         *
         * This only ever mapped `props.files.data`, so a folder could never be
         * part of a bulk Move, Copy or Delete however it was clicked — and the
         * grid gave folder cards no checkbox at all, which at least made the
         * limitation visible. `FileManager` has always accepted a folder as
         * the subject of all three (it resolves the ability on the subject
         * *and* the destination), so nothing underneath had to change.
         */
        const selectedRows = computed(() => [
            ...props.folders.filter((folder) => selected.value.includes(folder.public_id))
                .map((folder) => ({ kind: 'folder', public_id: folder.public_id, name: folder.name })),
            ...props.files.data.filter((file) => selected.value.includes(file.public_id))
                .map((file) => ({ kind: 'file', public_id: file.public_id, name: file.name })),
        ]);

        // ------------------------------------------------------------ preview
        /*
         * The descriptor is fetched per file rather than shipped with the
         * listing: which viewer a type needs is a server decision, and for an
         * Office document it means minting a URL that is live for minutes.
         * Forty cards in a folder would otherwise mint forty of them on every
         * navigation, all but one unused.
         */
        const previewIndex = ref(-1);
        const previewable = computed(() => props.files.data.filter((file) => file.can.view));

        /*
         * Document types, grouped by the server and shown under their
         * headings — the same list and the same arrangement the Documents
         * upload form offers, because they are one picker on two screens.
         */
        const trackingTypeOptions = computed(() => props.documentTypes.map((group) => ({
            label: group.label,
            options: (group.options ?? []).map((type) => ({
                value: type.value,
                label: `${type.label} (${type.code})`,
            })),
        })));
        const { describe, descriptor: previewFile, forget, loading: previewLoading, prefetch } = usePreviewDescriptor();

        /** The files either side, so arrow navigation is already answered. */
        const neighboursOf = (index) => [previewable.value[index - 1], previewable.value[index + 1]].filter(Boolean);

        const show = (index) => {
            previewIndex.value = index;
            describe(previewable.value[index], neighboursOf(index));
        };

        const openPreview = (file) => {
            const index = previewable.value.findIndex((candidate) => candidate.public_id === file.public_id);

            if (index < 0) return;

            show(index);
        };

        /*
         * A hover is a strong hint that a click is coming, so the descriptor is
         * fetched then — by the time the modal opens, the Office viewer's URL
         * is already in hand and only Microsoft's own render is left to wait
         * for. Harmless if the click never comes: the result is a cache entry.
         */
        const prefetchPreview = (file) => {
            if (file?.can?.view) prefetch(file);
        };

        /*
         * "Show versions" in the preview, for a tracked file that has them.
         *
         * Two conditions, and both are the point. A file at version 1 has no
         * history to show, and an **untracked** file has a history with
         * nowhere to send anybody — its versions live in the kebab's own
         * panel, which stays exactly as it was. A tracked file is the case
         * where the better answer is a different page: the document, where
         * the history sits beside the trail, the recipients and the label.
         *
         * Read off the listing row rather than the descriptor, because the
         * tracking jacket is a fact about the file and the descriptor is a
         * fact about the bytes.
         */
        const previewVersionsHref = computed(() => {
            const row = previewable.value[previewIndex.value];

            return row?.tracked && (row.version ?? 1) > 1 ? row.tracked.url : null;
        });

        const closePreview = () => { previewIndex.value = -1; forget(); };
        const navigatePreview = (step) => {
            const next = previewIndex.value + step;

            if (next < 0 || next >= previewable.value.length) return;

            show(next);
        };

        // ------------------------------------------------------------- modals
        /*
         * A new folder can name recipients as it is created. Tagging people at
         * creation is the one moment somebody is actually thinking about who
         * this is for — and the badge on the folder afterwards is what tells
         * them it worked.
         */
        const newFolder = useForm({ parent: props.folder.public_id, name: '', recipients: [] });
        const creatingFolder = ref(false);

        /*
         * The directory is an optional prop, fetched the first time the dialog
         * is opened. My Files is the page people sit on, so shipping every
         * account with each folder navigation is a cost paid constantly for a
         * dialog opened rarely.
         */
        let directoryRequested = false;
        const openFolderDialog = () => {
            creatingFolder.value = true;

            if (directoryRequested) return;

            directoryRequested = true;
            router.reload({ only: ['people'] });
        };
        const submitFolder = () => newFolder.post('/files/folders', {
            preserveScroll: true,
            onSuccess: () => { creatingFolder.value = false; newFolder.reset('name', 'recipients'); },
        });

        /*
         * Upload, optionally with a tracking jacket.
         *
         * The document fields are the same four the register's own form asks
         * for, and they go to the same `DocumentRegistrar` — so a file tracked
         * from here is indistinguishable from one uploaded through Documents,
         * except that it stays in the folder you were looking at.
         */
        const uploads = useForm({
            folder: props.folder.public_id,
            files: [],
            track: false,
            title: '',
            document_type_id: '',
            visibility: 'public',
            recipients: [],
        });
        const uploading = ref(false);

        /** A tracking code names one document, so tracking takes one file. */
        const canTrack = computed(() => uploads.files.length === 1);
        const trackedVisibility = computed(
            () => (props.documentVisibilities ?? []).find((level) => level.value === uploads.visibility) ?? null,
        );

        const chooseFiles = (event) => {
            uploads.files = Array.from(event.target.files);

            // Title defaults to the filename without its extension — almost
            // always what the person would have typed.
            if (uploads.files.length === 1 && uploads.title === '') {
                uploads.title = uploads.files[0].name.replace(/\.[^.]+$/, '');
            }

            if (!canTrack.value) uploads.track = false;
        };

        const openUploadDialog = () => {
            uploading.value = true;

            // The pickers' data is lazy, exactly as the New folder dialog does
            // it: a listing should not ship every account and document type to
            // everyone who merely opens a folder.
            router.reload({ only: ['people', 'documentTypes', 'documentVisibilities'] });
        };

        const submitUpload = () => uploads.post('/files/upload', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                uploading.value = false;
                uploads.reset('files', 'track', 'title', 'document_type_id', 'visibility', 'recipients');
            },
        });

        const renaming = ref(null);
        const renameForm = useForm({ kind: 'file', public_id: '', name: '' });
        const startRename = (item, kind) => {
            renameForm.kind = kind;
            renameForm.public_id = item.public_id;
            renameForm.name = item.name;
            renaming.value = item;
        };
        const submitRename = () => renameForm.post('/files/rename', {
            preserveScroll: true,
            onSuccess: () => { renaming.value = null; },
        });

        const changingPrivacy = ref(null);
        const privacyForm = useForm({ kind: 'file', public_id: '', visibility: 'private' });
        const startPrivacy = (item, kind) => {
            privacyForm.kind = kind;
            privacyForm.public_id = item.public_id;
            privacyForm.visibility = item.visibility;
            changingPrivacy.value = item;
        };
        const submitPrivacy = () => privacyForm.post('/files/visibility', {
            preserveScroll: true,
            onSuccess: () => { changingPrivacy.value = null; },
        });

        const sharing = ref(null);

        // ------------------------------------------------------- move and copy
        /*
         * Both ask where. Move used to send the folder already on screen, which
         * made it a no-op; Copy had no destination at all and always landed in
         * the root.
         */
        const relocating = ref(null);
        const relocateProcessing = ref(false);
        const relocateError = ref('');
        const startRelocate = (action, items) => {
            relocateError.value = '';
            relocating.value = { action, items };
        };
        const confirmRelocate = (target) => {
            relocateProcessing.value = true;
            router.post(relocating.value.action === 'copy' ? '/files/copy' : '/files/move', {
                target,
                items: relocating.value.items.map(({ kind, public_id: publicId }) => ({ kind, public_id: publicId })),
            }, {
                preserveScroll: true,
                onError: (errors) => {
                    relocateError.value = errors.target ?? errors.item ?? errors.name ?? 'That could not be done.';
                },
                onSuccess: () => { relocating.value = null; clearSelection(); },
                onFinish: () => { relocateProcessing.value = false; },
            });
        };

        // ------------------------------------------------------------- delete
        const deleting = ref(null);
        const deleteProcessing = ref(false);
        const deleteError = ref('');
        const startDelete = (items) => {
            deleteError.value = '';
            deleting.value = { items };
        };
        // No `confirmation` here: moving to trash is reversible and is
        // confirmed by pressing the button. The typed code guards the purge in
        // Trash, which is the delete nothing can undo.
        const confirmDelete = () => {
            deleteProcessing.value = true;
            router.post('/files/delete', {
                items: deleting.value.items.map(({ kind, public_id: publicId }) => ({ kind, public_id: publicId })),
            }, {
                preserveScroll: true,
                onError: (errors) => {
                    deleteError.value = errors.items ?? errors.item ?? 'That could not be deleted.';
                },
                onSuccess: () => { deleting.value = null; clearSelection(); },
                onFinish: () => { deleteProcessing.value = false; },
            });
        };

        /** The kebab menu is data, so one handler fans out to the modals. */
        /*
         * Replacing a file and reading its history: both act on an item in
         * place, so both are dialogs over the row rather than navigations.
         * The history is fetched by the modal when it opens — the entries
         * carry URLs that open bytes, so a listing must not carry them.
         */
        const replacing = ref(null);
        const versioning = ref(null);

        const act = ({ item, kind, action }) => {
            const row = { kind, public_id: item.public_id, name: item.name };

            if (action === 'preview') { openPreview(item); return; }
            if (action === 'replace') { replacing.value = item; return; }
            if (action === 'versions') { versioning.value = item; return; }
            if (action === 'download') { window.location = streamUrl(item); return; }
            if (action === 'rename') { startRename(item, kind); return; }
            if (action === 'privacy') { startPrivacy(item, kind); return; }
            if (action === 'delete') { startDelete([row]); return; }
            if (action === 'move' || action === 'copy') { startRelocate(action, [row]); return; }
            if (action === 'extract') {
                router.post('/files/extract', { public_id: item.public_id }, { preserveScroll: true });
                return;
            }
            if (action === 'share') {
                sharing.value = { kind, public_id: item.public_id, name: item.name };
            }
        };

        const onOpen = ({ item, action }) => { if (action === 'select') toggleSelect(item.public_id); };

        /*
         * Zipping a selection returns a file response, which an Inertia XHR
         * visit cannot follow — so it goes through a real form post built and
         * submitted here, with the CSRF token read from the meta tag in the
         * root view. This is the same reason documents/show.js posts its
         * receive form directly.
         */
        const downloadSelection = () => {
            if (selected.value.length === 0) return;

            const form = document.createElement('form');
            form.method = 'post';
            form.action = '/files/zip';
            form.style.display = 'none';

            const token = document.createElement('input');
            token.name = '_token';
            token.value = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
            form.appendChild(token);

            selected.value.forEach((publicId) => {
                const field = document.createElement('input');
                field.name = 'items[]';
                field.value = publicId;
                form.appendChild(field);
            });

            document.body.appendChild(form);
            form.submit();
            form.remove();
        };

        return {
            replacing, versioning,
            canTrack, chooseFiles, openUploadDialog, trackedVisibility,
            act, activeFilters, applyFilters, changingPrivacy, clearSelection, closePreview, confirmDelete, openFolderDialog,
            confirmRelocate, creatingFolder, deleteError, deleteProcessing, deleting, downloadSelection,
            filtering, filters, folderHref, load, navigatePreview, newFolder, onOpen, openPreview, page,
            prefetchPreview, previewFile, previewIndex, previewLoading, previewVersionsHref, previewable, privacyForm, relocateError, relocateProcessing, relocating,
            renameForm, renaming, search, selected, selectedRows, setView, sharing, sortBy, startDelete,
            startRelocate, submitFolder, submitPrivacy, submitRename, submitUpload, uploading, uploads,
        };
    },
    template: `
    <Head title="My Files" />
    <AdminShell active="files" title="My Files">
        <Card>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Storage</p>
                    <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">{{ folder.is_root ? 'My Files' : folder.name }}</h2>
                    <p v-if="folder.is_root" class="mt-1 text-slate-600">Your own storage, and everything other people have shared with you. Your files are private unless you change their privacy or share them.</p>
                    <p v-else-if="!folder.is_mine" class="mt-1 text-slate-600">Shared with you by {{ folder.owner_name }}.</p>
                    <p v-else class="mt-1 text-slate-600">Everything in this folder.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="relative inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50" data-tour="files-filter" @click="filtering = true">
                        <i class="fa-solid fa-filter" aria-hidden="true"></i>Filter
                        <span v-if="activeFilters" class="ml-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-[#00aeef] px-1.5 text-xs font-bold text-white">{{ activeFilters }}</span>
                    </button>
                    <button v-if="can.create && folder.can_write" type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50" data-tour="files-new-folder" @click="openFolderDialog">
                        <i class="fa-solid fa-folder-plus" aria-hidden="true"></i>New folder
                    </button>
                    <button v-if="can.upload && folder.can_write" type="button" class="inline-flex items-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8]" data-tour="files-upload" @click="openUploadDialog">
                        <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>Upload
                    </button>
                </div>
            </div>

            <div class="mt-5">
                <FileBreadcrumbs root-href="/files" root-label="My Files" :crumbs="breadcrumbs" />
            </div>

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <div class="min-w-0 flex-1">
                    <IndexControls
                        v-model:entries="filters.entries"
                        v-model:search="filters.search"
                        :entries-options="entriesOptions"
                        search-placeholder="Search this folder"
                        @search="search" />
                </div>
                <ViewToggle :model-value="filters.view" @update:model-value="setView" />
            </div>

            <div v-if="selected.length" class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-sky-50 px-4 py-3">
                <p class="text-sm font-bold text-[#07559e]">{{ selected.length }} selected</p>
                <div class="flex flex-wrap gap-2">
                    <button v-if="can.download" type="button" class="inline-flex items-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2 text-sm font-bold text-white transition hover:bg-[#009bd8]" @click="downloadSelection">
                        <i class="fa-solid fa-file-zipper" aria-hidden="true"></i>Download as ZIP
                    </button>
                    <button v-if="can.edit" type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-white" @click="startRelocate('move', selectedRows)">
                        <i class="fa-solid fa-arrow-right-arrow-left" aria-hidden="true"></i>Move
                    </button>
                    <button v-if="can.create" type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-white" @click="startRelocate('copy', selectedRows)">
                        <i class="fa-solid fa-copy" aria-hidden="true"></i>Copy
                    </button>
                    <button v-if="can.delete" type="button" class="inline-flex items-center gap-2 rounded-lg border border-rose-300 px-4 py-2 text-sm font-bold text-rose-600 transition hover:bg-rose-50" @click="startDelete(selectedRows)">
                        <i class="fa-solid fa-trash" aria-hidden="true"></i>Delete
                    </button>
                    <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-white" @click="clearSelection">Clear</button>
                </div>
            </div>

            <FileGrid
                v-if="filters.view === 'grid'"
                :folders="folders"
                :files="files.data"
                :folder-href="folderHref"
                :selected="selected"
                @act="act"
                @open="onOpen"
                @prefetch="prefetchPreview"
                @preview="openPreview" />
            <FileTable
                v-else
                :folders="folders"
                :files="files.data"
                :folder-href="folderHref"
                :sort="filters.sort"
                :direction="filters.direction"
                :selected="selected"
                @act="act"
                @open="onOpen"
                @prefetch="prefetchPreview"
                @preview="openPreview"
                @sort="sortBy" />

            <Pagination :pagination="files" @page="page" />
        </Card>

        <FilePreviewModal
            :open="previewIndex >= 0"
            :file="previewFile"
            :loading="previewLoading"
            :has-previous="previewIndex > 0"
            :has-next="previewIndex >= 0 && previewIndex < previewable.length - 1"
            :versions-href="previewVersionsHref"
            @close="closePreview"
            @navigate="navigatePreview" />

        <ReplaceFileModal
            :open="replacing !== null"
            :item="replacing"
            :limits="limits"
            @close="replacing = null"
            @replaced="load" />

        <FileVersionsModal
            :open="versioning !== null"
            :item="versioning"
            @close="versioning = null"
            @reverted="load" />

        <FileFilterModal
            :open="filtering"
            :filters="filters"
            :categories="categories"
            :owners="owners"
            @close="filtering = false"
            @apply="applyFilters" />

        <FolderPickerModal
            :open="relocating !== null"
            :action="relocating?.action ?? 'move'"
            :items="relocating?.items ?? []"
            :processing="relocateProcessing"
            :error="relocateError"
            @close="relocating = null"
            @confirm="confirmRelocate" />

        <ConfirmDeleteModal
            :open="deleting !== null"
            purpose="delete"
            :items="deleting?.items ?? []"
            :processing="deleteProcessing"
            :error="deleteError"
            @close="deleting = null"
            @confirm="confirmDelete" />

        <ShareDrawer :open="sharing !== null" :item="sharing" @close="sharing = null" />

        <ConfirmActionModal
            :open="creatingFolder"
            :processing="newFolder.processing"
            title="New folder"
            message="Folders inherit the privacy of the folder they are created in. Anyone you tag here is given access to it and to everything you put inside."
            confirm-label="Create folder"
            icon="fa-solid fa-folder-plus"
            wide
            @close="creatingFolder = false"
            @confirm="submitFolder">
            <div class="space-y-4 text-left">
                <label class="block">
                    <span class="required-label text-sm font-semibold text-slate-700">Folder name</span>
                    <input v-model="newFolder.name" class="field" :class="{ 'border-rose-500': newFolder.errors.name }" placeholder="e.g. Quarterly Reports" />
                    <p v-if="newFolder.errors.name" class="error">{{ newFolder.errors.name }}</p>
                </label>
                <div>
                    <span class="text-sm font-semibold text-slate-700">Tag recipients (optional)</span>
                    <RecipientPicker v-model="newFolder.recipients" :people="people" class="mt-2" />
                    <p v-if="newFolder.recipients.length" class="mt-2 rounded-xl bg-emerald-50 px-4 py-2.5 text-sm font-semibold text-emerald-700">
                        <i class="fa-solid fa-user-check mr-1.5" aria-hidden="true"></i>{{ newFolder.recipients.length }} person{{ newFolder.recipients.length === 1 ? '' : 's' }} will be able to open this folder.
                    </p>
                    <p v-if="newFolder.errors.recipients" class="error">{{ newFolder.errors.recipients }}</p>
                </div>
            </div>
        </ConfirmActionModal>

        <ConfirmActionModal
            :open="uploading"
            :processing="uploads.processing"
            :progress="uploads.progress ? uploads.progress.percentage : null"
            title="Upload files"
            message="Files are stored privately. Nothing is readable by anyone else until you share it."
            confirm-label="Upload"
            icon="fa-solid fa-cloud-arrow-up"
            @close="uploading = false"
            @confirm="submitUpload">
            <div class="text-left">
                <label class="block">
                    <span class="required-label text-sm font-semibold text-slate-700">Choose files</span>
                    <input type="file" :multiple="!uploads.track" :accept="limits ? limits.accept : undefined" class="field file:mr-4 file:rounded-lg file:border-0 file:bg-sky-100 file:px-4 file:py-2 file:text-sm file:font-bold file:text-[#07559e]" @input="chooseFiles" />
                    <p v-if="uploads.errors.files" class="error">{{ uploads.errors.files }}</p>
                    <p v-if="uploads.errors['files.0']" class="error">{{ uploads.errors['files.0'] }}</p>
                    <UploadLimitsHint :limits="limits" />
                </label>

                <!--
                    Track this as a document.

                    Off by default: most of what goes into personal storage is
                    not an office document, and issuing a tracking code for a
                    scratch file would fill the register with noise. Shown
                    disabled rather than hidden when more than one file is
                    chosen, so the limit is something you can read instead of a
                    control that mysteriously is not there.
                -->
                <div v-if="canTrackDocuments" class="mt-5 rounded-xl border border-slate-200 p-4">
                    <label class="flex items-start gap-3">
                        <input type="checkbox" class="mt-1 h-4 w-4 rounded border-slate-300 accent-[#07559e] disabled:opacity-40" :disabled="!canTrack" v-model="uploads.track" />
                        <span class="min-w-0">
                            <span class="block text-sm font-bold text-slate-800">Track this document</span>
                            <span class="block text-xs text-slate-500">
                                <template v-if="canTrack">Issues a tracking code and a QR Tracker, and opens an audit trail — exactly as uploading through Documents does. The file stays in this folder.</template>
                                <template v-else-if="uploads.files.length === 0">Choose a file first.</template>
                                <template v-else>A tracking code names one document, so choose a single file. Several that belong together can go up as a zip.</template>
                            </span>
                        </span>
                    </label>
                    <p v-if="uploads.errors.track" class="error">{{ uploads.errors.track }}</p>

                    <div v-if="uploads.track" class="mt-4 grid gap-4 border-t border-slate-200 pt-4">
                        <label class="block">
                            <span class="required-label text-sm font-semibold text-slate-700">Document title</span>
                            <input v-model="uploads.title" class="field" :class="{ 'border-rose-500': uploads.errors.title }" />
                            <p v-if="uploads.errors.title" class="error">{{ uploads.errors.title }}</p>
                        </label>

                        <div>
                            <span class="required-label text-sm font-semibold text-slate-700">Document type</span>
                            <div class="mt-2">
                                <!-- Grouped by the server, exactly as the
                                     upload form offers it: one list, one
                                     arrangement. -->
                                <SearchableSelect v-model="uploads.document_type_id" :options="trackingTypeOptions" placeholder="Select a type" :invalid="Boolean(uploads.errors.document_type_id)" />
                            </div>
                            <p v-if="uploads.errors.document_type_id" class="error">{{ uploads.errors.document_type_id }}</p>
                            <p class="mt-1 text-xs text-slate-500">The type decides the prefix of the tracking code.</p>
                        </div>

                        <fieldset>
                            <legend class="required-label text-sm font-semibold text-slate-700">Who can open this document?</legend>
                            <div class="mt-2 grid gap-2">
                                <label v-for="level in documentVisibilities" :key="level.value" class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition" :class="uploads.visibility === level.value ? 'border-[#00aeef] bg-sky-50' : 'border-slate-200 hover:border-slate-300'">
                                    <input type="radio" class="mt-1 h-4 w-4 accent-[#07559e]" :value="level.value" v-model="uploads.visibility" />
                                    <span class="min-w-0">
                                        <span class="flex items-center gap-2 text-sm font-bold text-slate-800">
                                            {{ level.label }}
                                            <i v-if="!level.linkable" class="fa-solid fa-link-slash text-xs text-rose-500" aria-hidden="true" title="Cannot be shared by link"></i>
                                        </span>
                                        <span class="mt-0.5 block text-xs text-slate-500">{{ level.description }}</span>
                                    </span>
                                </label>
                            </div>
                            <p v-if="uploads.errors.visibility" class="error">{{ uploads.errors.visibility }}</p>
                        </fieldset>

                        <div v-if="trackedVisibility && trackedVisibility.taggable">
                            <span class="text-sm font-semibold text-slate-700" :class="{ 'required-label': !trackedVisibility.linkable }">
                                Tag the people who may open it
                                <span v-if="trackedVisibility.linkable" class="font-normal text-slate-500">(optional)</span>
                            </span>
                            <RecipientPicker v-model="uploads.recipients" :people="people" class="mt-2" />
                            <p v-if="uploads.errors.recipients" class="error">{{ uploads.errors.recipients }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </ConfirmActionModal>

        <ConfirmActionModal
            :open="renaming !== null"
            :processing="renameForm.processing"
            title="Rename"
            confirm-label="Save"
            icon="fa-solid fa-pen"
            @close="renaming = null"
            @confirm="submitRename">
            <label class="block text-left">
                <span class="required-label text-sm font-semibold text-slate-700">Name</span>
                <input v-model="renameForm.name" class="field" :class="{ 'border-rose-500': renameForm.errors.name }" />
                <p v-if="renameForm.errors.name" class="error">{{ renameForm.errors.name }}</p>
            </label>
        </ConfirmActionModal>

        <ConfirmActionModal
            :open="changingPrivacy !== null"
            :processing="privacyForm.processing"
            title="Change privacy"
            confirm-label="Save"
            icon="fa-solid fa-lock"
            wide
            @close="changingPrivacy = null"
            @confirm="submitPrivacy">
            <div class="space-y-3 text-left">
                <label v-for="option in visibilities" :key="option.value" class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition" :class="privacyForm.visibility === option.value ? 'border-[#00aeef] bg-sky-50' : 'border-slate-300 hover:border-sky-300'">
                    <input v-model="privacyForm.visibility" type="radio" :value="option.value" class="mt-1 accent-[#07559e]" />
                    <span>
                        <span class="block font-bold text-slate-800">{{ option.label }}</span>
                        <span class="mt-1 block text-sm text-slate-600">{{ option.description }}</span>
                    </span>
                </label>
                <p v-if="privacyForm.errors.visibility" class="error">{{ privacyForm.errors.visibility }}</p>
            </div>
        </ConfirmActionModal>
    </AdminShell>
`,
});
