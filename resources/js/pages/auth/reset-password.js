import { Head, useForm } from '@inertiajs/vue3';
import { defineComponent } from 'vue';

export default defineComponent({
    name: 'ResetPassword',
    components: { Head },
    props: {
        email: { type: String, default: '' },
        token: { type: String, required: true },
    },
    setup(props) {
        const form = useForm({
            token: props.token,
            email: props.email,
            password: '',
            password_confirmation: '',
        });

        const submit = () => form.post('/reset-password', {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });

        return { form, submit };
    },
    template: `
    <Head title="Reset password" />
    <main class="flex flex-1 items-center bg-slate-50 px-5 py-10 sm:px-8 sm:py-14 lg:px-12">
        <section class="mx-auto w-full max-w-md">
            <form class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm sm:p-10" @submit.prevent="submit">
                <div class="flex items-center gap-3 border-b border-slate-200 pb-5">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-sky-100 text-lg text-[#07559e]">
                        <i class="fa-solid fa-lock-open" aria-hidden="true"></i>
                    </div>
                    <h1 class="text-xl font-semibold uppercase tracking-wide text-slate-900">Reset password</h1>
                </div>
                <label class="mt-7 block">
                    <span class="required-label text-sm font-semibold text-slate-700">Email address</span>
                    <input v-model="form.email" type="email" autocomplete="email" class="field" :class="{ 'border-rose-500': form.errors.email }" />
                    <p v-if="form.errors.email" class="error">{{ form.errors.email }}</p>
                </label>
                <label class="mt-5 block">
                    <span class="required-label text-sm font-semibold text-slate-700">New password</span>
                    <input v-model="form.password" type="password" autocomplete="new-password" class="field" :class="{ 'border-rose-500': form.errors.password }" />
                    <p v-if="form.errors.password" class="error">{{ form.errors.password }}</p>
                </label>
                <label class="mt-5 block">
                    <span class="required-label text-sm font-semibold text-slate-700">Confirm new password</span>
                    <input v-model="form.password_confirmation" type="password" autocomplete="new-password" class="field" :class="{ 'border-rose-500': form.errors.password_confirmation }" />
                    <p v-if="form.errors.password_confirmation" class="error">{{ form.errors.password_confirmation }}</p>
                </label>
                <button type="submit" :disabled="form.processing" class="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#00aeef] px-5 py-3 text-sm font-bold uppercase tracking-wide text-white transition hover:bg-[#008dcc] disabled:bg-slate-400">{{ form.processing ? 'Saving…' : 'Reset password' }}</button>
            </form>
        </section>
    </main>
`,
});
