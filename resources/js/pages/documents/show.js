import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, defineComponent, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import FilePreviewModal from '../../components/FilePreviewModal';
import VisibilityBadge from '../../components/VisibilityBadge';
import { formatTimestamp } from '../../utils/format-date';

/**
 * A document and its travel history — what a scanned QR label opens.
 *
 * The file actions that hand back bytes — Download, Receive, QR label — are
 * plain <a href> links or a real form post, not Inertia visits: they return
 * file responses, which an XHR visit cannot follow.
 *
 * **View file is not one of them any more.** It used to open the raw bytes in a
 * new tab, which is a download prompt for every format a browser cannot render
 * — which is most of what an office files. It now opens the same viewer the
 * file manager uses: the server says how this file should be shown
 * (`/documents/{id}/preview`), and a Word, Excel or PowerPoint document renders
 * through Microsoft's embedded Office viewer over a short-lived signed URL, as
 * it does in My Files. The decision is the server's; see
 * App\Services\Files\FilePreview.
 */
export default defineComponent({
    name: 'DocumentShow',
    components: { AdminShell, Card, ConfirmActionModal, FilePreviewModal, Head, Link, VisibilityBadge },
    props: {
        document: { type: Object, required: true },
        logs: { type: Array, default: () => [] },
        actions: { type: Array, default: () => [] },
        can: { type: Object, default: () => ({}) },
    },
    setup(props) {
        const page = usePage();
        const isAuthenticated = computed(() => Boolean(page.props.auth?.user));

        const receiving = ref(false);
        const logging = ref(false);

        // ------------------------------------------------------------ preview
        /*
         * The descriptor is fetched when the viewer is opened, never shipped
         * with the page: for an Office document it mints a URL that is live for
         * minutes, and a page that carried one would hand out a credential to
         * everybody who merely looked at the document.
         */
        const previewing = ref(false);
        const previewFile = ref(null);

        const openPreview = async () => {
            previewFile.value = null;
            previewing.value = true;

            try {
                const response = await fetch(`/documents/${props.document.id}/preview`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                previewFile.value = response.ok
                    ? await response.json()
                    : { mode: 'none', name: props.document.title, can_download: false };
            } catch {
                previewFile.value = { mode: 'none', name: props.document.title, can_download: false };
            }
        };

        const closePreview = () => {
            previewing.value = false;
            previewFile.value = null;
        };

        const receiveForm = useForm({ remarks: '' });
        // The action is an App\Enums\DocumentAction value, and the options
        // come from the server — the page no longer carries its own list of
        // action strings for the controller to compare against.
        const logForm = useForm({
            document_id: props.document.id,
            action: props.actions[0]?.value ?? 'acknowledged',
            remarks: '',
        });

        // The receive endpoint answers with the file itself, so it is submitted
        // as a real form post rather than through Inertia — which also means it
        // needs the CSRF token from the meta tag in app.blade.php.
        const receiveAction = computed(() => `/documents/${props.document.id}/receive`);
        const receiveFormEl = ref(null);
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const submitReceive = () => receiveFormEl.value?.submit();

        const submitLog = () => logForm.post('/document/log', {
            preserveScroll: true,
            onSuccess: () => {
                logForm.reset('remarks');
                logging.value = false;
            },
        });

        return {
            closePreview,
            csrfToken,
            openPreview,
            previewFile,
            previewing,
            formatTimestamp,
            isAuthenticated,
            logForm,
            logging,
            receiveAction,
            receiveForm,
            receiveFormEl,
            receiving,
            submitLog,
            submitReceive,
        };
    },
    template: `
    <Head :title="document.title" />
    <AdminShell active="documents" :title="document.title">
        <div class="space-y-5">
            <Card>
                <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-5">
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#07559e]">{{ document.type }}</p>
                        <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">{{ document.title }}</h2>
                        <p class="mt-2 font-mono text-sm text-slate-600">{{ document.code }}</p>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <VisibilityBadge :visibility="document.visibility" />
                    </div>
                </div>

                <dl class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Uploaded by</dt>
                        <dd class="mt-1 font-semibold text-slate-800">{{ document.user?.name ?? document.uploader_name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Uploaded</dt>
                        <dd class="mt-1 font-semibold text-slate-800">{{ formatTimestamp(document.created_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Last updated</dt>
                        <dd class="mt-1 font-semibold text-slate-800">{{ formatTimestamp(document.updated_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Hand-offs logged</dt>
                        <dd class="mt-1 font-semibold text-slate-800">{{ logs.length }}</dd>
                    </div>
                </dl>

                <div v-if="document.visibility === 'private' && document.recipients?.length" class="mt-6 border-t border-slate-200 pt-5">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Shared with</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <span v-for="recipient in document.recipients" :key="recipient.id" class="inline-flex items-center gap-2 rounded-full bg-sky-100 px-3 py-1 text-xs font-bold text-[#07559e]"><i class="fa-solid fa-user" aria-hidden="true"></i>{{ recipient.name }}</span>
                    </div>
                </div>

                <!-- File actions are plain links: these routes return the file itself. -->
                <div class="mt-7 flex flex-wrap gap-3 border-t border-slate-200 pt-5">
                    <button type="button" class="inline-flex items-center justify-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8]" @click="openPreview"><i class="fa-solid fa-eye" aria-hidden="true"></i>View file</button>
                    <a v-if="can.download" :href="'/documents/' + document.id + '/file/download'" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"><i class="fa-solid fa-download" aria-hidden="true"></i>Download</a>
                    <button v-if="isAuthenticated && can.receive" type="button" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="receiving = true"><i class="fa-solid fa-inbox" aria-hidden="true"></i>Receive &amp; download</button>
                    <button v-if="isAuthenticated" type="button" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="logging = true"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>Log a hand-off</button>
                    <a :href="'/documents/qr/download/' + document.qr_code_path" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"><i class="fa-solid fa-qrcode" aria-hidden="true"></i>QR label</a>
                    <template v-if="can.manage">
                        <Link :href="'/documents/' + document.id + '/edit'" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"><i class="fa-solid fa-pen" aria-hidden="true"></i>Edit</Link>
                    </template>
                </div>
            </Card>

            <Card>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Audit trail</p>
                        <h3 class="mt-1 text-xl font-bold uppercase tracking-wide text-slate-900">Tracking history</h3>
                        <p class="mt-1 text-slate-600">Every view, download, acknowledgement and hand-off, newest first.</p>
                    </div>
                    <Link :href="'/documents/' + document.id + '/logs'" class="text-sm font-bold text-[#07559e] hover:text-[#043d78]">Full history</Link>
                </div>
                <ol class="mt-6 space-y-4">
                    <li v-for="log in logs" :key="log.id" class="flex gap-4 border-b border-slate-100 pb-4 last:border-b-0 last:pb-0">
                        <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-sky-100 text-xs text-[#07559e]"><i class="fa-solid fa-circle-dot" aria-hidden="true"></i></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-bold text-slate-800">{{ log.action_label }}</span>
                            <span class="block text-xs text-slate-500">
                                {{ log.actor_name }}
                                <template v-if="log.division_name"> · {{ log.division_name }}</template>
                                <template v-if="log.section_name"> · {{ log.section_name }}</template>
                            </span>
                            <span class="block text-xs text-slate-400">{{ formatTimestamp(log.occurred_at ?? log.created_at) }}</span>
                            <span v-if="log.remarks" class="mt-2 block whitespace-pre-line rounded-lg bg-slate-50 px-3 py-2 text-xs italic text-slate-600">{{ log.remarks }}</span>
                        </span>
                    </li>
                    <li v-if="logs.length === 0" class="py-10 text-center text-sm text-slate-500">Nothing has been logged against this document yet.</li>
                </ol>
            </Card>
        </div>

        <!-- Receive posts a real form, because the response is the file. -->
        <ConfirmActionModal
            :open="receiving"
            title="Receive this document?"
            message="This records that you took delivery, then downloads the file. It can only be logged once."
            confirm-label="Receive &amp; download"
            icon="fa-solid fa-inbox"
            @close="receiving = false"
            @confirm="submitReceive">
            <form ref="receiveFormEl" :action="receiveAction" method="post" class="mt-5 text-left">
                <input type="hidden" name="_token" :value="csrfToken" />
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Remarks (optional)</span>
                    <textarea v-model="receiveForm.remarks" name="remarks" rows="3" class="field" placeholder="Anything worth recording about this hand-off"></textarea>
                </label>
            </form>
        </ConfirmActionModal>

        <ConfirmActionModal
            :open="logging"
            :processing="logForm.processing"
            title="Log a hand-off"
            message="Record that this document passed through you."
            confirm-label="Log activity"
            icon="fa-solid fa-pen-to-square"
            @close="logging = false"
            @confirm="submitLog">
            <div class="mt-5 space-y-4 text-left">
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Action</span>
                    <select v-model="logForm.action" class="field">
                        <option v-for="option in actions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Remarks (optional)</span>
                    <textarea v-model="logForm.remarks" rows="3" class="field" :class="{ 'border-rose-500': logForm.errors.remarks }"></textarea>
                    <p v-if="logForm.errors.remarks" class="error">{{ logForm.errors.remarks }}</p>
                </label>
            </div>
        </ConfirmActionModal>

        <FilePreviewModal
            :open="previewing"
            :file="previewFile"
            @close="closePreview" />
    </AdminShell>
`,
});
