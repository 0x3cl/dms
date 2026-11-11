import { Head, Link, router } from '@inertiajs/vue3';
import { defineComponent, reactive } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';
import IndexControls from '../../components/IndexControls';
import Pagination from '../../components/Pagination';
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
    components: { AdminShell, Card, Head, IndexControls, Link, Pagination },
    props: {
        downloads: { type: Object, required: true },
        filters: { type: Object, required: true },
    },
    setup(props) {
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

        return { filters, formatTimestamp, page, search };
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
            <div class="mt-6 overflow-x-auto">
                <table class="w-full min-w-[1000px] text-left">
                    <thead class="border-y border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-4">When</th>
                            <th class="px-4 py-4">Document</th>
                            <th class="px-4 py-4">Tracking code</th>
                            <th class="px-4 py-4">Taken by</th>
                            <th class="px-4 py-4">Division</th>
                            <th class="px-4 py-4">Section</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="download in downloads.data" :key="download.id" class="border-b border-slate-100 hover:bg-sky-50/50">
                            <td class="px-4 py-4 text-slate-600">{{ formatTimestamp(download.downloaded_at ?? download.created_at) }}</td>
                            <td class="px-4 py-4 font-bold text-slate-800">
                                <Link v-if="download.document" :href="'/documents/view/' + download.document.code" class="hover:text-[#07559e]">{{ download.document.title }}</Link>
                                <span v-else>Deleted document</span>
                            </td>
                            <td class="px-4 py-4 font-mono text-xs text-slate-600">{{ download.document?.code ?? '—' }}</td>
                            <!-- Snapshotted on the row, so the register still
                                 answers "who took a copy" after the account is
                                 deleted. -->
                            <td class="px-4 py-4 text-slate-600">{{ download.user_name }}<span v-if="download.user_employee_id" class="block text-xs text-slate-400">{{ download.user_employee_id }}</span></td>
                            <td class="px-4 py-4 text-slate-600">{{ download.division_name ?? '—' }}</td>
                            <td class="px-4 py-4 text-slate-600">{{ download.section_name ?? '—' }}</td>
                        </tr>
                        <tr v-if="downloads.data.length === 0">
                            <td colspan="6" class="px-4 py-12 text-center text-slate-500">No downloads have been recorded yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :pagination="downloads" @page="page" />
        </Card>
    </AdminShell>
`,
});
