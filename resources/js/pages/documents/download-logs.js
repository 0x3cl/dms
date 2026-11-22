import { Head, Link, router } from '@inertiajs/vue3';
import { defineComponent, reactive, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';
import IndexControls from '../../components/IndexControls';
import Pagination from '../../components/Pagination';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import RowActions from '../../components/RowActions';
import { formatTimestamp } from '../../utils/format-date';

/**
 * Who took a copy of which document.
 *
 * This report was permanently empty: nothing wrote to `document_downloads` and
 * the view read `$log->department` / `$log->section`, columns that do not
 * exist. DocumentController now writes a row on every download, and the
 * division and section come through their relations.
 */
export default defineComponent({
    name: 'DownloadLogs',
    components: { AdminShell, Card, ConfirmActionModal, Head, IndexControls, Link, Pagination, RowActions },
    props: {
        downloads: { type: Object, required: true },
        filters: { type: Object, required: true },
    },
    setup(props) {
        const inspecting = ref(null);

        const filters = reactive({ ...props.filters });
        let searchTimer;

        const load = () => router.get('/admin/download-logs', filters, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
        const search = () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(load, 350);
        };
        const page = (url) => url && router.get(url, {}, { preserveScroll: true });

        return { inspecting, filters, formatTimestamp, page, search };
    },
    template: `
    <Head title="Download Logs" />
    <AdminShell active="download-logs" title="Download Logs">
        <Card>
            <div>
                <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Reports</p>
                <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">Download history</h2>
                <p class="mt-1 text-slate-600">Every time a document's file left the server, and who took it.</p>
            </div>
            <div class="mt-7">
                <IndexControls
                    v-model:entries="filters.entries"
                    v-model:search="filters.search"
                    search-placeholder="Search document or person"
                    @search="search" />
            </div>
            <!-- Division and Section were columns and are now in the detail
                 modal, with the IP beside them — which is the one value this
                 report exists for and the reason it has a permission of its
                 own, yet was on no screen at all. -->
            <div class="mt-6">
                <table class="w-full text-left">
                    <thead class="border-y border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-4">When</th>
                            <th class="px-4 py-4">Document</th>
                            <th class="px-4 py-4">Taken by</th>
                            <th class="px-4 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="download in downloads.data" :key="download.id" class="border-b border-slate-100 hover:bg-sky-50/50">
                            <td class="px-4 py-4 text-slate-600">{{ formatTimestamp(download.downloaded_at ?? download.created_at) }}</td>
                            <!-- Capped for the same reason as the register's
                                 own title column: a free-text title that does
                                 not wrap pushes the IP, the person and the
                                 time off the right of the table. -->
                            <td class="max-w-[14rem] px-4 py-4 font-bold text-slate-800 sm:max-w-[20rem] lg:max-w-[28rem]">
                                <Link v-if="download.document" :href="'/documents/view/' + download.document.code" class="block truncate hover:text-[#07559e]" :title="download.document.title">{{ download.document.title }}</Link>
                                <span v-else>Deleted document</span>
                                <span class="block font-mono text-xs font-normal text-slate-500">{{ download.document?.code ?? '—' }}</span>
                            </td>
                            <!-- Snapshotted on the row, so the register still
                                 answers "who took a copy" after the account is
                                 deleted. -->
                            <td class="px-4 py-4 text-slate-600">{{ download.user_name }}</td>
                            <td class="px-4 py-4">
                                <RowActions
                                    :label="'Actions for ' + (download.document?.title ?? 'this release')"
                                    :actions="[{ key: 'view', label: 'View full record', icon: 'fa-solid fa-eye', tone: 'primary' }]"
                                    @select="inspecting = download" />
                            </td>
                        </tr>
                        <tr v-if="downloads.data.length === 0">
                            <td colspan="4" class="px-4 py-12 text-center text-slate-500">No downloads have been recorded yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :pagination="downloads" @page="page" />
        </Card>

        <ConfirmActionModal
            :open="Boolean(inspecting)"
            title="Copy released"
            :cancel-label="null"
            confirm-label="Close"
            icon="fa-solid fa-download"
            wide
            @close="inspecting = null"
            @confirm="inspecting = null">
            <div v-if="inspecting" class="text-left">
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Document</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">
                            {{ inspecting.document?.title ?? 'Deleted document' }}
                            <span class="block font-mono text-xs font-normal text-slate-500">{{ inspecting.document?.code ?? '—' }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Taken by</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">
                            {{ inspecting.user_name }}
                            <span v-if="inspecting.user_employee_id" class="block font-mono text-xs font-normal text-slate-500">{{ inspecting.user_employee_id }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">When</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">{{ formatTimestamp(inspecting.downloaded_at ?? inspecting.created_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Division</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">{{ inspecting.division_name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Section</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">{{ inspecting.section_name ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">IP address</dt>
                        <dd class="mt-1 font-mono text-sm font-semibold text-slate-800">{{ inspecting.ip_address ?? '—' }}</dd>
                        <p class="mt-1 text-xs text-slate-500">Where this copy went. It is why this report has a permission of its own.</p>
                    </div>
                </dl>
            </div>
        </ConfirmActionModal>
    </AdminShell>
`,
});
