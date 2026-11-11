import { Head, Link, router } from '@inertiajs/vue3';
import { computed, defineComponent, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';
import CountUp from '../../components/CountUp';
import VisibilityBadge from '../../components/VisibilityBadge';
import { formatDay, formatTimestamp } from '../../utils/format-date';

/**
 * The old dashboard was seven Bootstrap tab panes in one Blade file, three of
 * which were whole unpaginated tables (including every document in the
 * system). Those are their own screens now; what is left here is the summary
 * and the quick actions.
 */
export default defineComponent({
    name: 'Dashboard',
    components: { AdminShell, Card, CountUp, Head, Link, VisibilityBadge },
    props: {
        stats: { type: Object, required: true },
        recentUploads: { type: Array, default: () => [] },
        recentReceived: { type: Array, default: () => [] },
        recentActivity: { type: Array, default: () => [] },
    },
    setup(props) {
        const code = ref('');
        const track = () => {
            if (code.value.trim() === '') return;
            router.get('/documents/track', { code: code.value.trim() });
        };

        const tiles = computed(() => [
            { key: 'uploaded', label: 'Documents uploaded', value: props.stats.uploaded, icon: 'fa-solid fa-cloud-arrow-up', tone: 'bg-sky-100 text-[#07559e]' },
            { key: 'received', label: 'Documents received', value: props.stats.received, icon: 'fa-solid fa-inbox', tone: 'bg-emerald-100 text-emerald-700' },
            { key: 'shared', label: 'Shared with named staff', value: props.stats.shared, icon: 'fa-solid fa-user-lock', tone: 'bg-amber-100 text-amber-700' },
            { key: 'open', label: 'Readable office-wide', value: props.stats.open, icon: 'fa-solid fa-globe', tone: 'bg-slate-100 text-slate-600' },
        ]);

        return { code, formatDay, formatTimestamp, tiles, track };
    },
    template: `
    <Head title="Dashboard" />
    <AdminShell active="dashboard" title="Dashboard">
        <div class="space-y-5">
            <!-- Quick actions -->
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Link href="/documents/upload" class="group flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-sky-100 text-lg text-[#07559e]"><i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i></span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-bold uppercase tracking-wide text-slate-800">Upload a document</span>
                        <span class="block text-xs text-slate-500">Get a tracking code and a QR label.</span>
                    </span>
                    <i class="fa-solid fa-arrow-right text-slate-300 transition group-hover:translate-x-1 group-hover:text-[#00aeef]" aria-hidden="true"></i>
                </Link>
                <Link href="/scan" class="group flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-sky-100 text-lg text-[#07559e]"><i class="fa-solid fa-qrcode" aria-hidden="true"></i></span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-bold uppercase tracking-wide text-slate-800">Scan a QR code</span>
                        <span class="block text-xs text-slate-500">Open a document from its printed label.</span>
                    </span>
                    <i class="fa-solid fa-arrow-right text-slate-300 transition group-hover:translate-x-1 group-hover:text-[#00aeef]" aria-hidden="true"></i>
                </Link>
                <form class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" @submit.prevent="track">
                    <label class="block text-xs font-bold uppercase tracking-wide text-slate-500">Track by code</label>
                    <div class="mt-2 flex gap-2">
                        <input v-model="code" placeholder="e.g. MEMO-20260101-0001" class="h-[42px] min-w-0 flex-1 rounded-lg border border-slate-300 px-3 text-sm outline-none focus:border-[#00aeef] focus:ring-4 focus:ring-sky-100" />
                        <button type="submit" class="inline-flex h-[42px] shrink-0 items-center gap-2 rounded-lg bg-[#00aeef] px-4 text-sm font-bold text-white transition hover:bg-[#009bd8]"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></button>
                    </div>
                </form>
            </div>

            <!-- KPI strip -->
            <div class="grid divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm sm:grid-cols-2 xl:grid-cols-4 xl:divide-x xl:divide-y-0">
                <div v-for="tile in tiles" :key="tile.key" class="flex items-center gap-4 p-5">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-lg" :class="tile.tone"><i :class="tile.icon" aria-hidden="true"></i></span>
                    <span>
                        <span class="block text-2xl font-bold tabular-nums text-slate-900"><CountUp :value="tile.value" /></span>
                        <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">{{ tile.label }}</span>
                    </span>
                </div>
            </div>

            <div class="grid gap-5 xl:grid-cols-2">
                <Card>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Your uploads</p>
                            <h2 class="mt-1 text-xl font-bold uppercase tracking-wide text-slate-900">Recently uploaded</h2>
                        </div>
                        <Link href="/documents" class="text-sm font-bold text-[#07559e] hover:text-[#043d78]">View all</Link>
                    </div>
                    <ul class="mt-6 divide-y divide-slate-100">
                        <li v-for="document in recentUploads" :key="document.id" class="py-3">
                            <Link :href="'/documents/view/' + document.code" class="flex flex-wrap items-center justify-between gap-3 transition hover:text-[#07559e]">
                                <span class="min-w-0">
                                    <span class="block truncate font-semibold text-slate-800">{{ document.title }}</span>
                                    <span class="block text-xs text-slate-500">{{ document.code }} · {{ formatDay(document.created_at) }}</span>
                                </span>
                                <VisibilityBadge class="shrink-0" :visibility="document.visibility" />
                            </Link>
                        </li>
                        <li v-if="recentUploads.length === 0" class="py-10 text-center text-sm text-slate-500">You have not uploaded anything yet.</li>
                    </ul>
                </Card>

                <Card>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Handled by you</p>
                            <h2 class="mt-1 text-xl font-bold uppercase tracking-wide text-slate-900">Recently received</h2>
                        </div>
                        <Link href="/documents?tab=received" class="text-sm font-bold text-[#07559e] hover:text-[#043d78]">View all</Link>
                    </div>
                    <ul class="mt-6 divide-y divide-slate-100">
                        <li v-for="document in recentReceived" :key="document.id" class="py-3">
                            <Link :href="'/documents/view/' + document.code" class="flex flex-wrap items-center justify-between gap-3 transition hover:text-[#07559e]">
                                <span class="min-w-0">
                                    <span class="block truncate font-semibold text-slate-800">{{ document.title }}</span>
                                    <span class="block text-xs text-slate-500">{{ document.code }} · from {{ document.user?.name ?? document.uploader_name ?? 'Unknown' }}</span>
                                </span>
                                <VisibilityBadge class="shrink-0" :visibility="document.visibility" />
                            </Link>
                        </li>
                        <li v-if="recentReceived.length === 0" class="py-10 text-center text-sm text-slate-500">Nothing has come your way yet.</li>
                    </ul>
                </Card>
            </div>

            <Card>
                <div>
                    <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Audit trail</p>
                    <h2 class="mt-1 text-xl font-bold uppercase tracking-wide text-slate-900">Your recent activity</h2>
                </div>
                <ol class="mt-6 space-y-4">
                    <li v-for="entry in recentActivity" :key="entry.id" class="flex gap-4">
                        <span class="mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-sky-100 text-xs text-[#07559e]"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-slate-800">{{ entry.action_label }}</span>
                            <span class="block text-xs text-slate-500">{{ entry.document?.title ?? 'A document' }} · {{ formatTimestamp(entry.occurred_at ?? entry.created_at) }}</span>
                            <span v-if="entry.remarks" class="mt-1 block whitespace-pre-line text-xs italic text-slate-500">{{ entry.remarks }}</span>
                        </span>
                    </li>
                    <li v-if="recentActivity.length === 0" class="py-10 text-center text-sm text-slate-500">No activity recorded yet.</li>
                </ol>
            </Card>
        </div>
    </AdminShell>
`,
});
