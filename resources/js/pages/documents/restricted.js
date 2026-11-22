import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, defineComponent, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';
import VisibilityBadge from '../../components/VisibilityBadge';
import { registerHref } from '../../registerReturn';

/**
 * A document somebody may not read, answered as a page.
 *
 * The refusal used to be a redirect to the dashboard carrying an `error`
 * flash, which opened the modal over whatever happened to be there. That is a
 * worse answer than it looks: the reader is thrown off the thing they asked
 * for, the explanation arrives on a page that has nothing to do with it, and
 * the URL in the address bar is no longer the document — so a reload, a
 * bookmark or forwarding the link to the right person all lose the one piece
 * of information the whole exchange was about.
 *
 * So the code stays in the address bar and the answer is a page about *this*
 * document. What it may say is unchanged and is still the narrow thing the
 * resolver permits: the tracking code, the classification, and why the door
 * is shut. Never the title, the uploader or the type — telling somebody what
 * a document they may not read is *about* is the disclosure the refusal
 * exists to prevent.
 *
 * The two refusals stay distinct, because `refusalMessage()` already
 * distinguishes them on the server: somebody never tagged is invited to ask,
 * while somebody the uploader *removed* is told so rather than being left to
 * chase a decision as though it were an oversight.
 */
export default defineComponent({
    name: 'DocumentRestricted',
    components: { AdminShell, Card, Head, Link, VisibilityBadge },
    props: {
        code: { type: String, required: true },
        visibility: { type: String, required: true },
        /** The server's refusal, which distinguishes "never tagged" from "removed". */
        message: { type: String, required: true },
        /** Whether this account may ask for access at all. */
        canRequest: { type: Boolean, default: false },
        /** True when this person has already asked and nobody has answered yet. */
        pending: { type: Boolean, default: false },
    },
    setup(props) {
        const asking = ref(false);
        const form = useForm({ code: props.code, message: '' });

        const submit = () => form.post('/access-requests', {
            preserveScroll: true,
            onSuccess: () => { asking.value = false; },
        });

        /*
         * The lock sits next to the VisibilityBadge, so it takes the level's
         * own colour — orange for private, red for confidential. It was a
         * fixed amber, which put a warning tint beside a red badge on the one
         * page whose entire job is to state the classification: two different
         * answers to one question, side by side. Public never reaches this
         * page, and an unknown value takes the most restrictive tint for the
         * same reason the badge does.
         */
        const levelTint = computed(() => (props.visibility === 'private'
            ? 'bg-orange-100 text-orange-700'
            : 'bg-red-100 text-red-700'));

        return { asking, backHref: registerHref(), form, levelTint, submit };
    },
    template: `
    <AdminShell title="Documents" subtitle="Restricted document">
        <Head title="Restricted document" />

        <!-- Card straight inside the shell, with no wrapper and no
             max-width, because that is what every other page in the
             application does. It had a centred max-w-2xl column of its
             own, which made the one page somebody lands on by accident the
             only one that did not line up with the rest. -->
        <Card class="rounded-2xl">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <span class="inline-flex h-14 w-14 items-center justify-center rounded-full text-2xl" :class="levelTint" aria-hidden="true">
                    <i class="fa-solid fa-lock"></i>
                </span>
                <VisibilityBadge :visibility="visibility" />
            </div>

            <h1 class="mt-5 text-2xl font-bold uppercase tracking-wide text-slate-900">You cannot open this document</h1>
            <p class="mt-2 leading-7 text-slate-600">{{ message }}</p>
            <p class="mt-3 font-mono text-sm font-bold text-slate-500">{{ code }}</p>

            <!-- Deliberately nothing about what the document is. The
                 code is what the reader already typed or scanned, and
                 the level is what they are being refused by. -->

            <!-- A statement, not a branch of the actions. It used
                 to be the first arm of the same v-if chain as the
                 buttons, so a reader with a request pending was told
                 so and simultaneously lost the only way off the page
                 that was not the browser's Back button. -->
            <div v-if="pending" class="mt-6 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm font-semibold text-[#07559e]">
                <i class="fa-solid fa-hourglass-half mr-1.5" aria-hidden="true"></i>You have already asked for access to this document. The person who uploaded it has not answered yet.
            </div>

            <!-- The ask, inline. The offer used to live on the
                 dashboard the redirect landed on, which meant the
                 reader had to notice it after being moved somewhere
                 else; here it is on the page about the document they
                 wanted. -->
            <form v-if="canRequest && asking && !pending" class="mt-6 border-t border-slate-200 pt-6" @submit.prevent="submit">
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Why do you need it? (optional)</span>
                    <textarea v-model="form.message" rows="3" maxlength="1000" class="field" placeholder="e.g. I am preparing the endorsement for this request."></textarea>
                    <p v-if="form.errors.message" class="error">{{ form.errors.message }}</p>
                </label>
                <p v-if="form.errors.code" class="error mt-2">{{ form.errors.code }}</p>
                <div class="mt-4 flex flex-wrap gap-3">
                    <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8] disabled:opacity-60">
                        <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>{{ form.processing ? 'Sending…' : 'Send request' }}
                    </button>
                    <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="asking = false">Cancel</button>
                </div>
            </form>

            <div v-else class="mt-6 flex flex-wrap gap-3 border-t border-slate-200 pt-6">
                <button v-if="canRequest && !pending" type="button" class="inline-flex items-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8]" @click="asking = true">
                    <i class="fa-solid fa-hand" aria-hidden="true"></i>Request access
                </button>
                <Link :href="backHref" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to documents
                </Link>
            </div>
        </Card>
    </AdminShell>
`,
});
