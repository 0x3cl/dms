import { router, usePage } from '@inertiajs/vue3';
import { computed, defineComponent, ref, watch } from 'vue';
import Badge from './Badge';
import ConfirmActionModal from './ConfirmActionModal';
import UserAvatar from './UserAvatar';
import { pushToast } from './ToastStack';
import { formatTimestamp } from '../utils/format-date';

/**
 * Requests to be let into a document.
 *
 * A reader refused a private or confidential document used to be told only
 * that they were not on its list — true, and a dead end. The only way forward
 * was to work out who filed it and ask by other means, which mostly means the
 * work stops.
 *
 * Three tabs, because there are three questions and they belong to different
 * people: what is waiting on **me**, what I have already let through, and what
 * I am waiting on. Incoming leads, because it is the only one with an action
 * attached and the only one that should ever carry a badge.
 *
 * The rows are fetched when the modal opens rather than shipped as page props,
 * the way the share drawer fetches its own state: this is a worklist somebody
 * opens occasionally, not something every page should carry. Only the pending
 * **count** is a shared prop, because the badge has to be right before anybody
 * clicks.
 */
export default defineComponent({
    name: 'AccessRequestsMenu',
    components: { Badge, ConfirmActionModal, UserAvatar },
    setup() {
        const page = usePage();

        const open = ref(false);
        const tab = ref('incoming');
        const loading = ref(false);
        const deciding = ref(null);
        const state = ref({ incoming: [], granted: [], outgoing: [] });

        const permitted = computed(() => (page.props.auth?.permissions ?? []).includes('access-requests.read'));
        const pending = computed(() => page.props.accessRequests?.pending ?? 0);

        const rows = computed(() => state.value[tab.value] ?? []);

        const load = async () => {
            loading.value = true;

            try {
                const response = await fetch('/access-requests', {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                if (!response.ok) throw new Error(String(response.status));

                state.value = await response.json();
            } catch {
                pushToast({ title: 'Could not load requests', message: 'Try again in a moment.', tone: 'error' });
            } finally {
                loading.value = false;
            }
        };

        watch(open, (isOpen) => {
            if (isOpen) load();
        });

        /*
         * Opened by the notification that is about it.
         *
         * A bell row saying "Emilio is asking to open ADOR-20261123-0001" is
         * a thing to answer, and clicking it used to land on the document
         * register with nothing about the request on screen — the reader had
         * to work out for themselves that the answer lives behind a header
         * icon. `DocumentAccessRequested` has always carried
         * `?requests=incoming` on its URL and nothing read it; this is the
         * half that was missing, which means every notification already sent
         * starts working rather than only the ones sent from now on.
         *
         * The tab comes from the parameter rather than being assumed, so a
         * later notification can point at a different one — a decision on
         * something you asked for belongs in Outgoing, not Incoming.
         *
         * It runs on every navigation, not only on mount: the menu lives in
         * AdminShell, which persists across Inertia visits, so a mounted hook
         * would fire once per full page load and never again.
         */
        const openFromUrl = () => {
            if (! permitted.value) return;

            const asked = new URLSearchParams(window.location.search).get('requests');

            if (! asked) return;

            tab.value = ['incoming', 'granted', 'outgoing'].includes(asked) ? asked : 'incoming';
            open.value = true;

            /*
             * The parameter is cleared once it has been acted on, so a
             * reload or a Back does not reopen a panel somebody has just
             * closed. `replaceState`, not an Inertia visit: the page is
             * already the right one and a visit would refetch it.
             */
            const url = new URL(window.location.href);
            url.searchParams.delete('requests');
            window.history.replaceState({}, '', url);
        };

        openFromUrl();
        router.on('navigate', openFromUrl);

        /**
         * Grant or decline.
         *
         * `preserveState` keeps the modal open, and the list is refetched
         * rather than spliced: the server decides what each tab now contains,
         * and a granted row moves between two of them.
         */
        /*
         * Both answers are confirmed, and the grant is the reason why.
         *
         * Granting writes a `document_recipients` row — it is the one action
         * in this panel that hands somebody a document, and the two buttons
         * sit side by side in a list that is read quickly. Declining is
         * confirmed as well rather than only the grant, because a dialog
         * that appears on one button and not its neighbour trains the eye to
         * expect nothing and makes the one that does appear a surprise to be
         * clicked through.
         *
         * The pending answer is held rather than the dialog being told which
         * row it is about, so the wording can name the person and the
         * document: "are you sure" over an unnamed row is a question nobody
         * can actually check.
         */
        const pendingDecision = ref(null);

        /*
         * Clearing one's own request.
         *
         * Confirmed like the other two, and for a sharper reason here: the
         * same button means "withdraw this" while the request is open and
         * "clear this away" once it has been answered, so the dialog is
         * where those two are told apart in words.
         */
        const pendingDismissal = ref(null);

        const askToDismiss = (row) => { pendingDismissal.value = row; };

        const confirmDismissal = () => {
            const row = pendingDismissal.value;

            if (! row) return;

            pendingDismissal.value = null;
            deciding.value = row.id;

            router.delete(`/access-requests/${row.id}`, {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    load();
                    router.reload({ only: ['accessRequests', 'notifications'] });
                },
                onFinish: () => { deciding.value = null; },
            });
        };

        const askToDecide = (row, decision) => { pendingDecision.value = { row, decision }; };

        const confirmDecision = () => {
            if (! pendingDecision.value) return;

            const { row, decision } = pendingDecision.value;

            pendingDecision.value = null;
            decide(row, decision);
        };

        const decide = (row, decision) => {
            deciding.value = row.id;

            router.patch(`/access-requests/${row.id}`, { decision }, {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    load();
                    router.reload({ only: ['accessRequests', 'notifications'] });
                },
                onFinish: () => { deciding.value = null; },
            });
        };

        const tabs = [
            { key: 'incoming', label: 'Incoming requests' },
            { key: 'granted', label: 'Granted' },
            { key: 'outgoing', label: 'Outgoing requests' },
        ];

        return {
            askToDecide, askToDismiss, confirmDecision, confirmDismissal, decide, deciding,
            formatTimestamp, load, loading, open, pending, pendingDecision, pendingDismissal,
            permitted, rows, state, tab, tabs,
        };
    },
    template: `
    <div v-if="permitted" class="relative">
        <button
            type="button"
            class="relative flex h-10 w-10 items-center justify-center rounded-lg text-slate-600 transition hover:bg-slate-100 hover:text-[#07559e]"
            :title="pending ? pending + ' request' + (pending === 1 ? '' : 's') + ' waiting on you' : 'Access requests'"
            aria-label="Access requests"
            data-tour="access-requests"
            @click="open = true">
            <i class="fa-solid fa-user-clock" aria-hidden="true"></i>
            <span v-if="pending" class="absolute -right-0.5 -top-0.5 flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-rose-600 px-1 text-[10px] font-bold text-[#ffffff]">
                {{ pending > 9 ? '9+' : pending }}
            </span>
        </button>

        <ConfirmActionModal
            :open="open"
            title="Access requests"
            message="Documents people have asked to open, and the ones you have asked for."
            :cancel-label="null"
            confirm-label="Close"
            icon="fa-solid fa-user-clock"
            wider
            @close="open = false"
            @confirm="open = false">
            <div class="text-left">
                <div class="flex gap-1 rounded-xl bg-slate-100 p-1" role="tablist">
                    <button
                        v-for="option in tabs"
                        :key="option.key"
                        type="button"
                        role="tab"
                        :aria-selected="tab === option.key"
                        class="flex-1 rounded-lg px-3 py-2 text-sm font-bold transition"
                        :class="tab === option.key ? 'bg-white text-[#07559e] shadow-sm' : 'text-slate-600 hover:text-slate-800'"
                        @click="tab = option.key">
                        {{ option.label }}<span v-if="state[option.key].length" class="ml-1.5 text-xs font-semibold text-slate-400">{{ state[option.key].length }}</span>
                    </button>
                </div>

                <p v-if="loading" class="flex items-center gap-3 py-10 text-sm text-slate-500">
                    <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>Loading…
                </p>

                <ul v-else-if="rows.length" class="mt-4 space-y-3">
                    <li v-for="row in rows" :key="row.id" class="rounded-xl border border-slate-200 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-3">
                                <UserAvatar
                                    v-if="tab !== 'outgoing'"
                                    :person="{ name: row.requester_name }"
                                    class="!h-10 !w-10 !text-xs" />
                                <div class="min-w-0">
                                    <p class="max-w-[300px] truncate font-bold text-slate-800" :title="row.document_title">{{ row.document_title }}</p>
                                    <p class="font-mono text-xs text-slate-500">{{ row.document_code }}</p>
                                    <p class="mt-1 text-xs text-slate-600">
                                        <template v-if="tab === 'outgoing'">You asked {{ row.owner_name || 'the uploader' }}</template>
                                        <template v-else>{{ row.requester_name }}<span v-if="row.office"> · {{ row.office }}</span></template>
                                        · {{ formatTimestamp(row.created_at) }}
                                    </p>
                                </div>
                            </div>
                            <Badge :tone="row.status_tone">{{ row.status_label }}</Badge>
                        </div>

                        <p v-if="row.message" class="mt-3 whitespace-pre-line rounded-lg bg-slate-50 px-3 py-2 text-sm italic text-slate-600">{{ row.message }}</p>
                        <p v-if="row.decision_note" class="mt-2 text-xs text-slate-500">Note: {{ row.decision_note }}</p>
                        <p v-if="row.decided_by_name" class="mt-1 text-xs text-slate-400">Answered by {{ row.decided_by_name }}</p>

                        <!-- Only on the asker's own tab. The same row seen by
                             the uploader is their record of a decision they
                             made, and is not the asker's to take off their
                             screen. -->
                        <div v-if="tab === 'outgoing'" class="mt-3">
                            <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-bold text-slate-600 transition hover:bg-slate-50 disabled:opacity-60" :disabled="deciding === row.id" @click="askToDismiss(row)">
                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>{{ row.status === 'pending' ? 'Withdraw' : 'Remove' }}
                            </button>
                        </div>

                        <div v-if="tab === 'incoming'" class="mt-4 flex flex-wrap gap-2">
                            <button type="button" class="inline-flex items-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2 text-sm font-bold text-white transition hover:bg-[#009bd8] disabled:opacity-60" :disabled="deciding === row.id" @click="askToDecide(row, 'granted')">
                                <i class="fa-solid fa-check" aria-hidden="true"></i>Grant access
                            </button>
                            <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-rose-300 px-4 py-2 text-sm font-bold text-rose-600 transition hover:bg-rose-50 disabled:opacity-60" :disabled="deciding === row.id" @click="askToDecide(row, 'denied')">
                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>Decline
                            </button>
                        </div>
                    </li>
                </ul>

                <p v-else class="py-10 text-center text-sm text-slate-500">
                    <template v-if="tab === 'incoming'">Nobody is waiting on you.</template>
                    <template v-else-if="tab === 'granted'">You have not let anybody into a document yet.</template>
                    <template v-else>You have not asked for access to anything.</template>
                </p>
            </div>
        </ConfirmActionModal>

        <!--
            The confirmation, over the panel.

            z-[80] because the panel it is asked from is itself a dialog at
            the default z-50; without lifting it the question would open
            behind the list it is about. Same reason the full-screen
            preview's dialogs carry it.
        -->
        <ConfirmActionModal
            :open="pendingDecision !== null"
            layer-class="z-[80]"
            :title="pendingDecision && pendingDecision.decision === 'granted' ? 'Grant access to this document?' : 'Decline this request?'"
            :message="pendingDecision
                ? (pendingDecision.decision === 'granted'
                    ? pendingDecision.row.requester_name + ' will be added to ' + pendingDecision.row.document_code + ' and can open it from now on. You can take them off again from the document page later.'
                    : pendingDecision.row.requester_name + ' will be told that their request for ' + pendingDecision.row.document_code + ' was not granted. Nothing is shared, and they can ask again.')
                : ''"
            :icon="pendingDecision && pendingDecision.decision === 'granted' ? 'fa-solid fa-user-check' : 'fa-solid fa-user-xmark'"
            :tone="pendingDecision && pendingDecision.decision === 'granted' ? 'info' : 'danger'"
            :confirm-label="pendingDecision && pendingDecision.decision === 'granted' ? 'Grant access' : 'Decline request'"
            @close="pendingDecision = null"
            @confirm="confirmDecision" />

        <ConfirmActionModal
            :open="pendingDismissal !== null"
            layer-class="z-[80]"
            :title="pendingDismissal && pendingDismissal.status === 'pending' ? 'Withdraw this request?' : 'Remove this from your list?'"
            :message="pendingDismissal
                ? (pendingDismissal.status === 'pending'
                    ? 'Your request for ' + pendingDismissal.document_code + ' will stop waiting for an answer, so nobody is asked to decide it. You can ask again later, and the record of having asked is kept.'
                    : 'This answered request for ' + pendingDismissal.document_code + ' will be hidden from your list. Nothing is deleted - the uploader keeps the record of their decision, and any access you were granted is unaffected.')
                : ''"
            icon="fa-solid fa-xmark"
            tone="danger"
            :confirm-label="pendingDismissal && pendingDismissal.status === 'pending' ? 'Withdraw request' : 'Remove from list'"
            @close="pendingDismissal = null"
            @confirm="confirmDismissal" />
    </div>
`,
});
