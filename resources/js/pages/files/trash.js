import { Head, router } from '@inertiajs/vue3';
import { computed, defineComponent, reactive, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import ConfirmDeleteModal from '../../components/ConfirmDeleteModal';
import FileBreadcrumbs from '../../components/FileBreadcrumbs';
import FileGrid from '../../components/FileGrid';
import FileTable from '../../components/FileTable';
import IndexControls from '../../components/IndexControls';
import Pagination from '../../components/Pagination';
import ViewToggle from '../../components/ViewToggle';

/**
 * Soft-deleted items, and the two ways out of here.
 *
 * Deleting is reversible on purpose: `App\Services\Files\FileManager::delete()`
 * soft-deletes a folder's whole subtree in one transaction and writes an audit
 * row per subject, which is why `folders.parent_id` is `restrictOnDelete` — a
 * database cascade would hard-delete descendants with no record and no way
 * back.
 *
 * Three things make this a real screen rather than a list of regrets.
 *
 * **It is navigable.** Deleting a folder trashes everything under it, so a flat
 * list showed one act as a hundred rows with no way to tell they belonged
 * together. The top level lists what was actually deleted, and a trashed folder
 * opens to show what went with it. Everything stays deleted while it is
 * browsed — these listings are `onlyTrashed()`, so nothing here is reachable by
 * its ordinary route and nothing is restored by looking at it.
 *
 * **It has both views.** The same FileGrid and FileTable the rest of the file
 * manager uses, in `mode="trash"`, which is what keeps the grid and the table
 * from being two screens that drift.
 *
 * **Restoring says where it will land.** An item's original folder may itself
 * be in the trash — the normal case — or may have been purged since, so each
 * row carries `restores_to`, and the one that cannot go home says so before it
 * is clicked rather than after.
 */
export default defineComponent({
    name: 'FilesTrash',
    components: {
        AdminShell, Card, ConfirmActionModal, ConfirmDeleteModal, FileBreadcrumbs,
        FileGrid, FileTable, Head, IndexControls, Pagination, ViewToggle,
    },
    props: {
        files: { type: Object, required: true },
        folders: { type: Array, required: true },
        /**
         * Tracked documents the viewer has withdrawn.
         *
         * They are here because the Trash is the screen named after the
         * question "I deleted something, where is it" — a soft-deleted thing
         * that appears nowhere on it is a thing people hunt for. Not files:
         * withdrawing a document does not delete its bytes, which is exactly
         * what the row says.
         */
        documents: { type: Array, default: () => [] },
        folder: { type: Object, default: null },
        breadcrumbs: { type: Array, default: () => [] },
        filters: { type: Object, required: true },
        entriesOptions: { type: Array, required: true },
        can: { type: Object, default: () => ({}) },
    },
    setup(props) {
        const filters = reactive({ ...props.filters });
        let searchTimer;

        const load = () => router.get('/files/trash', filters, {
            preserveScroll: true, preserveState: true, replace: true,
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

        // Navigation inside the trash keeps the view, so opening a deleted
        // folder does not throw you back to the grid.
        const folderHref = (publicId) => `/files/trash?folder=${publicId}&view=${filters.view}`;

        // -------------------------------------------- withdrawn documents
        /*
         * Restoring a document is the documents module's own route, posted
         * from here rather than reimplemented: this screen lists them, it
         * does not own them.
         */
        const restoringDocument = ref(null);
        const documentProcessing = ref(false);
        const confirmRestoreDocument = () => {
            documentProcessing.value = true;
            router.post(`/documents/${restoringDocument.value.id}/restore`, {}, {
                preserveScroll: true,
                onFinish: () => {
                    documentProcessing.value = false;
                    restoringDocument.value = null;
                },
            });
        };

        // ------------------------------------------------------------ restore
        const restoring = ref(null);
        const restoreProcessing = ref(false);
        const confirmRestore = () => {
            restoreProcessing.value = true;
            router.post('/files/restore', {
                kind: restoring.value.kind,
                public_id: restoring.value.item.public_id,
            }, {
                preserveScroll: true,
                onFinish: () => { restoreProcessing.value = false; restoring.value = null; },
            });
        };

        const restoreMessage = computed(() => {
            const item = restoring.value?.item;

            if (!item) return '';

            return item.restores_home
                ? `${item.name} came from a folder that no longer exists, so it will be restored into your storage root.`
                : `${item.name} will be restored to ${item.restores_to}.`;
        });

        // --------------------------------------------------------------- purge
        const purging = ref(null);
        const purgeProcessing = ref(false);
        const purgeError = ref('');
        const startPurge = (items) => {
            purgeError.value = '';
            purging.value = { items };
        };
        const confirmPurge = (code) => {
            purgeProcessing.value = true;
            router.post('/files/purge', {
                items: purging.value.items.map(({ kind, public_id: publicId }) => ({ kind, public_id: publicId })),
                confirmation: code,
            }, {
                preserveScroll: true,
                onError: (errors) => {
                    purgeError.value = errors.confirmation ?? errors.item ?? 'That could not be deleted.';
                },
                onSuccess: () => { purging.value = null; },
                onFinish: () => { purgeProcessing.value = false; },
            });
        };

        const act = ({ item, kind, action }) => {
            // A document is restored through the documents module's own
            // route; everything else goes to /files/restore.
            if (kind === 'document') { restoringDocument.value = item; return; }

            if (action === 'restore') { restoring.value = { item, kind }; return; }
            if (action === 'purge') { startPurge([{ kind, public_id: item.public_id, name: item.name }]); }
        };

        return {
            act, confirmPurge, confirmRestore, filters, folderHref, load, page, purgeError,
            purgeProcessing, purging, restoreMessage, restoreProcessing, restoring, search, setView, sortBy,
            confirmRestoreDocument, documentProcessing, restoringDocument,
        };
    },
    template: `
    <Head title="Trash" />
    <AdminShell active="files-trash" title="Trash">
        <Card>
            <div>
                <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Storage</p>
                <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">{{ folder ? folder.name : 'Trash' }}</h2>
                <p v-if="folder" class="mt-1 text-slate-600">Inside a deleted folder. Everything here is still deleted — restoring the folder brings all of it back together.</p>
                <p v-else class="mt-1 text-slate-600">Everything you have deleted — files, folders and withdrawn documents. Open a folder to see what went with it, restore what you need, or delete it for good.</p>
            </div>

            <div class="mt-5">
                <FileBreadcrumbs root-href="/files/trash" root-label="Trash" :crumbs="breadcrumbs" />
            </div>

            <div class="mt-5 flex flex-wrap items-center gap-3">
                <div class="min-w-0 flex-1">
                    <IndexControls
                        v-model:entries="filters.entries"
                        v-model:search="filters.search"
                        :entries-options="entriesOptions"
                        search-placeholder="Search trash"
                        @search="search" />
                </div>
                <ViewToggle :model-value="filters.view" @update:model-value="setView" />
            </div>

            <!-- Documents go through the same grid and table as the files:
                 the trash is one question, and a second list on it reads as a
                 second trash. -->
            <FileGrid
                v-if="filters.view === 'grid'"
                mode="trash"
                :folders="folders"
                :files="files.data"
                :documents="documents"
                :folder-href="folderHref"
                :selectable="false"
                @act="act" />
            <FileTable
                v-else
                mode="trash"
                :folders="folders"
                :files="files.data"
                :documents="documents"
                :folder-href="folderHref"
                :sort="filters.sort"
                :direction="filters.direction"
                :selectable="false"
                @act="act"
                @sort="sortBy" />

            <Pagination :pagination="files" @page="page" />
        </Card>

        <ConfirmActionModal
            :open="restoring !== null"
            :processing="restoreProcessing"
            title="Restore this?"
            :message="restoreMessage"
            confirm-label="Restore"
            icon="fa-solid fa-rotate-left"
            @close="restoring = null"
            @confirm="confirmRestore" />

        <!--
            Confirmed like any restore here, and it states the consequence
            rather than asking "are you sure": the document goes back under
            the code already printed on paper, and opens again for everybody
            tagged on it — all of whom are off screen.
        -->
        <ConfirmActionModal
            :open="restoringDocument !== null"
            :processing="documentProcessing"
            title="Restore this document?"
            :message="(restoringDocument?.title ?? 'This document') + ' goes back on the register under ' + (restoringDocument?.code ?? 'its tracking code') + ', and opens again for everybody it is shared with.'"
            confirm-label="Restore"
            icon="fa-solid fa-rotate-left"
            @close="restoringDocument = null"
            @confirm="confirmRestoreDocument" />

        <ConfirmDeleteModal
            :open="purging !== null"
            purpose="purge"
            trashed
            :items="purging?.items ?? []"
            :processing="purgeProcessing"
            :error="purgeError"
            @close="purging = null"
            @confirm="confirmPurge" />
    </AdminShell>
`,
});
