import { Head, Link } from '@inertiajs/vue3';
import { defineComponent, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';

export default defineComponent({
    name: 'DocumentUploadSuccess',
    components: { AdminShell, Card, Head, Link },
    props: {
        code: { type: String, required: true },
        fileName: { type: String, required: true },
        qrCodeSvg: { type: String, required: true },
        trackingUrl: { type: String, required: true },
    },
    setup(props) {
        const copied = ref(false);
        const copy = async () => {
            try {
                await navigator.clipboard.writeText(props.code);
                copied.value = true;
                setTimeout(() => { copied.value = false; }, 2000);
            } catch {
                /* Clipboard access can be refused; the code is on screen anyway. */
            }
        };

        return { copied, copy };
    },
    template: `
    <Head title="Document uploaded" />
    <AdminShell active="documents-create" title="Document uploaded">
        <Card class="mx-auto w-full max-w-2xl text-center">
            <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-2xl text-emerald-700"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
            <h2 class="mt-5 text-2xl font-bold uppercase tracking-wide text-slate-900">Upload complete</h2>
            <p class="mt-2 text-slate-600">Print the label below and attach it to the physical document.</p>

            <div class="mt-7 inline-flex flex-col items-center gap-3 rounded-2xl border border-slate-200 bg-white p-6">
                <!-- Server-rendered SVG from simple-qrcode. -->
                <div class="[&>svg]:h-56 [&>svg]:w-56" v-html="qrCodeSvg"></div>
                <p class="font-mono text-sm font-bold text-slate-800">{{ code }}</p>
            </div>

            <div class="mt-7 flex flex-wrap justify-center gap-3">
                <a :href="'/documents/qr/download/' + fileName" class="inline-flex items-center justify-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8]"><i class="fa-solid fa-download" aria-hidden="true"></i>Download QR label</a>
                <button type="button" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="copy"><i :class="copied ? 'fa-solid fa-check' : 'fa-solid fa-copy'" aria-hidden="true"></i>{{ copied ? 'Copied' : 'Copy code' }}</button>
                <Link :href="'/documents/view/' + code" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"><i class="fa-solid fa-eye" aria-hidden="true"></i>Open document</Link>
                <Link href="/documents/upload" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"><i class="fa-solid fa-plus" aria-hidden="true"></i>Upload another</Link>
            </div>

            <p class="mt-7 break-all border-t border-slate-200 pt-5 text-xs text-slate-500">{{ trackingUrl }}</p>
        </Card>
    </AdminShell>
`,
});
