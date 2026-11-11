import { Head, Link } from '@inertiajs/vue3';
import { defineComponent } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';

/**
 * What a permission failure renders on a page visit.
 *
 * Inside AdminShell on purpose: the sidebar is the answer to "then what *can*
 * I open?", and dropping the user onto a bare page would take that away at the
 * moment they most need it. A failed form post does not come here — it goes
 * back with an error flash, which bootstrap/app.php arranges.
 */
export default defineComponent({
    name: 'Error403',
    components: { AdminShell, Card, Head, Link },
    props: {
        message: { type: String, default: 'You do not have permission to open that page.' },
    },
    template: `
    <Head title="Not permitted" />
    <AdminShell active="dashboard" title="Not permitted">
        <Card class="rounded-2xl">
            <div class="mx-auto max-w-xl py-8 text-center">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-amber-100 text-3xl text-amber-600" aria-hidden="true">
                    <i class="fa-solid fa-lock"></i>
                </div>
                <h2 class="mt-6 text-2xl font-bold uppercase tracking-wide text-slate-900">Not permitted</h2>
                <p class="mt-3 text-base leading-7 text-slate-600">{{ message }}</p>
                <p class="mt-1 text-sm text-slate-500">If you need it for your work, ask an administrator to adjust your role.</p>
                <Link href="/dashboard" class="mt-8 inline-flex items-center justify-center gap-2 rounded-lg bg-[#00aeef] px-5 py-3 text-sm font-bold text-white transition hover:bg-[#009bd8]"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to dashboard</Link>
            </div>
        </Card>
    </AdminShell>
`,
});
