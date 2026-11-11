import { computed, defineComponent, ref, watch } from 'vue';
import ConfirmActionModal from './ConfirmActionModal';
import { readableSize } from '../utils/file-rows';

/**
 * Deleting, behind a code that has to be typed and a count of what goes with it.
 *
 * Two things make this different from the confirmation dialog everything else
 * in the app uses.
 *
 * **The code.** It is issued by the server, held in the session, spent on use,
 * and the delete endpoint refuses a request whose code does not match — see
 * App\\Services\\Files\\DeletionConfirmation. A code the page invented and
 * checked itself would be a label on a button, bypassed by anything that posts
 * the request directly. Four digits is a speed bump, not a secret: what it is
 * for is the muscle memory that confirms a dialog without reading it.
 *
 * **The summary.** A folder delete is recursive, and "Delete folder?" does not
 * say that. The server counts the subtree — folders, files, bytes — so the
 * dialog can state the cost before it is paid, rather than after.
 */
export default defineComponent({
    name: 'ConfirmDeleteModal',
    components: { ConfirmActionModal },
    emits: ['close', 'confirm'],
    props: {
        open: { type: Boolean, default: false },
        /** 'delete' (to trash, reversible) or 'purge' (permanent). */
        purpose: { type: String, default: 'delete' },
        /** The rows being deleted: [{ kind, public_id, name }]. */
        items: { type: Array, default: () => [] },
        /** True while reading out of the trash, where the rows are soft-deleted. */
        trashed: { type: Boolean, default: false },
        processing: { type: Boolean, default: false },
        error: { type: String, default: '' },
    },
    setup(props, { emit }) {
        const issued = ref('');
        const typed = ref('');
        const summary = ref(null);
        const loading = ref(false);
        const failed = ref('');

        const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

        /**
         * Ask the server for a code and for what the delete would take.
         *
         * Both on open, and both refused rather than guessed if the request
         * fails: a dialog that showed a code the server does not know would
         * confirm nothing and fail at the last step.
         */
        const prepare = async () => {
            loading.value = true;
            failed.value = '';
            issued.value = '';
            typed.value = '';
            summary.value = null;

            const payload = new URLSearchParams();
            props.items.forEach((item, index) => {
                payload.append(`items[${index}][kind]`, item.kind);
                payload.append(`items[${index}][public_id]`, item.public_id);
            });

            if (props.trashed) payload.append('trashed', '1');

            try {
                const [codeResponse, previewResponse] = await Promise.all([
                    fetch('/files/confirm-code', {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-CSRF-TOKEN': csrf(),
                        },
                        credentials: 'same-origin',
                        body: new URLSearchParams({ purpose: props.purpose }),
                    }),
                    fetch(`/files/delete-preview?${payload.toString()}`, {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    }),
                ]);

                if (!codeResponse.ok) throw new Error('code');

                issued.value = (await codeResponse.json()).code;

                // The summary is a courtesy, so a failure to count does not
                // block the delete — the code is the part that must hold.
                summary.value = previewResponse.ok ? await previewResponse.json() : null;
            } catch {
                failed.value = 'A confirmation code could not be issued. Close this and try again.';
            } finally {
                loading.value = false;
            }
        };

        watch(() => props.open, (open) => {
            if (open) prepare();
        });

        const permanent = computed(() => props.purpose === 'purge');
        const matches = computed(() => issued.value !== '' && typed.value === issued.value);
        const folderCount = computed(() => summary.value?.folders ?? 0);
        const fileCount = computed(() => summary.value?.files ?? 0);

        const title = computed(() => {
            if (permanent.value) return props.items.length > 1 ? 'Delete these permanently?' : 'Delete permanently?';

            return props.items.length > 1 ? 'Move these to trash?' : 'Move to trash?';
        });

        const message = computed(() => {
            const what = props.items.length === 1
                ? props.items[0].name
                : `${props.items.length} items`;

            return permanent.value
                ? `${what} will be erased, with its stored file. This cannot be undone and nothing can bring it back.`
                : `${what} can be restored from Trash afterwards.`;
        });

        return {
            failed, fileCount, folderCount, issued, loading, matches, message,
            permanent, readableSize, summary, title, typed,
        };
    },
    template: `
    <ConfirmActionModal
        :open="open"
        :processing="processing"
        :title="title"
        :message="message"
        :confirm-label="permanent ? 'Delete permanently' : 'Move to trash'"
        :confirm-disabled="!matches"
        tone="danger"
        :icon="permanent ? 'fa-solid fa-fire' : 'fa-solid fa-trash'"
        wide
        @close="$emit('close')"
        @confirm="$emit('confirm', typed)">
        <div class="space-y-4 text-left">
            <!-- What goes with it. Stated before the code, because the code is
                 only worth typing once you know what it confirms. -->
            <div v-if="summary && (folderCount > 0 || fileCount > 1)" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3">
                <p class="text-sm font-bold uppercase tracking-wide text-rose-700">
                    <i class="fa-solid fa-triangle-exclamation mr-1.5" aria-hidden="true"></i>Everything inside goes too
                </p>
                <p class="mt-1 text-sm text-rose-700">
                    <template v-if="folderCount">{{ folderCount }} folder{{ folderCount === 1 ? '' : 's' }} and </template>{{ fileCount }} file{{ fileCount === 1 ? '' : 's' }}<template v-if="summary.bytes"> · {{ readableSize(summary.bytes) }}</template>
                </p>
                <ul v-if="summary.names?.length" class="mt-2 space-y-0.5 text-xs text-rose-700">
                    <li v-for="name in summary.names" :key="name" class="truncate">· {{ name }}</li>
                    <li v-if="summary.more">· and {{ summary.more }} more</li>
                </ul>
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-4">
                <p class="text-sm font-semibold text-slate-700">Type this code to confirm</p>
                <p v-if="loading" class="mt-2 text-sm text-slate-500">Issuing a code…</p>
                <p v-else-if="failed" class="mt-2 text-sm font-semibold text-rose-600">{{ failed }}</p>
                <p v-else class="mt-2 font-mono text-3xl font-bold tracking-[0.4em] text-slate-900" aria-label="Confirmation code">{{ issued }}</p>
                <label class="mt-3 block">
                    <span class="sr-only">Confirmation code</span>
                    <input
                        v-model="typed"
                        inputmode="numeric"
                        autocomplete="off"
                        maxlength="4"
                        placeholder="0000"
                        class="field max-w-[10rem] text-center font-mono text-xl tracking-[0.3em]"
                        :class="{ 'border-rose-500': typed.length === 4 && !matches }" />
                </label>
                <p v-if="typed.length === 4 && !matches" class="error">That does not match the code above.</p>
            </div>

            <p v-if="error" class="error">{{ error }}</p>
        </div>
    </ConfirmActionModal>
`,
});
