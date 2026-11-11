import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, defineComponent } from 'vue';

/**
 * Reset is by email address, which is the one thing Laravel's password broker
 * can look a user up by — sign-in itself uses the employee ID. An account with
 * no email on file cannot reset its own password and needs an administrator.
 */
export default defineComponent({
    name: 'ForgotPassword',
    components: { Head, Link },
    setup() {
        const form = useForm({ email: '' });
        const submit = () => form.post('/forgot-password');
        const page = usePage();
        const success = computed(() => page.props.flash?.success);

        return { form, submit, success };
    },
    template: `
    <Head title="Forgot password" />
    <main class="flex flex-1 items-center bg-slate-50 px-5 py-10 sm:px-8 sm:py-14 lg:px-12">
        <section class="mx-auto w-full max-w-md">
            <form class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm sm:p-10" @submit.prevent="submit">
                <div class="flex items-center gap-3 border-b border-slate-200 pb-5">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-sky-100 text-lg text-[#07559e]">
                        <i class="fa-solid fa-key" aria-hidden="true"></i>
                    </div>
                    <h1 class="text-xl font-semibold uppercase tracking-wide text-slate-900">Forgot password</h1>
                </div>
                <p class="mt-5 text-sm text-slate-600">Enter the email address on your account and we will send you a reset link.</p>
                <p v-if="success" class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ success }}</p>
                <label class="mt-7 block">
                    <span class="required-label text-sm font-semibold text-slate-700">Email address</span>
                    <input v-model="form.email" type="email" autocomplete="email" class="field" :class="{ 'border-rose-500': form.errors.email }" />
                    <p v-if="form.errors.email" class="error">{{ form.errors.email }}</p>
                </label>
                <button type="submit" :disabled="form.processing" class="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#00aeef] px-5 py-3 text-sm font-bold uppercase tracking-wide text-white transition hover:bg-[#008dcc] disabled:bg-slate-400">{{ form.processing ? 'Sending…' : 'Email reset link' }}</button>
                <p class="mt-6 text-center text-sm">
                    <Link href="/login" class="font-semibold text-[#07559e] hover:text-[#043d78]">Back to sign in</Link>
                </p>
            </form>
        </section>
    </main>
`,
});
