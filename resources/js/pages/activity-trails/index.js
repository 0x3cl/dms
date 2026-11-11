import { Head, router } from '@inertiajs/vue3';
import { defineComponent, reactive } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';
import IndexControls from '../../components/IndexControls';
import Pagination from '../../components/Pagination';
import { formatTimestamp } from '../../utils/format-date';

export default defineComponent({
    name: 'ActivityTrails',
    components: { AdminShell, Card, Head, IndexControls, Pagination },
    props: {
        entries: { type: Object, required: true },
        actions: { type: Array, required: true },
        filters: { type: Object, required: true },
    },
    setup(props) {
        const filters = reactive({ ...props.filters });
        let searchTimer;

        const load = () => router.get('/activity-trails', filters, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
        const search = () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(load, 350);
        };
        const page = (url) => url && router.get(url, {}, { preserveScroll: true });

        // The context column shows only what reads usefully in a row; the rest
        // is in the payload for anyone querying the table directly.
        const summarise = (entry) => {
            const context = entry.context ?? {};
            const parts = [];
            if (context.grantee) parts.push(`to ${context.grantee}`);
            if (context.ability) parts.push(context.ability);
            if (context.code) parts.push(context.code);
            if (context.from && context.to) parts.push(`${context.from} → ${context.to}`);
            return parts.join(' · ');
        };

        return { filters, formatTimestamp, load, page, search, summarise };
    },
    template: `
    <Head title="Activity Trail" />
    <AdminShell active="activity-trails" title="Activity Trail">
        <Card>
            <div>
                <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Reports</p>
                <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">File and folder activity</h2>
                <p class="mt-1 text-slate-600">Every upload, preview, download, copy, move, deletion, extraction and share. Each entry records who acted and which office they sat in at the time, so it still reads correctly after a rename or a departure.</p>
            </div>
            <div class="mt-7">
                <IndexControls
                    v-model:entries="filters.entries"
                    v-model:search="filters.search"
                    search-placeholder="Search by person, employee ID or item"
                    :filter-columns="4"
                    @search="search">
                    <template #filters>
                        <label>
                            <span>Action</span>
                            <select v-model="filters.action" class="rounded-lg border border-slate-300 px-3 py-2" @change="load">
                                <option value="">Any action</option>
                                <option v-for="action in actions" :key="action.value" :value="action.value">{{ action.label }}</option>
                            </select>
                        </label>
                        <label>
                            <span>From</span>
                            <input v-model="filters.from" type="date" class="rounded-lg border border-slate-300 px-3 py-2" @change="load" />
                        </label>
                        <label>
                            <span>To</span>
                            <input v-model="filters.to" type="date" class="rounded-lg border border-slate-300 px-3 py-2" @change="load" />
                        </label>
                    </template>
                </IndexControls>
            </div>
            <div class="mt-6 overflow-x-auto">
                <table class="w-full min-w-[1000px] text-left">
                    <thead class="border-y border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-4">When</th>
                            <th class="px-4 py-4">Who</th>
                            <th class="px-4 py-4">Office</th>
                            <th class="px-4 py-4">Action</th>
                            <th class="px-4 py-4">Item</th>
                            <th class="px-4 py-4">Detail</th>
                            <th class="px-4 py-4">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="entry in entries.data" :key="entry.id" class="border-b border-slate-100 hover:bg-sky-50/50">
                            <td class="px-4 py-4 text-slate-600">{{ formatTimestamp(entry.occurred_at) }}</td>
                            <td class="px-4 py-4">
                                <p class="font-bold text-slate-800">{{ entry.actor_name }}</p>
                                <p v-if="entry.actor_employee_id" class="font-mono text-xs text-slate-500">{{ entry.actor_employee_id }}</p>
                            </td>
                            <td class="px-4 py-4 text-slate-600">
                                <span v-if="entry.division_name">{{ entry.division_name }}</span>
                                <span v-if="entry.section_name" class="text-slate-400"> / {{ entry.section_name }}</span>
                                <span v-if="!entry.division_name && !entry.section_name" class="text-slate-400">—</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 font-semibold text-slate-700">
                                <i :class="entry.action_icon" class="mr-2 text-slate-400" aria-hidden="true"></i>{{ entry.action_label }}
                            </td>
                            <td class="px-4 py-4 text-slate-700">{{ entry.subject_label ?? '—' }}</td>
                            <td class="px-4 py-4 text-slate-500">{{ summarise(entry) || '—' }}</td>
                            <td class="px-4 py-4 font-mono text-xs text-slate-500">{{ entry.ip_address ?? '—' }}</td>
                        </tr>
                        <tr v-if="entries.data.length === 0">
                            <td colspan="7" class="px-4 py-12 text-center text-slate-500">No activity matches those filters.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :pagination="entries" @page="page" />
        </Card>
    </AdminShell>
`,
});
