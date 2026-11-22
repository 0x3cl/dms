import { Head, Link, router } from '@inertiajs/vue3';
import { defineComponent, reactive, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import IndexControls from '../../components/IndexControls';
import Pagination from '../../components/Pagination';
import RowActions from '../../components/RowActions';

export default defineComponent({
    name: 'RolesIndex',
    components: { AdminShell, Card, ConfirmActionModal, Head, IndexControls, Link, Pagination, RowActions },
    props: {
        roles: { type: Object, required: true },
        filters: { type: Object, required: true },
        protectedRole: { type: String, required: true },
    },
    setup(props) {
        const filters = reactive({ ...props.filters });
        let searchTimer;

        const load = () => router.get('/roles', filters, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
        const search = () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(load, 350);
        };
        const page = (url) => url && router.get(url, {}, { preserveScroll: true });

        // The one role the application itself depends on: the Gate::before hook
        // short-circuits on its name, so renaming or deleting it would strip
        // that bypass from whoever holds it. The server refuses too — this only
        // keeps the buttons honest.
        const isProtected = (role) => role.name === props.protectedRole;

        const deleting = ref(null);
        const deleteProcessing = ref(false);
        const confirmDelete = () => {
            deleteProcessing.value = true;
            router.delete(`/roles/${deleting.value.id}`, {
                preserveScroll: true,
                onFinish: () => {
                    deleteProcessing.value = false;
                    deleting.value = null;
                },
            });
        };

        return { confirmDelete, deleteProcessing, deleting, filters, isProtected, load, page, search };
    },
    template: `
    <Head title="Roles and Permissions" />
    <AdminShell active="roles" title="Roles and Permissions">
        <Card>
            <div>
                <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Settings</p>
                <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">All roles</h2>
                <p class="mt-1 text-slate-600">A role is a set of permissions, and every account holds exactly one. Changing a role takes effect on each holder's next request.</p>
            </div>
            <div class="mt-7">
                <IndexControls
                    v-model:entries="filters.entries"
                    v-model:search="filters.search"
                    add-href="/roles/create"
                    add-label="Add role"
                    search-placeholder="Search roles by name"
                    @search="search" />
            </div>
            <div class="mt-6 overflow-x-auto">
                <table class="w-full min-w-[700px] text-left">
                    <thead class="border-y border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-4">Role</th>
                            <th class="px-4 py-4">Permissions</th>
                            <th class="px-4 py-4">Accounts</th>
                            <th class="px-4 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="role in roles.data" :key="role.id" class="border-b border-slate-100 hover:bg-sky-50/50">
                            <td class="px-4 py-4 font-bold text-slate-800">
                                {{ role.name }}
                                <span v-if="isProtected(role)" class="ml-2 inline-flex items-center whitespace-nowrap rounded-full bg-sky-100 px-3 py-1 text-xs font-bold uppercase leading-5 text-[#07559e]">Built in</span>
                            </td>
                            <td class="px-4 py-4 text-slate-600">{{ role.permissions_count }}</td>
                            <td class="px-4 py-4 text-slate-600">{{ role.users_count }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right">
                                <RowActions
                                    v-if="!isProtected(role)"
                                    :label="'Actions for ' + role.name"
                                    :actions="[
                                        { key: 'edit', label: 'Edit', icon: 'fa-solid fa-pen', tone: 'primary', href: '/roles/' + role.id + '/edit' },
                                        { key: 'delete', label: 'Delete', icon: 'fa-solid fa-trash', tone: 'danger' },
                                    ]"
                                    @select="deleting = role" />
                                <span v-else class="text-sm text-slate-400">Not editable</span>
                            </td>
                        </tr>
                        <tr v-if="roles.data.length === 0">
                            <td colspan="4" class="px-4 py-12 text-center text-slate-500">No roles match that search.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :pagination="roles" @page="page" />
        </Card>

        <ConfirmActionModal
            :open="deleting !== null"
            :processing="deleteProcessing"
            title="Delete this role?"
            :message="'Deleting ' + (deleting?.name ?? '') + ' cannot be undone. Accounts holding it must be reassigned first.'"
            confirm-label="Delete"
            tone="danger"
            icon="fa-solid fa-trash"
            @close="deleting = null"
            @confirm="confirmDelete" />
    </AdminShell>
`,
});
