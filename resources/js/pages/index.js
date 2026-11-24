import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, defineComponent, ref } from 'vue';
import { organization } from '../organization';
import { pushToast } from '../components/ToastStack';

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

        /*
         * Where "Scan the label" goes, and it depends on who is asking.
         *
         * Somebody signed in belongs on the register's own lookup: it is the
         * screen inside the application, it shows them the full record their
         * account entitles them to, and it reads the same label with the same
         * QrScanner. A visitor has no account and no page behind `auth`, so
         * they get the public lookup, which is the door a printed label is
         * for.
         *
         * There is no `/scan` any more. It was a third address for the middle
         * of three ways to name a document — a camera page reachable from
         * here and from the dashboard — answering a question both lookups
         * already answer. One list at three addresses is how the one nobody
         * maintains drifts.
         */
        const scanHref = computed(() => (isAuthenticated.value
            ? '/documents/track?method=scan'
            : '/track?method=scan'));

        /*
         * The public lookup, not the authenticated one.
         *
         * `/documents/track` is behind `auth`, so a visitor typing a code off
         * a printed label landed on a sign-in form — which says nothing about
         * the document and nothing about why. `/track` answers them: a public
         * document is described and previewed, a restricted one states its
         * level and nothing else, and a signed-in reader who may open it is
         * forwarded to the full document page.
         */
        const code = ref('');
        const codeField = ref(null);
        /**
         * Why this box validates at all, and why the words are a toast.
         *
         * Pressing Track on an empty box used to do nothing whatsoever — no
         * message, no movement, nothing disabled — which reads as a broken
         * button rather than as a question not yet answered, and the usual
         * response to a button that does nothing is to press it again.
         *
         * **The complaint is a toast, not a line under the field.** A
         * message beneath the only input on a form says nothing the field's
         * own red ring does not already say about *where*, and it costs the
         * card a reserved line of height that is empty almost all of the
         * time — so the card jumped, or carried a permanent gap. The split
         * is: the ring says which control, the toast says what is wrong.
         * That is only true while there *is* one control. A form with eight
         * fields keeps its per-field messages, because a toast cannot say
         * which of the eight it means.
         *
         * `invalid` survives the toast on purpose: a toast expires after a
         * few seconds and the field is still empty, so the ring is what
         * remains true.
         *
         * Only the empty case is answered here, deliberately. Whether a
         * string could be a tracking code is `TrackingCodeService::MASK`'s
         * question and the server's to answer — the lookup page says "that is
         * not a tracking code" in its own words — and a second copy of that
         * rule in a landing page is the copy that eventually refuses a code
         * that is in fact valid.
         */
        const invalid = ref(false);
        const track = () => {
            if (code.value.trim() === '') {
                invalid.value = true;
                pushToast({ tone: 'error', message: 'Enter the tracking code printed on the label.' });
                codeField.value?.focus();

                return;
            }

            invalid.value = false;
            router.get('/track', { code: code.value.trim() });
        };

        const features = [
            {
                icon: 'fa-solid fa-cloud-arrow-up',
                title: 'Upload once',
                body: 'Every document gets a tracking code and a printable QR Tracker the moment it is uploaded.',
            },
            {
                icon: 'fa-solid fa-qrcode',
                title: 'Scan anywhere',
                body: 'Scan the label from any phone — a public document opens without an account.',
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

        return { code, codeField, features, invalid, isAuthenticated, org: organization, scanHref, track };
    },
    template: `
    <Head title="DocTrack" />
    <main class="flex-1">
        <!-- Hero -->
        <section class="relative overflow-hidden bg-gradient-to-br from-[#07559e] via-[#0a6bb8] to-[#00aeef] text-white">
            <span class="pointer-events-none absolute -right-20 -top-24 h-96 w-96 rounded-full bg-white/10"></span>
            <span class="pointer-events-none absolute -bottom-32 right-40 h-80 w-80 rounded-full bg-white/5"></span>
            <!--
                Two columns from the lg breakpoint up, stacked below it.

                The lookup is the only thing on this page a visitor can *do* —
                everybody else is sent to Staff sign in — so it reads as a
                panel beside the copy rather than as a strip beneath it.

                The widths are the part worth understanding. The right column
                is bounded rather than fractional, because a percentage of an
                1800px container would stretch one input across half a
                monitor; it grows between 26rem and 38rem and stops. The left
                column then takes the rest, which is why the copy carries no
                width cap of its own — it had a max-w-3xl, and on a wide
                screen that left the headline ending a third of the way
                across with a band of empty gradient between it and the card.
                The paragraph keeps a cap, because a line of body text 1200px
                long is unreadable however balanced it looks.
            -->
            <div class="relative mx-auto grid max-w-[1800px] items-center gap-10 px-5 py-16 sm:px-8 sm:py-20 lg:grid-cols-[minmax(0,1fr)_minmax(26rem,38rem)] lg:gap-16 lg:px-12 lg:py-24">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-sky-100">{{ org.line3 }}</p>
                    <h1 class="mt-3 max-w-[18ch] text-3xl font-bold leading-tight sm:text-4xl lg:text-5xl xl:text-6xl">Every document tracked, from upload to hand-off.</h1>
                    <p class="mt-5 max-w-xl text-base text-sky-100 sm:text-lg">DocTrack stamps each office document with a tracking code and a QR Tracker, then records every view, download and acknowledgement that follows.</p>
                    <div class="mt-9 flex flex-wrap gap-3">
                        <Link v-if="isAuthenticated" href="/dashboard" class="inline-flex items-center justify-center gap-2 rounded-xl bg-white px-6 py-3 text-sm font-bold uppercase tracking-wide text-[#07559e] transition hover:bg-sky-50">Go to dashboard<i class="fa-solid fa-arrow-right" aria-hidden="true"></i></Link>
                        <Link v-else href="/login" class="inline-flex items-center justify-center gap-2 rounded-xl bg-white px-6 py-3 text-sm font-bold uppercase tracking-wide text-[#07559e] transition hover:bg-sky-50">Staff sign in<i class="fa-solid fa-arrow-right" aria-hidden="true"></i></Link>
                    </div>
                </div>

                <!--
                    Public tracking lookup.

                    novalidate: the one rule here is ours, said in our own
                    words and in the brand's colours, rather than a browser
                    bubble that looks like nothing else on the page.

                    Stacked rather than input-beside-button, because the
                    column is narrower than the old full-width strip and a
                    26rem row would leave the field too short to show a whole
                    tracking code.
                -->
                <form class="w-full rounded-2xl border border-white/20 bg-white/15 p-6 shadow-xl backdrop-blur-sm sm:p-7" novalidate @submit.prevent="track">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-white/20 text-lg" aria-hidden="true">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </span>
                    <h2 class="mt-4 text-lg font-bold uppercase tracking-wide">Track a document</h2>
                    <p class="mt-1.5 max-w-md text-sm text-sky-100">Enter the code printed beside the QR Tracker to see where a document is.</p>

                    <label for="hero-tracking-code" class="mt-6 block text-xs font-semibold uppercase tracking-[0.14em] text-sky-100">Tracking code</label>

                    <!-- Field and button in a row from sm up, stacked below
                         it. The card is wide enough that a full-width button
                         under a full-width field reads as two stretched bars;
                         at phone width a row would leave the field too short
                         to show a whole tracking code. -->
                    <div class="mt-2 flex flex-col gap-3 sm:flex-row">
                        <input
                            id="hero-tracking-code"
                            ref="codeField"
                            v-model="code"
                            maxlength="64"
                            placeholder="e.g. MEMO-20260101-0001"
                            class="h-12 min-w-0 flex-1 rounded-xl border bg-white/90 px-4 text-sm text-slate-800 placeholder:text-slate-400 outline-none focus:bg-white"
                            :class="invalid ? 'border-rose-400 focus:border-rose-300' : 'border-white/30 focus:border-white'"
                            :aria-invalid="invalid ? 'true' : 'false'"
                            @input="invalid = false" />
                        <button type="submit" class="inline-flex h-12 shrink-0 items-center justify-center gap-2 rounded-xl bg-white px-7 text-sm font-bold uppercase tracking-wide text-[#07559e] transition hover:bg-sky-50">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>Track
                        </button>
                    </div>


                    <!-- The other two ways in, which the lookup page offers
                         with the same component: a camera, or a photo of a
                         label. Here rather than as a second hero button, so
                         the one action on this page stays one action. -->
                    <p class="mt-5 border-t border-white/20 pt-4 text-sm text-sky-100">
                        No code to hand?
                        <Link :href="scanHref" class="font-bold text-white underline-offset-2 hover:underline">Scan the label</Link>
                        instead.
                    </p>
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
