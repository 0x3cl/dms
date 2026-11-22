import { Head, router } from '@inertiajs/vue3';
import { defineComponent, reactive, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';
import IndexControls from '../../components/IndexControls';
import Pagination from '../../components/Pagination';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import RowActions from '../../components/RowActions';
import useLiveTrail from '../../composables/useLiveTrail';
import { formatTimestamp } from '../../utils/format-date';

export default defineComponent({
    name: 'ActivityTrails',
    components: { AdminShell, Card, ConfirmActionModal, Head, IndexControls, Pagination, RowActions },
    props: {
        entries: { type: Object, required: true },
        actions: { type: Array, required: true },
        filters: { type: Object, required: true },
    },
    setup(props) {
        const inspecting = ref(null);

        /*
         * Live, when a websocket is available.
         *
         * This report reaches every account's storage and is heavily
         * filtered, so it reloads only for an unfiltered viewer on page one —
         * the one case where a new row definitely belongs at the top of what
         * they are looking at. Everyone else gets the pill.
         */
        const { pending: liveEntries, refresh: refreshEntries } = useLiveTrail('activity-trails', {
            prop: ['entries'],
            isLive: () => (props.entries.current_page ?? 1) === 1
                && Object.values(filters).every((value) => value === '' || value === null || value === undefined),
        });

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

        return { inspecting, liveEntries, refreshEntries, filters, formatTimestamp, load, page, search, summarise };
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
            <!-- Office, Detail and IP were columns, which is what forced the
                 min-w-[1000px]. They are in the detail modal now: the first two
                 repeat down the page and the IP is the one value nobody scans
                 a list for, but every value somebody opens a row to check. -->
            <button
                v-if="liveEntries"
                type="button"
                class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-sky-50 px-4 py-2.5 text-sm font-bold text-[#07559e] transition hover:bg-sky-100"
                @click="refreshEntries">
                <i class="fa-solid fa-arrow-rotate-right" aria-hidden="true"></i>
                {{ liveEntries }} new {{ liveEntries === 1 ? 'entry' : 'entries' }} — refresh
            </button>

            <div class="mt-6">
                <table class="w-full text-left">
                    <thead class="border-y border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-4">When</th>
                            <th class="px-4 py-4">Who</th>
                            <th class="px-4 py-4">Action</th>
                            <th class="px-4 py-4">Item</th>
                            <th class="px-4 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="entry in entries.data" :key="entry.id" class="border-b border-slate-100 hover:bg-sky-50/50">
                            <td class="px-4 py-4 text-slate-600">{{ formatTimestamp(entry.occurred_at) }}</td>
                            <td class="px-4 py-4 font-bold text-slate-800">{{ entry.actor_name }}</td>
                            <td class="whitespace-nowrap px-4 py-4 font-semibold text-slate-700">
                                <i :class="entry.action_icon" class="mr-2 text-slate-400" aria-hidden="true"></i>{{ entry.action_label }}
                            </td>
                            <td class="px-4 py-4 text-slate-700">{{ entry.subject_label ?? '—' }}</td>
                            <td class="px-4 py-4">
                                <RowActions
                                    :label="'Actions for ' + entry.action_label"
                                    :actions="[{ key: 'view', label: 'View full entry', icon: 'fa-solid fa-eye', tone: 'primary' }]"
                                    @select="inspecting = entry" />
                            </td>
                        </tr>
                        <tr v-if="entries.data.length === 0">
                            <td colspan="5" class="px-4 py-12 text-center text-slate-500">No activity matches those filters.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :pagination="entries" @page="page" />
        </Card>

        <!-- The office, the detail and the IP a copy went to. The snapshots are
             what was true when it happened, not what the org chart says now. -->
        <ConfirmActionModal
            :open="Boolean(inspecting)"
            :title="inspecting ? inspecting.action_label : 'Activity'"
            :cancel-label="null"
            confirm-label="Close"
            :icon="inspecting ? inspecting.action_icon : 'fa-solid fa-clock-rotate-left'"
            wide
            @close="inspecting = null"
            @confirm="inspecting = null">
            <div v-if="inspecting" class="text-left">
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Who</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">
                            {{ inspecting.actor_name }}
                            <span v-if="inspecting.actor_employee_id" class="block font-mono text-xs font-normal text-slate-500">{{ inspecting.actor_employee_id }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">When</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">{{ formatTimestamp(inspecting.occurred_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Office</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">
                            <span v-if="inspecting.division_name">{{ inspecting.division_name }}</span>
                            <span v-if="inspecting.section_name" class="text-slate-500"> / {{ inspecting.section_name }}</span>
                            <span v-if="!inspecting.division_name && !inspecting.section_name">—</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">IP address</dt>
                        <dd class="mt-1 font-mono text-sm font-semibold text-slate-800">{{ inspecting.ip_address ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Item</dt>
                        <dd class="mt-1 break-words text-sm font-semibold text-slate-800">{{ inspecting.subject_label ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Detail</dt>
                        <dd class="mt-1 break-words text-sm text-slate-700">{{ summarise(inspecting) || '—' }}</dd>
                    </div>
                </dl>
            </div>
        </ConfirmActionModal>
    </AdminShell>
`,
});
