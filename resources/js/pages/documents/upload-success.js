import { Head, Link } from '@inertiajs/vue3';
import { defineComponent, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';
import { pushToast } from '../../components/ToastStack';
import { registerHref } from '../../registerReturn';

/**
 * The upload confirmation, with the QR Tracker to print.
 *
 * Full width, like every other card under AdminShell, and **two columns**
 * rather than one centred stack. The stack was written when the card was
 * capped at `max-w-2xl`; at full width it left a label and four buttons
 * marooned in the middle of the page with empty flanks either side, which is
 * the same imbalance a narrow card avoids by being narrow.
 *
 * So the width is given something to do. The left column is the **label** —
 * the one thing on this page that gets printed and stuck to a folder, kept
 * as a self-contained panel at a fixed width so it reads as an object rather
 * than as a region of the card. The right column is everything you might do
 * next: the code, what the label resolves to, and the actions.
 *
 * It collapses to one column below `lg`, label first, because on a phone the
 * label is still the thing you came for.
 *
 * The symbol is server-rendered **SVG** from `App\Services\Documents\QrLabelService`
 * — sharp at any size and no second request — while Download fetches the
 * stored PNG. Both encode the same target; they differ only in what each is
 * good at. Download is a plain `<a>`, because a file response is not
 * something an Inertia visit can follow.
 */
export default defineComponent({
    name: 'DocumentUploadSuccess',
    components: { AdminShell, Card, Head, Link },
    props: {
        code: { type: String, required: true },
        fileName: { type: String, required: true },
        qrCodeSvg: { type: String, required: true },
        trackingUrl: { type: String, required: true },
        /**
         * Whether the label's target opens the document for somebody with no
         * account — true only when it is a share link, which is minted
         * automatically for a public document and for no other level.
         */
        opensWithoutAccount: { type: Boolean, default: false },
        visibilityLabel: { type: String, default: '' },
    },
    setup(props) {
        /**
         * Which button was last copied, for the two seconds its icon shows a
         * tick. The *words* are a toast — a clipboard copy reaches no flash
         * and has nowhere else to be said — and the tick is only there so the
         * button you pressed acknowledges the press.
         */
        const copied = ref('');

        const copy = async (what) => {
            const value = what === 'link' ? props.trackingUrl : props.code;

            try {
                await navigator.clipboard.writeText(value);
                copied.value = what;
                setTimeout(() => { if (copied.value === what) copied.value = ''; }, 2000);
                pushToast({ message: what === 'link' ? 'Link copied.' : 'Tracking code copied.' });
            } catch {
                // Clipboard access can be refused outright — an insecure
                // origin, or a browser that asks. Both values are on screen,
                // so this says so rather than failing silently.
                pushToast({ tone: 'error', message: 'Your browser would not let the page copy that. Select it and copy by hand.' });
            }
        };

        return { backHref: registerHref(), copied, copy };
    },
    template: `
    <Head title="Document uploaded" />
    <AdminShell active="documents-create" title="Document uploaded">
        <Card class="w-full">
            <!-- The outcome, stated once and across the top rather than
                 above one of the columns. -->
            <div class="flex items-start gap-4 border-b border-slate-200 pb-6">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-xl text-emerald-700"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
                <div class="min-w-0">
                    <h2 class="text-2xl font-bold uppercase tracking-wide text-slate-900">Upload complete</h2>
                    <!-- Two sentences, because the label does two different
                         things. For a public document it carries a link that
                         opens the file for whoever scans it; for a restricted
                         one it carries the tracking code, and a scanner is
                         asked to sign in. Saying the first about a private
                         document would tell its uploader that the folder on
                         their desk is readable by anyone who photographs it. -->
                    <p v-if="opensWithoutAccount" class="mt-1 max-w-3xl text-slate-600">Print the label and attach it to the physical document. Scanning it opens this document through its share link, which you can narrow or revoke from the document's own page.</p>
                    <p v-else class="mt-1 max-w-3xl text-slate-600">Print the label and attach it to the physical document. This document is {{ visibilityLabel.toLowerCase() }}, so scanning it opens the file for you and the people you tagged, and tells anybody else only that it exists and is {{ visibilityLabel.toLowerCase() }}.</p>
                </div>
            </div>

            <div class="mt-7 grid gap-8 lg:grid-cols-[20rem_minmax(0,1fr)] lg:gap-10">
                <!-- The label, as an object: a fixed-width panel, centred on
                     itself, so it reads as the thing that gets printed. -->
                <div class="flex flex-col items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-6">
                    <div class="rounded-xl bg-white p-4 [&>svg]:h-52 [&>svg]:w-52" v-html="qrCodeSvg"></div>
                    <p class="font-mono text-sm font-bold text-slate-800">{{ code }}</p>
                    <a :href="'/documents/qr/download/' + fileName" class="mt-1 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8]"><i class="fa-solid fa-download" aria-hidden="true"></i>Download QR Tracker</a>
                </div>

                <!-- Everything you might do next. -->
                <div class="flex flex-col gap-6">
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Tracking code</dt>
                        <dd class="mt-2 flex flex-wrap items-center gap-3">
                            <span class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-sm font-bold text-slate-800">{{ code }}</span>
                            <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="copy('code')">
                                <i :class="copied === 'code' ? 'fa-solid fa-check' : 'fa-solid fa-copy'" aria-hidden="true"></i>Copy
                            </button>
                        </dd>
                        <p class="mt-2 text-sm text-slate-500">Printed under the symbol. Anyone with an account can look a document up by it.</p>
                    </div>

                    <!-- What the label resolves to, printed on screen because
                         it is the link the symbol carries rather than a
                         restatement of the code. -->
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">What the label opens</dt>
                        <dd class="mt-2 flex flex-wrap items-start gap-3">
                            <span class="min-w-0 flex-1 break-all rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">{{ trackingUrl }}</span>
                            <button type="button" class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="copy('link')">
                                <i :class="copied === 'link' ? 'fa-solid fa-check' : 'fa-solid fa-link'" aria-hidden="true"></i>Copy
                            </button>
                        </dd>
                        <p v-if="opensWithoutAccount" class="mt-2 text-sm text-slate-500">A share link, so it opens for somebody with no account — and can be revoked.</p>
                        <p v-else class="mt-2 text-sm text-slate-500">A share link, and it checks who is opening it: the people you tagged are taken straight to the document, and anybody else is told only its classification.</p>
                    </div>

                    <div class="mt-auto flex flex-wrap gap-3 border-t border-slate-200 pt-6">
                        <Link :href="'/documents/view/' + code" class="inline-flex items-center justify-center gap-2 rounded-lg bg-[#07559e] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#043d78]"><i class="fa-solid fa-eye" aria-hidden="true"></i>Open document</Link>
                        <Link href="/documents/upload" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"><i class="fa-solid fa-plus" aria-hidden="true"></i>Upload another</Link>
                        <Link :href="backHref" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"><i class="fa-solid fa-list" aria-hidden="true"></i>Back to documents</Link>
                    </div>
                </div>
            </div>
        </Card>
    </AdminShell>
`,
});
