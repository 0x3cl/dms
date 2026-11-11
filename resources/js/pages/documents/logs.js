import { Head, Link, router } from '@inertiajs/vue3';
import { defineComponent } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';
import Pagination from '../../components/Pagination';
import { formatTimestamp } from '../../utils/format-date';

/**
 * A document's full travel history. The route used to return raw JSON that
 * nothing consumed.
 */
export default defineComponent({
    name: 'DocumentLogs',
    components: { AdminShell, Card, Head, Link, Pagination },
    props: {
        document: { type: Object, required: true },
        logs: { type: Object, required: true },
    },
    setup() {
        const page = (url) => url && router.get(url, {}, { preserveScroll: true });

        return { formatTimestamp, page };
    },
    template: `
    <Head :title="'History — ' + document.title" />
    <AdminShell active="documents" :title="'History — ' + document.title">
        <Card>
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-5">
                <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#07559e]">Audit trail</p>
                    <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">Tracking history</h2>
                    <p class="mt-2 font-mono text-sm text-slate-600">{{ document.code }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <Link :href="'/documents/view/' + document.code" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 hover:border-[#07559e] hover:text-[#07559e]"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back</Link>
                </div>
            </div>

            <div class="mt-6 overflow-x-auto">
                <table class="w-full min-w-[900px] text-left">
                    <thead class="border-y border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-4">When</th>
                            <th class="px-4 py-4">Action</th>
                            <th class="px-4 py-4">By</th>
                            <th class="px-4 py-4">Division</th>
                            <th class="px-4 py-4">Section</th>
                            <th class="px-4 py-4">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="log in logs.data" :key="log.id" class="border-b border-slate-100 hover:bg-sky-50/50">
                            <td class="px-4 py-4 text-slate-600">{{ formatTimestamp(log.occurred_at ?? log.created_at) }}</td>
                            <td class="px-4 py-4 font-bold text-slate-800"><i :class="log.action_icon" class="mr-2 text-slate-400" aria-hidden="true"></i>{{ log.action_label }}</td>
                            <!-- The actor and their office are read off the row,
                                 not joined: each entry snapshots who they were
                                 at the time, so a later rename cannot rewrite
                                 where this document has been. -->
                            <td class="px-4 py-4 text-slate-600">{{ log.actor_name }}</td>
                            <td class="px-4 py-4 text-slate-600">{{ log.division_name ?? '—' }}</td>
                            <td class="px-4 py-4 text-slate-600">{{ log.section_name ?? '—' }}</td>
                            <td class="whitespace-normal px-4 py-4 text-slate-600">{{ log.remarks ?? '—' }}</td>
                        </tr>
                        <tr v-if="logs.data.length === 0">
                            <td colspan="6" class="px-4 py-12 text-center text-slate-500">Nothing has been logged against this document yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :pagination="logs" @page="page" />
        </Card>
    </AdminShell>
`,
});
