import { Head, Link, router } from '@inertiajs/vue3';
import { computed, defineComponent, reactive, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Badge from '../../components/Badge';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import IndexControls from '../../components/IndexControls';
import Pagination from '../../components/Pagination';
import RowActions from '../../components/RowActions';

/**
 * The types inside one group.
 *
 * Reached by opening a group from the module's landing, which is the shelf.
 * The set is a path segment rather than a query parameter because it is the
 * thing somebody navigated *to* — a group id, `other` for the ungrouped, or
 * `all`. Search and status stay query parameters, because those really are
 * filters on what is already here.
 *
 * `Add type` carries the group with it, so a type opened from inside a group
 * is already filed there: the question has been answered once, and asking it
 * again is how a type ends up in the wrong place.
 */
export default defineComponent({
    name: 'DocumentTypeGroupTypes',
    components: { AdminShell, Badge, Card, ConfirmActionModal, Head, IndexControls, Link, Pagination, RowActions },
    props: {
        group: { type: Object, required: true },
        types: { type: Object, required: true },
        filters: { type: Object, required: true },
    },
    setup(props) {
        const filters = reactive({ ...props.filters });
        let searchTimer;

        const path = computed(() => `/document-types/group/${props.group.id}`);

        const load = () => router.get(path.value, filters, {
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

        // `all` and `other` are sets, not groups, so a type added from them
        // has no group to inherit.
        const addHref = computed(() => (props.group.editable
            ? `/document-types/create?group=${props.group.id}`
            : '/document-types/create'));

        return { addHref, confirmDelete, deleting, filters, load, page, processing, restore, search, state };
    },
    template: `
    <Head :title="group.name" />
    <AdminShell active="document-types" :title="group.name">
        <Card>
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-5">
                <div class="min-w-0 max-w-2xl">
                    <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Document types</p>
                    <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">{{ group.name }}</h2>
                    <p v-if="group.description" class="mt-1 text-slate-600">{{ group.description }}</p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-3">
                    <Link v-if="group.editable" :href="'/document-type-groups/' + group.id + '/edit'" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                        <i class="fa-solid fa-pen" aria-hidden="true"></i>Edit group
                    </Link>
                    <Link href="/document-types" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 transition hover:border-[#07559e] hover:text-[#07559e]">
                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>All groups
                    </Link>
                </div>
            </div>

            <div class="mt-7">
                <IndexControls
                    v-model:entries="filters.entries"
                    v-model:search="filters.search"
                    :add-href="addHref"
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

            <div class="mt-6">
                <table class="w-full text-left">
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
                                <RowActions
                                    :label="'Actions for ' + type.name"
                                    :actions="type.deleted_at
                                        ? [{ key: 'restore', label: 'Restore', icon: 'fa-solid fa-rotate-left', tone: 'primary' }]
                                        : [
                                            { key: 'edit', label: 'Edit', icon: 'fa-solid fa-pen', tone: 'primary', href: '/document-types/' + type.id + '/edit' },
                                            { key: 'delete', label: 'Delete', icon: 'fa-solid fa-trash', tone: 'danger' },
                                        ]"
                                    @select="$event.key === 'restore' ? restore(type) : (deleting = type)" />
                            </td>
                        </tr>
                        <tr v-if="types.data.length === 0">
                            <td colspan="6" class="px-4 py-12 text-center text-slate-500">
                                <span v-if="filters.search || filters.status">No types here match that search.</span>
                                <span v-else>Nothing is filed under this group yet.</span>
                            </td>
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
