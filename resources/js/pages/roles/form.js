import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, defineComponent } from 'vue';
import AdminShell from '../../components/AdminShell';
import Button from '../../components/Button';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import useConfirmSubmit from '../../composables/useConfirmSubmit';

/**
 * One component serves create and edit, branching on the nullable `role` prop.
 *
 * The whole matrix is built from props the server derived from
 * App\Support\Modules, so a module added to that registry appears here with no
 * change to this file. Whatever this screen posts is re-derived against the
 * same registry server-side — an action a module does not offer is dropped, and
 * a read-only module is narrowed to `read` — so a crafted request cannot grant
 * something the matrix never showed.
 */
const ACTION_LABELS = {
    read: 'Read',
    create: 'Create',
    write: 'Write',
    edit: 'Edit',
    delete: 'Delete',
    share: 'Share',
    download: 'Download',
    manage: 'Manage',
};

export default defineComponent({
    name: 'RoleForm',
    components: { AdminShell, Button, Card, ConfirmActionModal, Head, Link },
    props: {
        role: { type: Object, default: null },
        moduleGroups: { type: Object, required: true },
        moduleActions: { type: Object, required: true },
        moduleLabels: { type: Object, required: true },
        readOnlyModules: { type: Array, required: true },
    },
    setup(props) {
        const isEditing = computed(() => Boolean(props.role));

        // `access` is {module: [action, …]} — the shape the server reads back.
        const form = useForm({
            name: props.role?.name ?? '',
            access: { ...(props.role?.access ?? {}) },
        });

        const actionsFor = (module) => props.moduleActions[module] ?? ['read'];
        const holds = (module, action) => (form.access[module] ?? []).includes(action);

        /*
         * Write implies read, in both directions.
         *
         * A role that may edit a record it cannot open is not a coherent grant,
         * so ticking anything grants read, and clearing read clears everything.
         * The server enforces the same rule; doing it here too keeps the
         * checkboxes from showing a state that cannot be saved.
         */
        const toggle = (module, action) => {
            const held = new Set(form.access[module] ?? []);

            if (held.has(action)) {
                held.delete(action);
                if (action === 'read') held.clear();
            } else {
                held.add(action);
                if (actionsFor(module).includes('read')) held.add('read');
            }

            const next = actionsFor(module).filter((candidate) => held.has(candidate));

            if (next.length === 0) {
                delete form.access[module];
            } else {
                form.access[module] = next;
            }
        };

        const grantedCount = computed(() => Object.values(form.access)
            .reduce((total, actions) => total + actions.length, 0));

        const submitForm = (options) => {
            if (isEditing.value) {
                form.put(`/roles/${props.role.id}`, options);
            } else {
                form.post('/roles', options);
            }
        };

        const { confirm, request, cancel, proceed } = useConfirmSubmit(submitForm);

        return {
            actionLabels: ACTION_LABELS,
            actionsFor,
            cancel,
            confirm,
            form,
            grantedCount,
            holds,
            isEditing,
            proceed,
            request,
            toggle,
        };
    },
    template: `
    <Head :title="isEditing ? 'Edit role' : 'Add role'" />
    <AdminShell active="roles" :title="isEditing ? 'Edit role' : 'Add role'">
        <Card class="w-full">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-5">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#07559e]">Settings</p>
                    <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">{{ isEditing ? 'Update role' : 'Create role' }}</h2>
                    <p class="mt-1 text-slate-600">Fields marked with <span class="font-bold text-rose-600">*</span> are required.</p>
                </div>
                <Link href="/roles" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to roles</Link>
            </div>

            <form class="mt-7 space-y-7" @submit.prevent="request">
                <label class="block max-w-md">
                    <span class="required-label text-sm font-semibold text-slate-700">Role name</span>
                    <input v-model="form.name" class="field" :class="{ 'border-rose-500': form.errors.name }" placeholder="e.g. Records Officer" />
                    <p v-if="form.errors.name" class="error">{{ form.errors.name }}</p>
                </label>

                <div>
                    <h3 class="text-sm font-bold uppercase tracking-wide text-slate-700">Permissions</h3>
                    <p class="mt-1 text-sm text-slate-600">Ticking any permission grants Read as well — a role that may change a record it cannot open is not a usable grant. {{ grantedCount }} selected.</p>
                    <p v-if="form.errors.access" class="error">{{ form.errors.access }}</p>
                </div>

                <div v-for="(modules, groupLabel) in moduleGroups" :key="groupLabel" class="space-y-3">
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#07559e]">{{ groupLabel }}</p>
                    <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                        <div v-for="module in modules" :key="module" class="rounded-xl border border-slate-200 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <p class="font-bold text-slate-800">{{ moduleLabels[module] }}</p>
                                <span v-if="readOnlyModules.includes(module)" class="inline-flex items-center whitespace-nowrap rounded-full bg-slate-100 px-3 py-1 text-xs font-bold uppercase leading-5 text-slate-600">Read only</span>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2">
                                <label v-for="action in actionsFor(module)" :key="action" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700">
                                    <input type="checkbox" class="h-4 w-4 rounded border-slate-300 accent-[#07559e]" :checked="holds(module, action)" @change="toggle(module, action)" />
                                    {{ actionLabels[action] ?? action }}
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-3 border-t border-slate-200 pt-5">
                    <Button href="/roles" variant="secondary">Cancel</Button>
                    <Button type="submit" :disabled="form.processing">{{ isEditing ? 'Save changes' : 'Create role' }}</Button>
                </div>
            </form>
        </Card>

        <ConfirmActionModal
            :open="confirm.open"
            :processing="confirm.processing"
            :title="isEditing ? 'Save this role?' : 'Create this role?'"
            :message="isEditing ? 'Every account holding this role takes the new permissions on its next request.' : 'The role will be available to assign straight away.'"
            :confirm-label="isEditing ? 'Save changes' : 'Create role'"
            icon="fa-solid fa-user-shield"
            @close="cancel"
            @confirm="proceed" />
    </AdminShell>
`,
});
