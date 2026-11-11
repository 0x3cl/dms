import { Head, Link, router } from '@inertiajs/vue3';
import { defineComponent, reactive, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import IndexControls from '../../components/IndexControls';
import Pagination from '../../components/Pagination';

export default defineComponent({
    name: 'DivisionsIndex',
    components: { AdminShell, Card, ConfirmActionModal, Head, IndexControls, Link, Pagination },
    props: {
        divisions: { type: Object, required: true },
        filters: { type: Object, required: true },
    },
    setup(props) {
        const filters = reactive({ ...props.filters });
        let searchTimer;

        const load = () => router.get('/divisions', filters, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
        const search = () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(load, 350);
        };
        const page = (url) => url && router.get(url, {}, { preserveScroll: true });

        const deleting = ref(null);
        const deleteProcessing = ref(false);
        const confirmDelete = () => {
            deleteProcessing.value = true;
            router.delete(`/divisions/${deleting.value.id}`, {
                preserveScroll: true,
                onFinish: () => {
                    deleteProcessing.value = false;
                    deleting.value = null;
                },
            });
        };

        return { confirmDelete, deleteProcessing, deleting, filters, load, page, search };
    },
    template: `
    <Head title="Divisions" />
    <AdminShell active="divisions" title="Divisions">
        <Card>
            <div>
                <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Directory</p>
                <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">All divisions</h2>
                <p class="mt-1 text-slate-600">The top level of the office structure. Each division holds the sections staff are assigned to.</p>
            </div>
            <div class="mt-7">
                <IndexControls
                    v-model:entries="filters.entries"
                    v-model:search="filters.search"
                    add-href="/divisions/create"
                    add-label="Add division"
                    search-placeholder="Search divisions by name or code"
                    @search="search" />
            </div>
            <div class="mt-6 overflow-x-auto">
                <table class="w-full min-w-[700px] text-left">
                    <thead class="border-y border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-4">Code</th>
                            <th class="px-4 py-4">Division</th>
                            <th class="px-4 py-4">Sections</th>
                            <th class="px-4 py-4">Staff</th>
                            <th class="px-4 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="division in divisions.data" :key="division.id" class="border-b border-slate-100 hover:bg-sky-50/50">
                            <td class="px-4 py-4 font-mono text-xs font-bold text-slate-500">{{ division.code }}</td>
                            <td class="px-4 py-4 font-bold text-slate-800">{{ division.name }}</td>
                            <td class="px-4 py-4 text-slate-600">{{ division.sections_count }}</td>
                            <td class="px-4 py-4 text-slate-600">{{ division.users_count }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right">
                                <Link :href="'/divisions/' + division.id + '/edit'" class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-bold text-[#07559e] hover:bg-sky-100"><i class="fa-solid fa-pen" aria-hidden="true"></i>Edit</Link>
                                <button type="button" class="ml-2 inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-bold text-rose-600 hover:bg-rose-50" @click="deleting = division"><i class="fa-solid fa-trash" aria-hidden="true"></i>Delete</button>
                            </td>
                        </tr>
                        <tr v-if="divisions.data.length === 0">
                            <td colspan="5" class="px-4 py-12 text-center text-slate-500">No divisions match that search.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :pagination="divisions" @page="page" />
        </Card>

        <ConfirmActionModal
            :open="deleting !== null"
            :processing="deleteProcessing"
            title="Delete this division?"
            :message="'Deleting ' + (deleting?.name ?? '') + ' cannot be undone.'"
            confirm-label="Delete"
            tone="danger"
            icon="fa-solid fa-trash"
            @close="deleting = null"
            @confirm="confirmDelete" />
    </AdminShell>
`,
});
