import { Head, router } from '@inertiajs/vue3';
import { computed, defineComponent, reactive, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';
import FileBreadcrumbs from '../../components/FileBreadcrumbs';
import FileGrid from '../../components/FileGrid';
import FilePreviewModal from '../../components/FilePreviewModal';
import FileTable from '../../components/FileTable';
import IndexControls from '../../components/IndexControls';
import FolderPickerModal from '../../components/FolderPickerModal';
import Pagination from '../../components/Pagination';
import SearchableSelect from '../../components/SearchableSelect';
import ViewToggle from '../../components/ViewToggle';

/**
 * Other people's storage, for an administrator.
 *
 * The account list is itself scoped: only users with at least one item this
 * person may actually see are offered. So the page cannot be used to enumerate
 * who has storage, let alone what is in it — the picker being empty is the
 * honest answer when nothing has been shared.
 *
 * Gated on `files.manage`, which is the same permission AccessResolver resolves
 * the administrator's reach by, so the screen and the rule cannot be granted
 * apart.
 */
export default defineComponent({
    name: 'FilesBrowse',
    components: {
        AdminShell, Card, FileBreadcrumbs, FileGrid, FilePreviewModal, FileTable,
        FolderPickerModal, Head, IndexControls, Pagination, SearchableSelect, ViewToggle,
    },
    props: {
        owners: { type: Array, required: true },
        owner: { type: Object, default: null },
        files: { type: Object, default: null },
        folders: { type: Array, default: () => [] },
        folder: { type: Object, default: null },
        breadcrumbs: { type: Array, default: () => [] },
        filters: { type: Object, required: true },
        entriesOptions: { type: Array, required: true },
    },
    setup(props) {
        const filters = reactive({ ...props.filters });
        let searchTimer;

        const load = () => router.get('/files/browse', filters, {
            preserveScroll: true, preserveState: true, replace: true,
        });
        const search = () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(load, 350);
        };
        const page = (url) => url && router.get(url, {}, { preserveScroll: true });
        const setView = (view) => { filters.view = view; load(); };
        const chooseOwner = (publicId) => {
            filters.owner = publicId;
            // A folder from the previous owner's tree means nothing in this
            // one's, so it has to be cleared or the page 404s.
            filters.folder = '';
            load();
        };

        const ownerOptions = computed(() => props.owners.map((owner) => ({
            value: owner.public_id,
            label: owner.name,
        })));

        const folderHref = (publicId) => `/files/browse?owner=${filters.owner}&folder=${publicId}&view=${filters.view}`;
        const streamUrl = (file) => `/files/${file.owner_public_id}/${file.public_id}`;

        /*
         * Which viewer a file needs is a server decision — see
         * App\Services\Files\FilePreview — so the descriptor is fetched per
         * file rather than guessed from the mime type here. This page had its
         * own copy of that guess, which is exactly the duplication that let a
         * video show as "no preview" on one screen and play on another.
         */
        const previewIndex = ref(-1);
        const previewFile = ref(null);
        const previewable = computed(() => (props.files?.data ?? []).filter((file) => file.can.view));

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
            previewIndex.value = previewable.value.findIndex((c) => c.public_id === file.public_id);
            describe(file);
        };
        const closePreview = () => { previewIndex.value = -1; previewFile.value = null; };
        const navigatePreview = (step) => {
            const next = previewIndex.value + step;

            if (next < 0 || next >= previewable.value.length) return;

            previewIndex.value = next;
            describe(previewable.value[next]);
        };

        // Taking a copy asks where it should go, the same picker My Files uses.
        // It used to land silently in the taker's root, which is fine exactly
        // once and useless after that.
        const copying = ref(null);
        const copyProcessing = ref(false);
        const copyError = ref('');
        const confirmCopy = (target) => {
            copyProcessing.value = true;
            router.post('/files/copy', {
                target,
                items: copying.value.items.map(({ kind, public_id: publicId }) => ({ kind, public_id: publicId })),
            }, {
                preserveScroll: true,
                onError: (errors) => { copyError.value = errors.target ?? errors.item ?? 'That could not be copied.'; },
                onSuccess: () => { copying.value = null; },
                onFinish: () => { copyProcessing.value = false; },
            });
        };

        const act = ({ item, kind, action }) => {
            if (action === 'preview') { openPreview(item); return; }
            if (action === 'download') { window.location = streamUrl(item); return; }
            if (action === 'copy') {
                copyError.value = '';
                copying.value = { items: [{ kind, public_id: item.public_id, name: item.name }] };
            }
        };

        return {
            act, chooseOwner, closePreview, confirmCopy, copyError, copyProcessing, copying, filters,
            folderHref, load, navigatePreview, openPreview, ownerOptions, page, previewFile,
            previewIndex, previewable, search, setView,
        };
    },
    template: `
    <Head title="Browse Storage" />
    <AdminShell active="files-browse" title="Browse Storage">
        <Card>
            <div>
                <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Storage</p>
                <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">Other people's storage</h2>
                <p class="mt-1 text-slate-600">Only accounts that have shared something with you appear here, and only the items you are allowed to open are listed.</p>
            </div>

            <div class="mt-7 max-w-md">
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Account</span>
                    <SearchableSelect
                        :model-value="filters.owner"
                        :options="ownerOptions"
                        placeholder="Choose an account"
                        search-placeholder="Search accounts"
                        empty-text="Nobody has shared anything with you yet."
                        @update:model-value="chooseOwner" />
                </label>
            </div>

            <div v-if="!owner" class="mt-7 rounded-2xl border border-dashed border-slate-300 px-6 py-16 text-center">
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-slate-100 text-xl text-slate-400" aria-hidden="true"><i class="fa-solid fa-folder-open"></i></span>
                <p class="mt-4 font-bold uppercase tracking-wide text-slate-700">Choose an account</p>
                <p class="mt-1 text-sm text-slate-500">Pick someone above to see what they have shared with you.</p>
            </div>

            <template v-else>
                <div class="mt-5">
                    <FileBreadcrumbs :root-href="'/files/browse?owner=' + filters.owner" :root-label="owner.name" :crumbs="breadcrumbs" />
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

                <FileGrid
                    v-if="filters.view === 'grid'"
                    :folders="folders"
                    :files="files ? files.data : []"
                    :folder-href="folderHref"
                    :selectable="false"
                    @act="act"
                    @preview="openPreview" />
                <FileTable
                    v-else
                    :folders="folders"
                    :files="files ? files.data : []"
                    :folder-href="folderHref"
                    :selectable="false"
                    @act="act"
                    @preview="openPreview" />

                <Pagination v-if="files" :pagination="files" @page="page" />
            </template>
        </Card>

        <FilePreviewModal
            :open="previewIndex >= 0"
            :file="previewFile"
            :has-previous="previewIndex > 0"
            :has-next="previewIndex >= 0 && previewIndex < previewable.length - 1"
            @close="closePreview"
            @navigate="navigatePreview" />

        <FolderPickerModal
            :open="copying !== null"
            action="copy"
            :items="copying?.items ?? []"
            :processing="copyProcessing"
            :error="copyError"
            @close="copying = null"
            @confirm="confirmCopy" />
    </AdminShell>
`,
});
