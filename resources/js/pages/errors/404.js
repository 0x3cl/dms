import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, defineComponent } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';

/**
 * What a 404 renders — the branded answer that replaced Laravel's default
 * "404 | NOT FOUND" body.
 *
 * Two things about it are deliberate.
 *
 * **The shell depends on who is asking.** A missing page reached by a
 * signed-in user renders inside `AdminShell`, for the same reason the 403
 * does: the sidebar is the answer to "then what *can* I open?". But the most
 * common 404 in this application is a share link that no longer resolves, and
 * whoever follows one has no account at all — so a signed-out reader gets the
 * standalone framing the share landing and public tracking pages use, with its
 * own `<main class="flex-1">`, because app.js wraps every page in a flex
 * column and Vue cannot fall a class through onto a fragment root.
 *
 * **The copy never distinguishes "revoked" from "never existed".** Every
 * refusal on the share and file routes is a 404 precisely so a token or an
 * identifier cannot be confirmed by probing (see AGENTS.md), and a page that
 * says "this link has expired" would hand back exactly the fact those routes
 * refuse to state. So the share wording lists the possibilities and commits to
 * none of them, and the doors it offers — ask the sender, track the code, sign
 * in — are the real ones.
 */
const SUBJECTS = {
    share: {
        heading: 'This link is not available',
        lead: 'The shared document or file this link pointed to cannot be opened.',
        detail: 'A share link stops working when it is revoked, when it expires, when it reaches its limit of opens, or when the item is reclassified as confidential — and an address with a typo in it looks exactly the same from here. Ask whoever sent it for a new link.',
        icon: 'fa-solid fa-link-slash',
    },
    file: {
        heading: 'Document or file not found',
        lead: 'Nothing here matches that address.',
        detail: 'It may have been deleted, moved to the trash, or shared with someone else — a file you are not a recipient of answers the same way as one that does not exist.',
        icon: 'fa-solid fa-file-circle-question',
    },
    page: {
        heading: 'Page not found',
        lead: 'Nothing here matches that address.',
        detail: 'The page may have moved, or the address may have been mistyped.',
        icon: 'fa-solid fa-compass',
    },
};

/**
 * The standalone framing for a reader with no account. A local component
 * rather than a second copy of the card markup under a `v-else`, so the body
 * below is written once whichever shell wraps it.
 */
const PlainShell = defineComponent({
    name: 'PlainShell',
    template: `
        <main class="flex flex-1 items-center bg-slate-50 px-4 py-12 sm:px-6">
            <div class="mx-auto w-full max-w-xl"><slot /></div>
        </main>
    `,
});

export default defineComponent({
    name: 'Error404',
    components: { AdminShell, Card, Head, Link, PlainShell },
    props: {
        /** Which of SUBJECTS the miss is about — resolved server-side from the path. */
        subject: { type: String, default: 'page' },
        /** An override for the lead line, when a caller has something truer to say. */
        message: { type: String, default: '' },
    },
    setup(props) {
        const page = usePage();
        const signedIn = computed(() => Boolean(page.props.auth?.user));
        const copy = computed(() => SUBJECTS[props.subject] ?? SUBJECTS.page);

        return { signedIn, copy };
    },
    template: `
    <Head :title="copy.heading" />
    <component :is="signedIn ? 'AdminShell' : 'PlainShell'" v-bind="signedIn ? { active: 'dashboard', title: copy.heading } : {}">
        <Card class="rounded-2xl">
            <div class="mx-auto max-w-xl py-6 text-center sm:py-8">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-sky-100 text-3xl text-[#07559e]" aria-hidden="true">
                    <i :class="copy.icon"></i>
                </div>
                <p class="mt-6 text-5xl font-black tracking-tight text-[#07559e]">404</p>
                <h1 class="mt-2 text-2xl font-bold uppercase tracking-wide text-slate-900">{{ copy.heading }}</h1>
                <p class="mt-3 text-base leading-7 text-slate-600">{{ message || copy.lead }}</p>
                <p class="mt-2 text-sm leading-6 text-slate-500">{{ copy.detail }}</p>

                <div class="mt-8 flex flex-wrap justify-center gap-3">
                    <Link v-if="signedIn" href="/dashboard" class="inline-flex items-center justify-center gap-2 rounded-lg bg-[#00aeef] px-5 py-3 text-sm font-bold text-white transition hover:bg-[#009bd8]">
                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to dashboard
                    </Link>
                    <Link v-if="signedIn" href="/files" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-5 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                        <i class="fa-solid fa-folder-tree" aria-hidden="true"></i>My Files
                    </Link>

                    <!-- A reader with no account gets the two doors that are
                         real for them: a tracking code, which is public, and a
                         sign-in for staff who simply were not signed in. -->
                    <Link v-if="!signedIn" href="/track" class="inline-flex items-center justify-center gap-2 rounded-lg bg-[#00aeef] px-5 py-3 text-sm font-bold text-white transition hover:bg-[#009bd8]">
                        <i class="fa-solid fa-magnifying-glass-location" aria-hidden="true"></i>Track a document
                    </Link>
                    <Link v-if="!signedIn" href="/login" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-5 py-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                        <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i>Sign in
                    </Link>
                </div>
            </div>
        </Card>
    </component>
`,
});
