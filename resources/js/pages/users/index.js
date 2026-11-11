import { Head, Link, router } from '@inertiajs/vue3';
import { computed, defineComponent, reactive, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Badge from '../../components/Badge';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import IndexControls from '../../components/IndexControls';
import Pagination from '../../components/Pagination';
import SearchableSelect from '../../components/SearchableSelect';
import UserAvatar from '../../components/UserAvatar';
import { formatTimestamp } from '../../utils/format-date';

/**
 * The accounts list, which replaced the employee directory.
 *
 * There is no "Account" column any more because there is nothing left to link:
 * a row *is* an account. What took its place is Role, which is the thing about
 * a person this screen actually decides.
 *
 * **The table carries four columns, not seven.** Email, division and section
 * were on every row and read on almost none of them: they pushed the table past
 * the viewport, so the Actions column — the only part of a row anybody clicks —
 * sat behind a horizontal scroll. What a person scanning this list needs is who
 * somebody is and what they can reach; everything else is a question about *one*
 * account, which is what the View dialog answers.
 *
 * Nothing was dropped from the payload to do it. The rows already carry every
 * field, so View costs no request.
 */
export default defineComponent({
    name: 'UsersIndex',
    components: { AdminShell, Badge, Card, ConfirmActionModal, Head, IndexControls, Link, Pagination, SearchableSelect, UserAvatar },
    props: {
        users: { type: Object, required: true },
        sections: { type: Array, default: () => [] },
        roles: { type: Array, default: () => [] },
        filters: { type: Object, required: true },
    },
    setup(props) {
        const filters = reactive({ ...props.filters });

        const load = () => router.get('/users', filters, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });

        let timer = null;
        const search = () => {
            clearTimeout(timer);
            timer = setTimeout(load, 350);
        };

        const page = (url) => router.get(url, {}, { preserveScroll: true });

        // Closing an account has to ask where its files go before it can
        // happen, so the modal carries the picker rather than a bare Yes.
        const closing = ref(null);
        const transferTo = ref('');
        const processing = ref(false);

        const transferOptions = computed(() => [
            { value: '', label: 'Nobody — this account holds nothing' },
            ...props.users.data
                .filter((candidate) => candidate.id !== closing.value?.id)
                .map((candidate) => ({
                    value: candidate.id,
                    label: `${candidate.name} (${candidate.employee_id})`,
                })),
        ]);

        const cancelClose = () => {
            if (!processing.value) {
                closing.value = null;
                transferTo.value = '';
            }
        };

        const confirmClose = () => {
            processing.value = true;

            router.delete(`/users/${closing.value.id}`, {
                data: { transfer_to: transferTo.value || null },
                preserveScroll: true,
                onFinish: () => {
                    processing.value = false;
                    closing.value = null;
                    transferTo.value = '';
                },
            });
        };

        const roleOf = (user) => user.roles?.[0]?.name ?? '—';

        const roleTone = (user) => (roleOf(user) === 'SUPERADMIN' ? 'sky' : 'slate');

        // The whole of an account, for the one at a time somebody actually
        // wants it. Built from the row that is already on the page, so opening
        // this costs no request.
        const viewing = ref(null);

        const details = computed(() => {
            const user = viewing.value;

            if (!user) return [];

            return [
                { label: 'Employee ID', value: user.employee_id },
                { label: 'First name', value: user.first_name || '—' },
                { label: 'Last name', value: user.last_name || '—' },
                // Stated plainly rather than left blank: an account without one
                // cannot reset its own password, which is the thing somebody
                // looking at this screen needs to know.
                { label: 'Email address', value: user.email || 'None — cannot self-serve a password reset' },
                { label: 'Division', value: user.division?.name || '—' },
                { label: 'Section', value: user.section?.name || '—' },
                { label: 'Role', value: roleOf(user) },
                { label: 'Account created', value: formatTimestamp(user.created_at) },
                { label: 'Last updated', value: formatTimestamp(user.updated_at) },
            ];
        });

        return {
            cancelClose,
            closing,
            confirmClose,
            details,
            filters,
            load,
            page,
            processing,
            roleOf,
            roleTone,
            search,
            transferOptions,
            transferTo,
            viewing,
        };
    },
    template: `
    <Head title="Users" />
    <AdminShell active="users" title="Users">
        <Card>
            <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Directory</p>
            <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">User accounts</h2>
            <p class="mt-2 text-slate-600">Everyone who can sign in, the office they sit in, and the role that decides what they reach.</p>

            <div class="mt-7">
                <IndexControls
                    v-model:entries="filters.entries"
                    v-model:search="filters.search"
                    add-href="/users/create"
                    add-label="Add user"
                    search-placeholder="Search name, ID or email"
                    @search="search">
                    <template #filters>
                        <label>
                            Section
                            <select v-model="filters.section" class="field" @change="load">
                                <option :value="null">All sections</option>
                                <option v-for="section in sections" :key="section.id" :value="section.id">
                                    {{ section.name }}<template v-if="section.division"> — {{ section.division }}</template>
                                </option>
                            </select>
                        </label>
                        <label>
                            Role
                            <select v-model="filters.role" class="field" @change="load">
                                <option value="">All roles</option>
                                <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
                            </select>
                        </label>
                    </template>
                </IndexControls>
            </div>

            <div class="mt-6 overflow-x-auto">
                <table class="w-full min-w-[640px] text-left">
                    <thead>
                        <tr class="border-b border-slate-200 text-xs font-bold uppercase tracking-wide text-slate-500">
                            <th class="py-3 pr-4">Employee ID</th>
                            <th class="py-3 pr-4">Name</th>
                            <th class="py-3 pr-4">Role</th>
                            <th class="py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in users.data" :key="user.id" class="border-b border-slate-100 transition hover:bg-sky-50/50">
                            <td class="py-3 pr-4 font-bold text-slate-800">{{ user.employee_id }}</td>
                            <td class="py-3 pr-4">
                                <button type="button" class="flex items-center gap-3 text-left" @click="viewing = user">
                                    <UserAvatar :person="user" class="!h-9 !w-9 !text-xs" />
                                    <span class="min-w-0">
                                        <span class="block truncate font-semibold text-slate-800">{{ user.last_name }}, {{ user.first_name }}</span>
                                        <!-- The office, under the name rather than in two columns of
                                             its own: it is context for who this is, not something
                                             anybody reads down the page. -->
                                        <span class="block truncate text-xs text-slate-500">
                                            {{ user.division?.name || 'No division' }}<template v-if="user.section?.name"> · {{ user.section.name }}</template>
                                        </span>
                                    </span>
                                </button>
                            </td>
                            <td class="py-3 pr-4"><Badge :tone="roleTone(user)">{{ roleOf(user) }}</Badge></td>
                            <td class="py-3 text-right whitespace-nowrap">
                                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-bold text-slate-600 transition hover:bg-slate-100" @click="viewing = user">
                                    <i class="fa-solid fa-eye" aria-hidden="true"></i>View
                                </button>
                                <Link :href="'/users/' + user.id + '/edit?tab=personal'" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-bold text-[#07559e] transition hover:bg-sky-50">
                                    <i class="fa-solid fa-pen" aria-hidden="true"></i>Edit
                                </Link>
                                <button type="button" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-bold text-rose-600 transition hover:bg-rose-50" @click="closing = user">
                                    <i class="fa-solid fa-user-slash" aria-hidden="true"></i>Close
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!users.data.length">
                            <td colspan="4" class="py-10 text-center text-slate-500">No accounts match that search.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :pagination="users" @page="page" />
        </Card>

        <!--
            The whole account, one at a time. This uses ConfirmActionModal's own
            label/value list rather than laying one out again, so it is a
            read-only dialog with nothing to keep in step — and its only button
            closes it.

            No backticks in here: this comment is inside a JavaScript template
            literal, and one would end the string.
        -->
        <ConfirmActionModal
            :open="Boolean(viewing)"
            :title="viewing ? viewing.name : 'Account'"
            :details="details"
            :cancel-label="null"
            confirm-label="Close"
            icon="fa-solid fa-circle-user"
            wider
            @close="viewing = null"
            @confirm="viewing = null">
            <div class="text-left">
                <div class="flex items-center gap-4 rounded-xl bg-slate-50 px-4 py-3">
                    <UserAvatar :person="viewing" class="!h-16 !w-16 !text-lg" />
                    <span class="min-w-0">
                        <span class="block truncate font-bold text-slate-800">{{ viewing?.name }}</span>
                        <span class="block truncate text-sm text-slate-600">{{ viewing?.employee_id }} · {{ roleOf(viewing ?? {}) }}</span>
                    </span>
                </div>
                <Link v-if="viewing" :href="'/users/' + viewing.id + '/edit?tab=personal'" class="mt-4 inline-flex items-center gap-2 text-sm font-bold text-[#07559e] hover:text-[#043d78]">
                    <i class="fa-solid fa-pen" aria-hidden="true"></i>Edit this account
                </Link>
            </div>
        </ConfirmActionModal>

        <ConfirmActionModal
            :open="Boolean(closing)"
            :processing="processing"
            title="Close this account?"
            :message="closing ? closing.name + ' will no longer be able to sign in. Their audit trail stays intact.' : ''"
            confirm-label="Close account"
            tone="danger"
            icon="fa-solid fa-user-slash"
            @close="cancelClose"
            @confirm="confirmClose">
            <div class="mt-5 text-left">
                <span class="text-sm font-semibold text-slate-700">Hand their files and documents to</span>
                <div class="mt-2">
                    <SearchableSelect v-model="transferTo" :options="transferOptions" placeholder="Nobody" />
                </div>
                <p class="mt-1 text-xs text-slate-500">An account that still owns files cannot be closed without naming someone — otherwise those files would belong to a sign-in nobody can use. Who uploaded what does not change.</p>
            </div>
        </ConfirmActionModal>
    </AdminShell>
`,
});
