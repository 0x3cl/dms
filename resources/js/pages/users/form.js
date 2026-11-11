import { Head, useForm } from '@inertiajs/vue3';
import { computed, defineComponent, watch } from 'vue';
import AdminShell from '../../components/AdminShell';
import AvatarField from '../../components/AvatarField';
import Button from '../../components/Button';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import SearchableSelect from '../../components/SearchableSelect';
import useConfirmSubmit from '../../composables/useConfirmSubmit';
import useUrlTab from '../../composables/useUrlTab';

/**
 * One account across two tabs: the office's record of a person, and their
 * access.
 *
 * The split is not cosmetic. Correcting a surname and setting a password are
 * different acts of trust and they fail differently — a rejected password
 * should not discard a corrected name — so on an existing account each tab is
 * its own form posting to its own endpoint, with its own error bag.
 *
 * Creating is the exception: there is no account yet to set a password on
 * separately, so both tabs feed the single create request.
 *
 * `{ always: true }` keeps `?tab=` in the address even on the first tab, so the
 * URL in the bar is always the one to hand to a colleague.
 */
export default defineComponent({
    name: 'UserForm',
    components: { AdminShell, AvatarField, Button, Card, ConfirmActionModal, Head, SearchableSelect },
    props: {
        user: { type: Object, default: null },
        divisions: { type: Array, default: () => [] },
        roles: { type: Array, default: () => [] },
        defaultRole: { type: String, default: 'Staff' },
        holdings: { type: Object, default: null },
        transferTargets: { type: Array, default: () => [] },
        can: { type: Object, default: () => ({}) },
    },
    setup(props) {
        const isEditing = Boolean(props.user);
        const tab = useUrlTab('tab', ['personal', 'security'], 'personal', { always: true });

        const tabs = [
            { key: 'personal', label: 'Personal Information', icon: 'fa-solid fa-circle-user' },
            { key: 'security', label: 'Security & Password', icon: 'fa-solid fa-key' },
        ];

        const form = useForm({
            employee_id: props.user?.employee_id ?? '',
            first_name: props.user?.first_name ?? '',
            last_name: props.user?.last_name ?? '',
            email: props.user?.email ?? '',
            division_id: props.user?.division_id ?? '',
            section_id: props.user?.section_id ?? '',
            role: props.user?.role ?? props.defaultRole,
            // On the create form the avatar rides along with the account,
            // because a person and their face arrive together and there is no
            // account yet to post one to. On an existing account AvatarField
            // posts to its own endpoint instead.
            ...(isEditing ? {} : { avatar_seed: null, profile_image: null }),
            ...(isEditing ? {} : { password: '', password_confirmation: '' }),
        });

        const divisionOptions = computed(() => props.divisions.map((division) => ({
            value: division.id,
            label: division.name,
        })));

        const sectionOptions = computed(() => {
            const division = props.divisions.find((candidate) => candidate.id === form.division_id);

            return (division?.sections ?? []).map((section) => ({
                value: section.id,
                label: section.name,
            }));
        });

        const roleOptions = computed(() => props.roles.map((role) => ({ value: role, label: role })));

        // A section belongs to exactly one division, so a division change can
        // leave a section selected that no longer belongs to it.
        watch(() => form.division_id, (value, previous) => {
            if (previous !== undefined && value !== previous) {
                form.section_id = '';
            }
        });

        const submit = (options) => (isEditing
            ? form.put(`/users/${props.user.id}`, options)
            // forceFormData, because the create request may carry a photo. A
            // chosen avatar seed is a plain string and would post either way,
            // but the two have to travel in one request or an account can be
            // created with a face nobody asked for.
            : form.post('/users', { ...options, forceFormData: true }));

        const { confirm, request, cancel, proceed } = useConfirmSubmit(submit);

        // Only on an existing account: a password set here replaces whatever
        // was there, and the administrator doing it does not know the old one.
        const passwordForm = useForm({ password: '', password_confirmation: '' });

        const submitPassword = (options) => passwordForm.put(`/users/${props.user.id}/password`, {
            ...options,
            errorBag: 'updatePassword',
            onSuccess: () => passwordForm.reset(),
        });

        const password = useConfirmSubmit(submitPassword);

        return {
            cancel,
            confirm,
            divisionOptions,
            form,
            isEditing,
            password,
            passwordForm,
            proceed,
            request,
            roleOptions,
            sectionOptions,
            tab,
            tabs,
        };
    },
    template: `
    <Head :title="isEditing ? 'Edit User' : 'Add User'" />
    <AdminShell active="users" :title="isEditing ? 'Edit User' : 'Add User'">
        <Card>
            <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Directory</p>
            <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">{{ isEditing ? user.name : 'New account' }}</h2>
            <p class="mt-2 text-slate-600">
                <template v-if="isEditing">{{ user.employee_id }} — this is both their record in the office and their sign-in.</template>
                <template v-else>Fields marked with <span class="font-bold text-rose-600">*</span> are required. The account can sign in as soon as it is created.</template>
            </p>

            <div class="mt-7 flex border-b border-slate-200">
                <button
                    v-for="item in tabs"
                    :key="item.key"
                    type="button"
                    class="flex items-center justify-center gap-2 border-b-2 px-4 py-3 text-xs font-bold uppercase tracking-wide transition"
                    :class="tab === item.key ? 'border-[#00aeef] text-[#07559e]' : 'border-transparent text-slate-400 hover:text-slate-600'"
                    @click="tab = item.key">
                    <i :class="item.icon" aria-hidden="true"></i><span class="hidden sm:inline">{{ item.label }}</span>
                </button>
            </div>

            <!--
                Personal Information. The avatar sits above the form: on an
                existing account it posts to its own endpoint, so a face can be
                changed without resubmitting the record, and on the create form
                it rides along with the account.
            -->
            <div v-show="tab === 'personal'" class="mt-7 border-b border-slate-200 pb-7">
                <AvatarField
                    v-model="form.profile_image"
                    v-model:seed="form.avatar_seed"
                    :image="user?.profile_image"
                    :seed="user ? user.avatar_seed : form.avatar_seed"
                    :name="user ? user.name : ((form.first_name + ' ' + form.last_name).trim())"
                    :action="user ? '/users/' + user.id + '/avatar' : ''"
                    :remove-action="user ? '/users/' + user.id + '/avatar' : ''"
                    label="Avatar"
                    hint="Pick a generated avatar, or upload a photo — JPG, PNG or WebP, up to 2 MB." />
            </div>

            <form v-show="tab === 'personal'" class="mt-7 grid gap-5 md:grid-cols-2" @submit.prevent="request">
                <label>
                    <span class="required-label text-sm font-semibold text-slate-700">Employee ID</span>
                    <input v-model="form.employee_id" placeholder="e.g. EMP-0001" class="field" :class="{ 'border-rose-500': form.errors.employee_id }" />
                    <p v-if="form.errors.employee_id" class="error">{{ form.errors.employee_id }}</p>
                    <p class="mt-1 text-xs text-slate-500">This is what they sign in with.</p>
                </label>
                <label>
                    <span class="text-sm font-semibold text-slate-700">Email address</span>
                    <input v-model="form.email" type="email" class="field" :class="{ 'border-rose-500': form.errors.email }" />
                    <p v-if="form.errors.email" class="error">{{ form.errors.email }}</p>
                    <p class="mt-1 text-xs text-slate-500">Optional. Only needed so they can reset their own password.</p>
                </label>
                <label>
                    <span class="required-label text-sm font-semibold text-slate-700">First name</span>
                    <input v-model="form.first_name" class="field" :class="{ 'border-rose-500': form.errors.first_name }" />
                    <p v-if="form.errors.first_name" class="error">{{ form.errors.first_name }}</p>
                </label>
                <label>
                    <span class="required-label text-sm font-semibold text-slate-700">Last name</span>
                    <input v-model="form.last_name" class="field" :class="{ 'border-rose-500': form.errors.last_name }" />
                    <p v-if="form.errors.last_name" class="error">{{ form.errors.last_name }}</p>
                </label>
                <div>
                    <span class="required-label text-sm font-semibold text-slate-700">Division</span>
                    <div class="mt-2">
                        <SearchableSelect v-model="form.division_id" :options="divisionOptions" placeholder="Select a division" :invalid="Boolean(form.errors.division_id)" />
                    </div>
                    <p v-if="form.errors.division_id" class="error">{{ form.errors.division_id }}</p>
                </div>
                <div>
                    <span class="required-label text-sm font-semibold text-slate-700">Section</span>
                    <div class="mt-2">
                        <SearchableSelect v-model="form.section_id" :options="sectionOptions" :disabled="!form.division_id" :placeholder="form.division_id ? 'Select a section' : 'Pick a division first'" :invalid="Boolean(form.errors.section_id)" />
                    </div>
                    <p v-if="form.errors.section_id" class="error">{{ form.errors.section_id }}</p>
                </div>
                <div class="md:col-span-2">
                    <span class="required-label text-sm font-semibold text-slate-700">Role</span>
                    <div class="mt-2">
                        <SearchableSelect v-model="form.role" :options="roleOptions" :disabled="can.editRole === false" placeholder="Select a role" :invalid="Boolean(form.errors.role)" />
                    </div>
                    <p v-if="form.errors.role" class="error">{{ form.errors.role }}</p>
                    <p v-if="can.editRole === false" class="mt-1 text-xs text-slate-500">You cannot change this role — it is either your own account or the only {{ 'SUPERADMIN' }} left. Another administrator can do it.</p>
                    <p v-else class="mt-1 text-xs text-slate-500">The role decides which modules this account reaches. Manage what each one carries under Roles and Permissions.</p>
                </div>

                <template v-if="!isEditing">
                    <div class="border-t border-slate-200 pt-5 md:col-span-2">
                        <p class="text-sm font-bold uppercase tracking-wide text-slate-700">First password</p>
                        <p class="mt-1 text-sm text-slate-600">Set on the Security &amp; Password tab. A new account is created with its password in the same request.</p>
                    </div>
                </template>

                <div class="flex justify-end gap-3 border-t border-slate-200 pt-5 md:col-span-2">
                    <Button href="/users" variant="secondary"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back</Button>
                    <Button type="submit" variant="primary" :disabled="form.processing">
                        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>{{ isEditing ? 'Save changes' : 'Create account' }}
                    </Button>
                </div>
            </form>

            <!-- Security & Password -->
            <div v-show="tab === 'security'" class="mt-7">
                <form v-if="!isEditing" class="grid gap-5 md:grid-cols-2" @submit.prevent="request">
                    <p class="text-sm text-slate-600 md:col-span-2">This password is what the account signs in with. It is submitted together with the Personal Information tab, so one button creates the whole account.</p>
                    <label>
                        <span class="required-label text-sm font-semibold text-slate-700">Password</span>
                        <input v-model="form.password" type="password" autocomplete="new-password" class="field" :class="{ 'border-rose-500': form.errors.password }" />
                        <p v-if="form.errors.password" class="error">{{ form.errors.password }}</p>
                    </label>
                    <label>
                        <span class="required-label text-sm font-semibold text-slate-700">Confirm password</span>
                        <input v-model="form.password_confirmation" type="password" autocomplete="new-password" class="field" />
                    </label>
                    <div class="flex justify-end gap-3 border-t border-slate-200 pt-5 md:col-span-2">
                        <Button type="submit" variant="primary" :disabled="form.processing">
                            <i class="fa-solid fa-user-plus" aria-hidden="true"></i>Create account
                        </Button>
                    </div>
                </form>

                <form v-else class="grid gap-5 md:grid-cols-2" @submit.prevent="password.request">
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 md:col-span-2">
                        <p class="text-sm text-amber-800">
                            <i class="fa-solid fa-circle-info mr-1.5" aria-hidden="true"></i>
                            Setting a password here replaces the current one without asking for it — that is the point of an administrator reset. Tell {{ user.first_name }} what it is, and have them change it from My Account.
                        </p>
                    </div>
                    <label>
                        <span class="required-label text-sm font-semibold text-slate-700">New password</span>
                        <input v-model="passwordForm.password" type="password" autocomplete="new-password" class="field" :class="{ 'border-rose-500': passwordForm.errors.password }" />
                        <p v-if="passwordForm.errors.password" class="error">{{ passwordForm.errors.password }}</p>
                    </label>
                    <label>
                        <span class="required-label text-sm font-semibold text-slate-700">Confirm new password</span>
                        <input v-model="passwordForm.password_confirmation" type="password" autocomplete="new-password" class="field" />
                    </label>
                    <div class="flex justify-end gap-3 border-t border-slate-200 pt-5 md:col-span-2">
                        <Button type="submit" variant="primary" :disabled="passwordForm.processing">
                            <i class="fa-solid fa-key" aria-hidden="true"></i>Set password
                        </Button>
                    </div>
                </form>
            </div>
        </Card>

        <ConfirmActionModal
            :open="confirm.open"
            :processing="confirm.processing"
            :title="isEditing ? 'Save these changes?' : 'Create this account?'"
            :message="isEditing ? 'The account record is updated.' : 'The account can sign in as soon as it exists.'"
            :confirm-label="isEditing ? 'Save changes' : 'Create account'"
            icon="fa-solid fa-user-pen"
            @close="cancel"
            @confirm="proceed" />

        <ConfirmActionModal
            :open="password.confirm.open"
            :processing="password.confirm.processing"
            title="Set a new password?"
            message="The current password stops working immediately. Any signed-in session of theirs is unaffected until they sign out."
            confirm-label="Set password"
            tone="danger"
            icon="fa-solid fa-key"
            @close="password.cancel"
            @confirm="password.proceed" />
    </AdminShell>
`,
});
