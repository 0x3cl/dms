import { Head, Link, router } from '@inertiajs/vue3';
import { computed, defineComponent, reactive, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import IndexControls from '../../components/IndexControls';
import Pagination from '../../components/Pagination';
import QrScanner from '../../components/QrScanner';
import VisibilityBadge from '../../components/VisibilityBadge';
import { formatDay } from '../../utils/format-date';

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
    components: { AdminShell, Card, ConfirmActionModal, Head, IndexControls, Link, Pagination, QrScanner, VisibilityBadge },
    props: {
        documents: { type: Object, required: true },
        types: { type: Array, default: () => [] },
        filters: { type: Object, required: true },
        counts: { type: Object, required: true },
        can: { type: Object, required: true },
    },
    setup(props) {
        const filters = reactive({ ...props.filters });
        let searchTimer;

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
        const tracking = ref(false);
        const method = ref(null);
        const code = ref('');
        const scanned = ref('');

        const openTracking = () => {
            method.value = null;
            code.value = '';
            scanned.value = '';
            tracking.value = true;
        };
        const closeTracking = () => {
            tracking.value = false;
            // Unmounts QrScanner, which stops the camera in onBeforeUnmount.
            method.value = null;
        };
        const trackByCode = () => {
            if (code.value.trim() === '') return;
            closeTracking();
            router.get('/documents/track', { code: code.value.trim() });
        };

        /**
         * A scan of one of our own labels is a full tracking URL; anything else
         * is treated as a bare code. Both normalise to the same visit, which is
         * what makes a label printed by another system still work.
         */
        const trackByScan = (decodedText) => {
            scanned.value = decodedText;
            closeTracking();

            try {
                const url = new URL(decodedText);

                if (url.origin === window.location.origin) {
                    router.visit(url.pathname + url.search);

                    return;
                }
            } catch {
                /* Not a URL — fall through to the bare-code path. */
            }

            router.get('/documents/track', { code: decodedText.trim() });
        };

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

        return {
            closeTracking, code, confirmDelete, deleting, filters, formatDay, load, method,
            openTracking, page, processing, scanned, search, switchTab, tabs, trackByCode, trackByScan, tracking,
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
                    <template #filters>
                        <label>
                            <span>Type</span>
                            <select v-model="filters.type" class="rounded-lg border border-slate-300 px-3 py-2" @change="load">
                                <option value="">All types</option>
                                <!-- Filtered by id: the type is a row, so a
                                     renamed type keeps its saved filter. -->
                                <option v-for="type in types" :key="type.id" :value="type.id">{{ type.name }}</option>
                            </select>
                        </label>
                    </template>
                </IndexControls>
            </div>

            <div class="mt-6 overflow-x-auto">
                <table class="w-full min-w-[980px] text-left">
                    <thead class="border-y border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-4">Title</th>
                            <th class="px-4 py-4">Tracking code</th>
                            <th class="px-4 py-4">Type</th>
                            <th v-if="filters.tab !== 'mine'" class="px-4 py-4">Uploader</th>
                            <th v-if="filters.tab === 'all'" class="px-4 py-4">Origin</th>
                            <th class="px-4 py-4">Uploaded</th>
                            <th class="px-4 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="document in documents.data" :key="document.id" class="border-b border-slate-100 hover:bg-sky-50/50">
                            <td class="px-4 py-4">
                                <span class="block font-bold text-slate-800">{{ document.title }}</span>
                                <VisibilityBadge class="mt-1" :visibility="document.visibility" />
                            </td>
                            <td class="px-4 py-4 font-mono text-xs text-slate-600">{{ document.code }}</td>
                            <td class="px-4 py-4 text-slate-600">{{ document.type }}</td>
                            <td v-if="filters.tab !== 'mine'" class="px-4 py-4 text-slate-600">{{ document.user?.name ?? document.uploader_name ?? '—' }}</td>
                            <td v-if="filters.tab === 'all'" class="px-4 py-4 text-slate-600">{{ document.user?.division?.name ?? '—' }}<span v-if="document.user?.section?.name"> · {{ document.user.section.name }}</span></td>
                            <td class="px-4 py-4 text-slate-600">{{ formatDay(document.created_at) }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right">
                                <Link :href="'/documents/view/' + document.code" class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-bold text-[#07559e] hover:bg-sky-100"><i class="fa-solid fa-eye" aria-hidden="true"></i>Open</Link>
                                <template v-if="filters.tab === 'mine'">
                                    <Link :href="'/documents/' + document.id + '/edit'" class="ml-1 inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-bold text-slate-600 hover:bg-slate-100"><i class="fa-solid fa-pen" aria-hidden="true"></i>Edit</Link>
                                    <button type="button" class="ml-1 inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-bold text-rose-600 hover:bg-rose-50" @click="deleting = document"><i class="fa-solid fa-trash" aria-hidden="true"></i>Delete</button>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="documents.data.length === 0">
                            <td colspan="7" class="px-4 py-12 text-center text-slate-500">
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
            Tracking. Two methods behind one button: the code printed beside the
            label, or the label itself. QrScanner is mounted only while that
            method is chosen, so the camera is opened on request and released
            the moment the modal closes.
        -->
        <ConfirmActionModal
            :open="tracking"
            title="Track a document"
            message="Open a document from the tracking code printed beside its QR label, or by scanning the label itself."
            :cancel-label="null"
            confirm-label="Close"
            icon="fa-solid fa-magnifying-glass-location"
            wide
            @close="closeTracking"
            @confirm="closeTracking">
            <div class="text-left">
                <div v-if="method === null" class="grid gap-3 sm:grid-cols-2">
                    <button type="button" class="flex flex-col items-start gap-2 rounded-xl border border-slate-300 p-5 text-left transition hover:border-[#00aeef] hover:bg-sky-50" @click="method = 'code'">
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-sky-100 text-lg text-[#07559e]"><i class="fa-solid fa-keyboard" aria-hidden="true"></i></span>
                        <span class="font-bold uppercase tracking-wide text-slate-800">Tracking code</span>
                        <span class="text-sm text-slate-600">Type or paste the code, e.g. MEMO-20260101-0001.</span>
                    </button>
                    <button type="button" class="flex flex-col items-start gap-2 rounded-xl border border-slate-300 p-5 text-left transition hover:border-[#00aeef] hover:bg-sky-50" @click="method = 'qr'">
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-sky-100 text-lg text-[#07559e]"><i class="fa-solid fa-qrcode" aria-hidden="true"></i></span>
                        <span class="font-bold uppercase tracking-wide text-slate-800">QR code</span>
                        <span class="text-sm text-slate-600">Point the camera at the printed label.</span>
                    </button>
                </div>

                <form v-else-if="method === 'code'" @submit.prevent="trackByCode">
                    <button type="button" class="mb-4 inline-flex items-center gap-2 text-sm font-bold text-[#07559e] hover:text-[#043d78]" @click="method = null"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i>Choose another method</button>
                    <label class="block">
                        <span class="required-label text-sm font-semibold text-slate-700">Tracking code</span>
                        <input data-tour="track-code" v-model="code" class="field" placeholder="e.g. MEMO-20260101-0001" />
                    </label>
                    <button type="submit" class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#00aeef] px-5 py-3 text-sm font-bold uppercase tracking-wide text-white transition hover:bg-[#008dcc]"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>Track document</button>
                </form>

                <div v-else>
                    <button type="button" class="mb-4 inline-flex items-center gap-2 text-sm font-bold text-[#07559e] hover:text-[#043d78]" @click="method = null"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i>Choose another method</button>
                    <QrScanner data-tour="scanner" @scanned="trackByScan" />
                    <p class="mt-3 text-xs text-slate-500">The document opens as soon as its code is read. The camera is released when this closes.</p>
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
    </AdminShell>
`,
});
