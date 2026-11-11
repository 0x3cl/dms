import { Head, Link, router } from '@inertiajs/vue3';
import { defineComponent, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';

/**
 * Track-by-code. On a hit the controller renders documents/show instead, so
 * this page only ever shows the empty form or a not-found message.
 */
export default defineComponent({
    name: 'DocumentTrack',
    components: { AdminShell, Card, Head, Link },
    props: {
        code: { type: String, default: '' },
        notFound: { type: Boolean, default: false },
    },
    setup(props) {
        const code = ref(props.code);
        const submit = () => {
            if (code.value.trim() === '') return;
            router.get('/documents/track', { code: code.value.trim() });
        };

        return { code, submit };
    },
    template: `
    <Head title="Track Document" />
    <AdminShell active="track" title="Track Document">
        <Card class="mx-auto w-full max-w-xl">
            <div>
                <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Documents</p>
                <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">Enter a tracking code</h2>
                <p class="mt-1 text-slate-600">The code is printed beside the QR label, in the form <span class="font-mono text-sm">MEMO-20260101-0001</span>.</p>
            </div>

            <p v-if="notFound" class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">No document carries the code “{{ code }}”. Check it against the printed label.</p>

            <form class="mt-7" @submit.prevent="submit">
                <label class="block">
                    <span class="required-label text-sm font-semibold text-slate-700">Tracking code</span>
                    <input data-tour="track-code" v-model="code" class="field" :class="{ 'border-rose-500': notFound }" placeholder="e.g. MEMO-20260101-0001" />
                </label>
                <button type="submit" class="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#00aeef] px-5 py-3 text-sm font-bold uppercase tracking-wide text-white transition hover:bg-[#008dcc]"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>Track document</button>
            </form>

            <p class="mt-7 border-t border-slate-200 pt-5 text-sm text-slate-600">
                Have the label in front of you?
                <Link href="/scan" class="font-semibold text-[#07559e] hover:text-[#043d78]">Scan it instead</Link>.
            </p>
        </Card>
    </AdminShell>
`,
});
