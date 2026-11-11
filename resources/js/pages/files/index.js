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
import FileTable from '../../components/FileTable';
import FolderPickerModal from '../../components/FolderPickerModal';
import IndexControls from '../../components/IndexControls';
import Pagination from '../../components/Pagination';
import RecipientPicker from '../../components/RecipientPicker';
import ShareDrawer from '../../components/ShareDrawer';
import ViewToggle from '../../components/ViewToggle';

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
    components: {
        AdminShell, Card, ConfirmActionModal, ConfirmDeleteModal, FileBreadcrumbs,
        FileFilterModal, FileGrid, FilePreviewModal, FileTable, FolderPickerModal,
        Head, IndexControls, Pagination, RecipientPicker, ShareDrawer, ViewToggle,
    },
    props: {
        files: { type: Object, required: true },
        folders: { type: Array, required: true },
        folder: { type: Object, required: true },
        breadcrumbs: { type: Array, required: true },
        filters: { type: Object, required: true },
        entriesOptions: { type: Array, required: true },
        visibilities: { type: Array, required: true },
        categories: { type: Array, required: true },
        owners: { type: Array, default: () => [] },
        people: { type: Array, default: () => [] },
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
        const selectedRows = computed(() => props.files.data
            .filter((file) => selected.value.includes(file.public_id))
            .map((file) => ({ kind: 'file', public_id: file.public_id, name: file.name })));

        // ------------------------------------------------------------ preview
        /*
         * The descriptor is fetched per file rather than shipped with the
         * listing: which viewer a type needs is a server decision, and for an
         * Office document it means minting a URL that is live for minutes.
         * Forty cards in a folder would otherwise mint forty of them on every
         * navigation, all but one unused.
         */
        const previewIndex = ref(-1);
        const previewFile = ref(null);
        const previewable = computed(() => props.files.data.filter((file) => file.can.view));

        const describe = async (file) => {
            previewFile.value = null;

            try {
                const response = await fetch(`/files/preview?owner=${file.owner_public_id}&file=${file.public_id}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                previewFile.value = response.ok
                    ? await response.json()
                    : { mode: 'none', name: file.name, can_download: false };
            } catch {
                previewFile.value = { mode: 'none', name: file.name, can_download: false };
            }
        };

        const openPreview = (file) => {
            previewIndex.value = previewable.value.findIndex((candidate) => candidate.public_id === file.public_id);
            describe(file);
        };
        const closePreview = () => { previewIndex.value = -1; previewFile.value = null; };
        const navigatePreview = (step) => {
            const next = previewIndex.value + step;

            if (next < 0 || next >= previewable.value.length) return;

            previewIndex.value = next;
            describe(previewable.value[next]);
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

        const uploads = useForm({ folder: props.folder.public_id, files: [] });
        const uploading = ref(false);
        const submitUpload = () => uploads.post('/files/upload', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => { uploading.value = false; uploads.reset('files'); },
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
        const confirmDelete = (code) => {
            deleteProcessing.value = true;
            router.post('/files/delete', {
                items: deleting.value.items.map(({ kind, public_id: publicId }) => ({ kind, public_id: publicId })),
                confirmation: code,
            }, {
                preserveScroll: true,
                onError: (errors) => {
                    deleteError.value = errors.confirmation ?? errors.item ?? 'That could not be deleted.';
                },
                onSuccess: () => { deleting.value = null; clearSelection(); },
                onFinish: () => { deleteProcessing.value = false; },
            });
        };

        /** The kebab menu is data, so one handler fans out to the modals. */
        const act = ({ item, kind, action }) => {
            const row = { kind, public_id: item.public_id, name: item.name };

            if (action === 'preview') { openPreview(item); return; }
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
            act, activeFilters, applyFilters, changingPrivacy, clearSelection, closePreview, confirmDelete, openFolderDialog,
            confirmRelocate, creatingFolder, deleteError, deleteProcessing, deleting, downloadSelection,
            filtering, filters, folderHref, load, navigatePreview, newFolder, onOpen, openPreview, page,
            previewFile, previewIndex, previewable, privacyForm, relocateError, relocateProcessing, relocating,
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
                    <button v-if="can.upload && folder.can_write" type="button" class="inline-flex items-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8]" data-tour="files-upload" @click="uploading = true">
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
                @preview="openPreview"
                @sort="sortBy" />

            <Pagination :pagination="files" @page="page" />
        </Card>

        <FilePreviewModal
            :open="previewIndex >= 0"
            :file="previewFile"
            :has-previous="previewIndex > 0"
            :has-next="previewIndex >= 0 && previewIndex < previewable.length - 1"
            @close="closePreview"
            @navigate="navigatePreview" />

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
            <label class="block text-left">
                <span class="required-label text-sm font-semibold text-slate-700">Choose files</span>
                <input type="file" multiple class="field file:mr-4 file:rounded-lg file:border-0 file:bg-sky-100 file:px-4 file:py-2 file:text-sm file:font-bold file:text-[#07559e]" @input="uploads.files = Array.from($event.target.files)" />
                <p v-if="uploads.errors.files" class="error">{{ uploads.errors.files }}</p>
                <p v-if="uploads.errors['files.0']" class="error">{{ uploads.errors['files.0'] }}</p>
            </label>
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
