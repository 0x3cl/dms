import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, defineComponent } from 'vue';
import { organization } from '../../organization';

/**
 * Sign-in takes **either** the employee ID or the email address, in one field.
 *
 * Two boxes, or a toggle between them, would ask the person to classify their
 * own identifier before they may type it; the server already knows which it
 * is from the shape of the string. See App\Http\Requests\Auth\LoginRequest,
 * which is also where the rate limiting lives.
 */
export default defineComponent({
    name: 'Login',
    components: { Head, Link },
    props: {
    },
    setup() {
        const form = useForm({ login: '', password: '', remember: false });
        const submit = () => form.post('/login', { onFinish: () => form.reset('password') });
        const page = usePage();
        const success = computed(() => page.props.flash?.success);

        return { form, org: organization, submit, success };
    },
    template: `
    <Head title="Sign in" />
    <main class="flex flex-1 items-center bg-slate-50 px-5 py-10 sm:px-8 sm:py-14 lg:px-12 lg:py-16">
        <section class="mx-auto grid w-full max-w-[1500px] gap-10 lg:grid-cols-[1.1fr_0.9fr] lg:items-stretch xl:gap-14">
            <aside class="relative h-56 overflow-hidden rounded-2xl bg-gradient-to-br from-[#07559e] via-[#0a6bb8] to-[#00aeef] sm:h-72 md:h-96 lg:h-auto lg:min-h-[560px]">
                <span class="pointer-events-none absolute -right-10 -top-16 h-56 w-56 rounded-full bg-white/10"></span>
                <span class="pointer-events-none absolute -bottom-20 right-24 h-48 w-48 rounded-full bg-white/5"></span>
                <div class="relative flex h-full flex-col justify-end gap-3 p-8 text-white sm:p-11">
                    <img :src="org.logo" :alt="org.logoAlt" class="h-16 w-16 object-contain" />
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-sky-100">{{ org.label }}</p>
                    <h2 class="text-2xl font-bold uppercase tracking-wide sm:text-3xl">{{ org.title }}</h2>
                    <p class="max-w-md text-sm text-sky-100">Upload a document, get a tracking code and a QR Tracker, and follow every view, download and hand-off from there.</p>
                </div>
            </aside>
            <div class="flex items-stretch justify-center">
                <form class="flex min-h-72 w-full flex-col justify-center rounded-2xl border border-slate-200 bg-white p-8 shadow-sm sm:p-11 lg:min-h-[560px]" @submit.prevent="submit">
                    <div class="flex items-center gap-3 border-b border-slate-200 pb-5">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-sky-100 text-lg text-[#07559e]">
                            <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-[#07559e]">Staff access</p>
                            <h1 class="text-xl font-semibold uppercase tracking-wide text-slate-900">Sign in</h1>
                        </div>
                    </div>
                    <p class="mt-5 text-sm text-slate-600">Use the employee ID issued to you by the office, or the email address on your account.</p>
                    <p v-if="success" class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ success }}</p>
                    <!-- One field for both. Which of the two it is comes from
                         the shape of what was typed, on the server, so the
                         person never has to say. -->
                    <label class="mt-8 block">
                        <span class="required-label text-sm font-semibold text-slate-700">Employee ID or email</span>
                        <input v-model="form.login" autocomplete="username" placeholder="e.g. EMP-0001 or you@example.com" class="field" :class="{ 'border-rose-500': form.errors.login }" />
                        <p v-if="form.errors.login" class="error">{{ form.errors.login }}</p>
                    </label>
                    <label class="mt-5 block">
                        <span class="flex items-center justify-between">
                            <span class="required-label text-sm font-semibold text-slate-700">Password</span>
                            <Link href="/forgot-password" class="text-xs font-semibold text-[#07559e] hover:text-[#043d78]">Forgot password?</Link>
                        </span>
                        <input v-model="form.password" type="password" autocomplete="current-password" class="field" :class="{ 'border-rose-500': form.errors.password }" />
                        <p v-if="form.errors.password" class="error">{{ form.errors.password }}</p>
                    </label>
                    <label class="mt-5 flex items-center gap-2.5">
                        <input v-model="form.remember" type="checkbox" class="h-4 w-4 cursor-pointer rounded border-slate-300 accent-[#00aeef] focus:ring-4 focus:ring-sky-100" />
                        <span class="cursor-pointer text-sm font-medium text-slate-700">Remember me</span>
                    </label>
                    <button type="submit" :disabled="form.processing" class="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#00aeef] px-5 py-3 text-sm font-bold uppercase tracking-wide text-white transition hover:bg-[#008dcc] disabled:bg-slate-400">{{ form.processing ? 'Signing in…' : 'Sign in' }}<i v-if="!form.processing" class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
                    <!-- Where an account comes from, since there is no
                         sign-up page to send anybody to. A form that simply
                         refuses an unknown employee ID, saying nothing about
                         how to get one, is a dead end for exactly the person
                         who has just joined the office. -->
                    <p class="mt-6 text-center text-sm text-slate-600">
                        No account yet? Accounts are created by an administrator — ask your office's DocTrack administrator to set one up.
                    </p>
                </form>
            </div>
        </section>
    </main>
`,
});
