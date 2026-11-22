import { Head, Link, router } from '@inertiajs/vue3';
import { defineComponent, reactive, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import IndexControls from '../../components/IndexControls';
import Pagination from '../../components/Pagination';
import RowActions from '../../components/RowActions';

export default defineComponent({
    name: 'SectionsIndex',
    components: { AdminShell, Card, ConfirmActionModal, Head, IndexControls, Link, Pagination, RowActions },
    props: {
        sections: { type: Object, required: true },
        divisions: { type: Array, default: () => [] },
        filters: { type: Object, required: true },
    },
    setup(props) {
        const filters = reactive({ ...props.filters });
        let searchTimer;

        const load = () => router.get('/sections', filters, {
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
            router.delete(`/sections/${deleting.value.id}`, {
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
    <Head title="Sections" />
    <AdminShell active="sections" title="Sections">
        <Card>
            <div>
                <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Directory</p>
                <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">All sections</h2>
                <p class="mt-1 text-slate-600">Each section belongs to one division. Staff accounts are assigned to a section.</p>
            </div>
            <div class="mt-7">
                <IndexControls
                    v-model:entries="filters.entries"
                    v-model:search="filters.search"
                    add-href="/sections/create"
                    add-label="Add section"
                    search-placeholder="Search sections"
                    @search="search">
                    <template #filters>
                        <label>
                            <span>Division</span>
                            <select v-model="filters.division" class="rounded-lg border border-slate-300 px-3 py-2" @change="load">
                                <option :value="null">All divisions</option>
                                <option v-for="division in divisions" :key="division.id" :value="division.id">{{ division.name }}</option>
                            </select>
                        </label>
                    </template>
                </IndexControls>
            </div>
            <div class="mt-6 overflow-x-auto">
                <table class="w-full min-w-[760px] text-left">
                    <thead class="border-y border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-4">Code</th>
                            <th class="px-4 py-4">Section</th>
                            <th class="px-4 py-4">Division</th>
                            <th class="px-4 py-4">Staff</th>
                            <th class="px-4 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="section in sections.data" :key="section.id" class="border-b border-slate-100 hover:bg-sky-50/50">
                            <td class="px-4 py-4 font-mono text-xs font-bold text-slate-500">{{ section.code }}</td>
                            <td class="px-4 py-4 font-bold text-slate-800">{{ section.name }}</td>
                            <td class="px-4 py-4 text-slate-600">{{ section.division?.name ?? '—' }}</td>
                            <td class="px-4 py-4 text-slate-600">{{ section.users_count }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right">
                                <RowActions
                                    :label="'Actions for ' + section.name"
                                    :actions="[
                                        { key: 'edit', label: 'Edit', icon: 'fa-solid fa-pen', tone: 'primary', href: '/sections/' + section.id + '/edit' },
                                        { key: 'delete', label: 'Delete', icon: 'fa-solid fa-trash', tone: 'danger' },
                                    ]"
                                    @select="deleting = section" />
                            </td>
                        </tr>
                        <tr v-if="sections.data.length === 0">
                            <td colspan="5" class="px-4 py-12 text-center text-slate-500">No sections match the selected filters.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :pagination="sections" @page="page" />
        </Card>

        <ConfirmActionModal
            :open="deleting !== null"
            :processing="deleteProcessing"
            title="Delete this section?"
            :message="'Deleting ' + (deleting?.name ?? '') + ' cannot be undone.'"
            confirm-label="Delete"
            tone="danger"
            icon="fa-solid fa-trash"
            @close="deleting = null"
            @confirm="confirmDelete" />
    </AdminShell>
`,
});
