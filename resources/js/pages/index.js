import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, defineComponent, ref } from 'vue';
import { organization } from '../organization';

/**
 * The public landing page.
 *
 * `<main>` carries `flex-1` itself: Inertia pages render <Head> and <main> as
 * sibling roots, so Vue cannot fall a class through onto it from app.js, and
 * without it a short page leaves a white gap above the footer.
 */
export default defineComponent({
    name: 'Landing',
    components: { Head, Link },
    setup() {
        const page = usePage();
        const isAuthenticated = computed(() => Boolean(page.props.auth?.user));

        const code = ref('');
        const track = () => {
            if (code.value.trim() === '') return;
            router.get('/documents/track', { code: code.value.trim() });
        };

        const features = [
            {
                icon: 'fa-solid fa-cloud-arrow-up',
                title: 'Upload once',
                body: 'Every document gets a tracking code and a printable QR label the moment it is uploaded.',
            },
            {
                icon: 'fa-solid fa-qrcode',
                title: 'Scan anywhere',
                body: 'Scan the label from any phone to open the document and see where it has been.',
            },
            {
                icon: 'fa-solid fa-route',
                title: 'Follow the trail',
                body: 'Views, downloads, acknowledgements and hand-offs are all recorded against the document.',
            },
            {
                icon: 'fa-solid fa-lock',
                title: 'Share deliberately',
                body: 'A document is public to anyone with the code, or private to the people you name.',
            },
        ];

        return { code, features, isAuthenticated, org: organization, track };
    },
    template: `
    <Head title="DocTrack" />
    <main class="flex-1">
        <!-- Hero -->
        <section class="relative overflow-hidden bg-gradient-to-br from-[#07559e] via-[#0a6bb8] to-[#00aeef] text-white">
            <span class="pointer-events-none absolute -right-20 -top-24 h-96 w-96 rounded-full bg-white/10"></span>
            <span class="pointer-events-none absolute -bottom-32 right-40 h-80 w-80 rounded-full bg-white/5"></span>
            <div class="relative mx-auto max-w-[1800px] px-5 py-16 sm:px-8 sm:py-20 lg:px-12 lg:py-24">
                <div class="max-w-3xl">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-sky-100">{{ org.line3 }}</p>
                    <h1 class="mt-3 text-3xl font-bold leading-tight sm:text-4xl lg:text-5xl">Every document tracked, from upload to hand-off.</h1>
                    <p class="mt-5 max-w-2xl text-base text-sky-100 sm:text-lg">DocTrack stamps each office document with a tracking code and a QR label, then records every view, download and acknowledgement that follows.</p>
                    <div class="mt-9 flex flex-wrap gap-3">
                        <Link v-if="isAuthenticated" href="/dashboard" class="inline-flex items-center justify-center gap-2 rounded-xl bg-white px-6 py-3 text-sm font-bold uppercase tracking-wide text-[#07559e] transition hover:bg-sky-50">Go to dashboard<i class="fa-solid fa-arrow-right" aria-hidden="true"></i></Link>
                        <Link v-else href="/login" class="inline-flex items-center justify-center gap-2 rounded-xl bg-white px-6 py-3 text-sm font-bold uppercase tracking-wide text-[#07559e] transition hover:bg-sky-50">Staff sign in<i class="fa-solid fa-arrow-right" aria-hidden="true"></i></Link>
                        <Link href="/scan" class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/40 px-6 py-3 text-sm font-bold uppercase tracking-wide text-white transition hover:bg-white/10"><i class="fa-solid fa-qrcode" aria-hidden="true"></i>Scan a label</Link>
                    </div>
                </div>

                <!-- Public tracking lookup -->
                <form class="mt-12 max-w-xl rounded-2xl bg-white/15 p-5 backdrop-blur-sm" @submit.prevent="track">
                    <label class="block text-xs font-semibold uppercase tracking-[0.14em] text-sky-100">Track a document</label>
                    <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                        <input v-model="code" placeholder="Enter a tracking code" class="h-12 min-w-0 flex-1 rounded-xl border border-white/30 bg-white/90 px-4 text-sm text-slate-800 placeholder:text-slate-400 outline-none focus:border-white focus:bg-white" />
                        <button type="submit" class="inline-flex h-12 shrink-0 items-center justify-center gap-2 rounded-xl bg-[#07559e] px-6 text-sm font-bold uppercase tracking-wide text-white transition hover:bg-[#043d78]"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>Track</button>
                    </div>
                </form>
            </div>
        </section>

        <!-- Features -->
        <section class="bg-slate-50 py-16 sm:py-20">
            <div class="mx-auto max-w-[1800px] px-5 sm:px-8 lg:px-12">
                <div class="max-w-2xl">
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#07559e]">How it works</p>
                    <h2 class="mt-2 text-2xl font-bold uppercase tracking-wide text-slate-900 sm:text-3xl">A paper trail that keeps itself</h2>
                </div>
                <div class="mt-10 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                    <article v-for="feature in features" :key="feature.title" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-sky-100 text-xl text-[#07559e]"><i :class="feature.icon" aria-hidden="true"></i></span>
                        <h3 class="mt-5 text-sm font-bold uppercase tracking-wide text-slate-900">{{ feature.title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ feature.body }}</p>
                    </article>
                </div>
            </div>
        </section>
    </main>
`,
});
