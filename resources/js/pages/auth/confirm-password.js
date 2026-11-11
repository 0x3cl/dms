import { Head, useForm } from '@inertiajs/vue3';
import { defineComponent } from 'vue';

/**
 * Re-confirms the signed-in user's password before a sensitive action. The
 * server validates it against the employee ID, not an email address.
 */
export default defineComponent({
    name: 'ConfirmPassword',
    components: { Head },
    setup() {
        const form = useForm({ password: '' });
        const submit = () => form.post('/confirm-password', {
            onFinish: () => form.reset('password'),
        });

        return { form, submit };
    },
    template: `
    <Head title="Confirm password" />
    <main class="flex flex-1 items-center bg-slate-50 px-5 py-10 sm:px-8 sm:py-14 lg:px-12">
        <section class="mx-auto w-full max-w-md">
            <form class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm sm:p-10" @submit.prevent="submit">
                <div class="flex items-center gap-3 border-b border-slate-200 pb-5">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-sky-100 text-lg text-[#07559e]">
                        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                    </div>
                    <h1 class="text-xl font-semibold uppercase tracking-wide text-slate-900">Confirm password</h1>
                </div>
                <p class="mt-5 text-sm text-slate-600">This is a secure area. Please confirm your password before continuing.</p>
                <label class="mt-7 block">
                    <span class="required-label text-sm font-semibold text-slate-700">Password</span>
                    <input v-model="form.password" type="password" autocomplete="current-password" class="field" :class="{ 'border-rose-500': form.errors.password }" />
                    <p v-if="form.errors.password" class="error">{{ form.errors.password }}</p>
                </label>
                <button type="submit" :disabled="form.processing" class="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#00aeef] px-5 py-3 text-sm font-bold uppercase tracking-wide text-white transition hover:bg-[#008dcc] disabled:bg-slate-400">{{ form.processing ? 'Confirming…' : 'Confirm' }}</button>
            </form>
        </section>
    </main>
`,
});
