import { Head, Link, router } from '@inertiajs/vue3';
import { defineComponent, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';
import QrScanner from '../../components/QrScanner';

/**
 * A QR label encodes the full tracking URL, but a code typed or pasted by hand
 * is just the code — so both are accepted and normalised to the same visit.
 */
export default defineComponent({
    name: 'DocumentScan',
    components: { AdminShell, Card, Head, Link, QrScanner },
    setup() {
        const scanning = ref(true);
        const lastResult = ref('');

        const open = (decodedText) => {
            lastResult.value = decodedText;
            scanning.value = false;

            // A scan of one of our own labels is a full URL; anything else is
            // treated as a bare tracking code.
            try {
                const url = new URL(decodedText);

                if (url.origin === window.location.origin) {
                    router.visit(url.pathname + url.search);

                    return;
                }
            } catch {
                /* Not a URL — fall through to the bare-code path. */
            }

            router.get('/documents/track', { code: decodedText.trim() });
        };

        const restart = () => {
            lastResult.value = '';
            scanning.value = true;
        };

        return { lastResult, open, restart, scanning };
    },
    template: `
    <Head title="Scan QR Code" />
    <AdminShell active="scan" title="Scan QR Code">
        <Card class="mx-auto w-full max-w-2xl">
            <div>
                <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Documents</p>
                <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">Point the camera at a label</h2>
                <p class="mt-1 text-slate-600">The document opens as soon as its code is read. The camera is released when you leave this page.</p>
            </div>

            <div class="mt-7">
                <QrScanner data-tour="scanner" v-if="scanning" @scanned="open" />
                <div v-else class="rounded-xl border border-slate-200 bg-slate-50 p-6 text-center">
                    <p class="text-sm font-semibold text-slate-700">Code read</p>
                    <p class="mt-2 break-all font-mono text-xs text-slate-500">{{ lastResult }}</p>
                    <button type="button" class="mt-5 inline-flex items-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8]" @click="restart"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i>Scan another</button>
                </div>
            </div>

            <p class="mt-7 border-t border-slate-200 pt-5 text-sm text-slate-600">
                No camera available?
                <Link href="/documents/track" class="font-semibold text-[#07559e] hover:text-[#043d78]">Enter the tracking code instead</Link>.
            </p>
        </Card>
    </AdminShell>
`,
});
