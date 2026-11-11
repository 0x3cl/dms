import { Head, Link, router } from '@inertiajs/vue3';
import { defineComponent, reactive, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Badge from '../../components/Badge';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import IndexControls from '../../components/IndexControls';
import Pagination from '../../components/Pagination';

export default defineComponent({
    name: 'DocumentTypesIndex',
    components: { AdminShell, Badge, Card, ConfirmActionModal, Head, IndexControls, Link, Pagination },
    props: {
        types: { type: Object, required: true },
        filters: { type: Object, required: true },
    },
    setup(props) {
        const filters = reactive({ ...props.filters });
        let searchTimer;

        const load = () => router.get('/document-types', filters, {
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
        const processing = ref(false);
        const confirmDelete = () => {
            processing.value = true;
            router.delete(`/document-types/${deleting.value.id}`, {
                preserveScroll: true,
                onFinish: () => { processing.value = false; deleting.value = null; },
            });
        };

        const restore = (type) => router.post(`/document-types/${type.id}/restore`, {}, {
            preserveScroll: true,
        });

        /** Three states, in the order someone reads them: live, retired, deleted. */
        const state = (type) => {
            if (type.deleted_at) return { label: 'Deleted', tone: 'rose' };
            if (!type.is_active) return { label: 'Retired', tone: 'slate' };
            return { label: 'Active', tone: 'emerald' };
        };

        return { confirmDelete, deleting, filters, load, page, processing, restore, search, state };
    },
    template: `
    <Head title="Document Types" />
    <AdminShell active="document-types" title="Document Types">
        <Card>
            <div>
                <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Settings</p>
                <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">Kinds of document</h2>
                <p class="mt-1 text-slate-600">
                    The list the upload form offers, and the abbreviation each one puts at the front of a tracking code —
                    <span class="font-mono text-sm">MEMO</span> in <span class="font-mono text-sm">MEMO-20260110-0001</span>.
                </p>
                <div class="mt-4 rounded-xl bg-sky-50 p-4 text-sm text-slate-700">
                    <p><strong class="font-bold">Retire a type you have stopped using.</strong> It leaves the upload form but stays available for filtering and counting, which is almost always what you want.</p>
                    <p class="mt-2"><strong class="font-bold">Delete a type that should not exist.</strong> Documents already filed under it are untouched — they keep the type they were filed as, and still appear in the register under it.</p>
                </div>
            </div>

            <div class="mt-7">
                <IndexControls
                    v-model:entries="filters.entries"
                    v-model:search="filters.search"
                    add-href="/document-types/create"
                    add-label="Add type"
                    search-placeholder="Search by name or abbreviation"
                    @search="search">
                    <template #filters>
                        <label>
                            <span>Status</span>
                            <select v-model="filters.status" class="rounded-lg border border-slate-300 px-3 py-2" @change="load">
                                <option value="">All</option>
                                <option value="active">Active</option>
                                <option value="retired">Retired</option>
                                <option value="deleted">Deleted</option>
                            </select>
                        </label>
                    </template>
                </IndexControls>
            </div>

            <div class="mt-6 overflow-x-auto">
                <table class="w-full min-w-[920px] text-left">
                    <thead class="border-y border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-4">Code</th>
                            <th class="px-4 py-4">Type</th>
                            <th class="px-4 py-4">Status</th>
                            <th class="px-4 py-4">Documents</th>
                            <th class="px-4 py-4">Order</th>
                            <th class="px-4 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="type in types.data" :key="type.id" class="border-b border-slate-100 hover:bg-sky-50/50">
                            <td class="px-4 py-4 font-mono text-xs font-bold text-slate-500">{{ type.code }}</td>
                            <td class="px-4 py-4">
                                <p class="font-bold text-slate-800">{{ type.name }}</p>
                                <p v-if="type.description" class="mt-0.5 max-w-md whitespace-normal text-xs text-slate-500">{{ type.description }}</p>
                            </td>
                            <td class="px-4 py-4"><Badge :tone="state(type).tone">{{ state(type).label }}</Badge></td>
                            <td class="px-4 py-4 text-slate-600">{{ type.documents_count }}</td>
                            <td class="px-4 py-4 text-slate-600">{{ type.position }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right">
                                <Link v-if="!type.deleted_at" :href="'/document-types/' + type.id + '/edit'" class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-bold text-[#07559e] hover:bg-sky-100"><i class="fa-solid fa-pen" aria-hidden="true"></i>Edit</Link>
                                <button v-if="!type.deleted_at" type="button" class="ml-2 inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-bold text-rose-600 hover:bg-rose-50" @click="deleting = type"><i class="fa-solid fa-trash" aria-hidden="true"></i>Delete</button>
                                <button v-if="type.deleted_at" type="button" class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-bold text-[#07559e] hover:bg-sky-100" @click="restore(type)"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Restore</button>
                            </td>
                        </tr>
                        <tr v-if="types.data.length === 0">
                            <td colspan="6" class="px-4 py-12 text-center text-slate-500">No document types match that search.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :pagination="types" @page="page" />
        </Card>

        <ConfirmActionModal
            :open="deleting !== null"
            :processing="processing"
            title="Delete this document type?"
            :message="deleting?.documents_count
                ? 'The ' + deleting.documents_count + ' document(s) already filed as ' + deleting.name + ' are not affected — they keep this type and stay in the register under it. Only the upload form loses the option.'
                : 'Nothing has been filed under ' + (deleting?.name ?? '') + ' yet.'"
            confirm-label="Delete type"
            tone="danger"
            icon="fa-solid fa-trash"
            @close="deleting = null"
            @confirm="confirmDelete">
            <p class="text-left text-sm text-slate-600">You can restore it afterwards from the Deleted filter.</p>
        </ConfirmActionModal>
    </AdminShell>
`,
});
