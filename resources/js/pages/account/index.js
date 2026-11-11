import { Head, useForm } from '@inertiajs/vue3';
import { defineComponent, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import AvatarField from '../../components/AvatarField';
import Button from '../../components/Button';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import ThemeToggle from '../../components/ThemeToggle';
import SearchableSelect from '../../components/SearchableSelect';
import useUrlTab from '../../composables/useUrlTab';

/**
 * Profile, password and account deletion on one tabbed screen.
 *
 * The Blade original was three partials in an `<x-app-layout>` whose slot the
 * layout never rendered, calling two routes that did not exist and reading an
 * `email` column the schema did not have — so the page showed nothing at all.
 */
export default defineComponent({
    name: 'Account',
    components: { AdminShell, AvatarField, Button, Card, ConfirmActionModal, Head, ThemeToggle, SearchableSelect },
    props: {
        user: { type: Object, required: true },
        holdings: { type: Object, required: true },
        transferTargets: { type: Array, required: true },
    },
    setup(props) {
        const tab = useUrlTab('tab', ['profile', 'password', 'danger'], 'profile');

        const profileForm = useForm({
            first_name: props.user.first_name,
            last_name: props.user.last_name,
            email: props.user.email ?? '',
        });
        const submitProfile = () => profileForm.patch('/profile', { preserveScroll: true });

        const passwordForm = useForm({
            current_password: '',
            password: '',
            password_confirmation: '',
        });
        const submitPassword = () => passwordForm.put('/password', {
            preserveScroll: true,
            errorBag: 'updatePassword',
            onSuccess: () => passwordForm.reset(),
        });

        const confirmingDeletion = ref(false);
        const deleteForm = useForm({ password: '', transfer_to: null });
        const confirmDelete = () => deleteForm.delete('/profile', {
            preserveScroll: true,
            errorBag: 'userDeletion',
            onError: () => deleteForm.reset('password'),
        });

        const tabs = [
            { key: 'profile', label: 'Profile', icon: 'fa-solid fa-circle-user' },
            { key: 'password', label: 'Password', icon: 'fa-solid fa-key' },
            { key: 'danger', label: 'Delete account', icon: 'fa-solid fa-triangle-exclamation' },
        ];

        return {
            confirmDelete,
            confirmingDeletion,
            deleteForm,
            transferOptions: props.transferTargets.map((target) => ({ value: target.id, label: target.name })),
            passwordForm,
            profileForm,
            submitPassword,
            submitProfile,
            tab,
            tabs,
        };
    },
    template: `
    <Head title="My Account" />
    <AdminShell active="account" title="My Account">
        <div class="space-y-5">
            <Card>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Account</p>
                        <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">{{ user.name }}</h2>
                        <p class="mt-1 text-slate-600">
                            {{ user.employee_id }}
                            <template v-if="user.division"> · {{ user.division }}</template>
                            <template v-if="user.section"> · {{ user.section }}</template>
                        </p>
                    </div>
                    <div class="flex items-center gap-3 rounded-xl border border-slate-200 px-4 py-2">
                        <span class="text-xs font-bold uppercase tracking-wide text-slate-500">Theme</span>
                        <ThemeToggle />
                    </div>
                </div>

                <div data-tour="account-tabs" class="mt-7 flex border-b border-slate-200">
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
                    Profile. The avatar is outside the form on purpose: it posts
                    to its own endpoint, so changing a face is one click and does
                    not drag a half-corrected surname along with it.
                -->
                <div v-if="tab === 'profile'" class="mt-7 border-b border-slate-200 pb-7">
                    <AvatarField
                        :image="user.profile_image"
                        :seed="user.avatar_seed"
                        :name="user.name"
                        action="/profile/avatar"
                        remove-action="/profile/avatar"
                        label="Your avatar"
                        hint="Pick a generated avatar, or upload a photo — JPG, PNG or WebP, up to 2 MB." />
                </div>

                <form v-if="tab === 'profile'" class="mt-7 grid gap-5 md:grid-cols-2" @submit.prevent="submitProfile">
                    <label>
                        <span class="required-label text-sm font-semibold text-slate-700">First name</span>
                        <input v-model="profileForm.first_name" class="field" :class="{ 'border-rose-500': profileForm.errors.first_name }" />
                        <p v-if="profileForm.errors.first_name" class="error">{{ profileForm.errors.first_name }}</p>
                    </label>
                    <label>
                        <span class="required-label text-sm font-semibold text-slate-700">Last name</span>
                        <input v-model="profileForm.last_name" class="field" :class="{ 'border-rose-500': profileForm.errors.last_name }" />
                        <p v-if="profileForm.errors.last_name" class="error">{{ profileForm.errors.last_name }}</p>
                    </label>
                    <label>
                        <span class="text-sm font-semibold text-slate-700">Email address</span>
                        <input v-model="profileForm.email" type="email" class="field" :class="{ 'border-rose-500': profileForm.errors.email }" />
                        <p v-if="profileForm.errors.email" class="error">{{ profileForm.errors.email }}</p>
                        <p class="mt-1 text-xs text-slate-500">Optional. You sign in with your employee ID — an address is only needed to reset your own password.</p>
                    </label>
                    <div class="md:col-span-2">
                        <span class="text-sm font-semibold text-slate-700">Employee ID</span>
                        <input :value="user.employee_id" disabled class="field cursor-not-allowed bg-slate-50 text-slate-500" />
                        <p class="mt-1 text-xs text-slate-500">Your employee ID and office assignment are managed by the administrator.</p>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-slate-200 pt-5 md:col-span-2">
                        <Button type="submit" variant="primary" :disabled="profileForm.processing"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>{{ profileForm.processing ? 'Saving…' : 'Save changes' }}</Button>
                    </div>
                </form>

                <!-- Password -->
                <form v-if="tab === 'password'" class="mt-7 grid gap-5 md:grid-cols-2" @submit.prevent="submitPassword">
                    <label class="md:col-span-2">
                        <span class="required-label text-sm font-semibold text-slate-700">Current password</span>
                        <input v-model="passwordForm.current_password" type="password" autocomplete="current-password" class="field" :class="{ 'border-rose-500': passwordForm.errors.current_password }" />
                        <p v-if="passwordForm.errors.current_password" class="error">{{ passwordForm.errors.current_password }}</p>
                    </label>
                    <label>
                        <span class="required-label text-sm font-semibold text-slate-700">New password</span>
                        <input v-model="passwordForm.password" type="password" autocomplete="new-password" class="field" :class="{ 'border-rose-500': passwordForm.errors.password }" />
                        <p v-if="passwordForm.errors.password" class="error">{{ passwordForm.errors.password }}</p>
                    </label>
                    <label>
                        <span class="required-label text-sm font-semibold text-slate-700">Confirm new password</span>
                        <input v-model="passwordForm.password_confirmation" type="password" autocomplete="new-password" class="field" :class="{ 'border-rose-500': passwordForm.errors.password_confirmation }" />
                        <p v-if="passwordForm.errors.password_confirmation" class="error">{{ passwordForm.errors.password_confirmation }}</p>
                    </label>
                    <div class="flex justify-end gap-3 border-t border-slate-200 pt-5 md:col-span-2">
                        <Button type="submit" variant="primary" :disabled="passwordForm.processing"><i class="fa-solid fa-key" aria-hidden="true"></i>{{ passwordForm.processing ? 'Saving…' : 'Update password' }}</Button>
                    </div>
                </form>

                <!-- Danger zone -->
                <div v-if="tab === 'danger'" class="mt-7">
                    <div class="rounded-xl border border-rose-200 bg-rose-50 p-5">
                        <h3 class="text-sm font-bold uppercase tracking-wide text-rose-800">Close this account</h3>
                        <p class="mt-2 text-sm text-rose-700">Your sign-in access is removed. Documents you uploaded are not deleted — the office register keeps them, and their tracking history stays intact.</p>

                        <div v-if="holdings.total > 0" class="mt-4 rounded-lg bg-white/70 p-4">
                            <p class="text-sm font-bold text-rose-800">This account still holds {{ holdings.total }} item(s)</p>
                            <ul class="mt-2 list-inside list-disc text-sm text-rose-700">
                                <li v-if="holdings.files">{{ holdings.files }} file(s)</li>
                                <li v-if="holdings.folders">{{ holdings.folders }} folder(s)</li>
                                <li v-if="holdings.documents">{{ holdings.documents }} tracked document(s)</li>
                            </ul>
                            <p class="mt-2 text-sm text-rose-700">They have to be handed to someone before the account can be closed — otherwise they would belong to an account nobody can sign into, and nobody could edit them.</p>
                        </div>

                        <Button variant="danger" class="mt-5" @click="confirmingDeletion = true"><i class="fa-solid fa-trash" aria-hidden="true"></i>Close account</Button>
                    </div>
                </div>
            </Card>
        </div>

        <ConfirmActionModal
            :open="confirmingDeletion"
            :processing="deleteForm.processing"
            title="Delete your account?"
            message="This cannot be undone. Enter your password to confirm."
            confirm-label="Delete account"
            tone="danger"
            icon="fa-solid fa-triangle-exclamation"
            @close="confirmingDeletion = false; deleteForm.reset('password')"
            @confirm="confirmDelete">
            <label class="mt-5 block text-left">
                <span class="required-label text-sm font-semibold text-slate-700">Password</span>
                <input v-model="deleteForm.password" type="password" autocomplete="current-password" class="field" :class="{ 'border-rose-500': deleteForm.errors.password }" />
                <p v-if="deleteForm.errors.password" class="error">{{ deleteForm.errors.password }}</p>
            </label>
        </ConfirmActionModal>
    </AdminShell>
`,
});
