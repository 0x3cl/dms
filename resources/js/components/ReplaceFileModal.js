import { computed, defineComponent, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ConfirmActionModal from './ConfirmActionModal';
import UploadLimitsHint from './UploadLimitsHint';
import { csrfHeaders } from '../csrf';
import { pushToast } from './ToastStack';

/**
 * New bytes behind a file that already exists.
 *
 * A replacement rather than a second upload, and the dialog says which: the
 * item keeps its name, its link, its shares and — for a tracked document — its
 * tracking code and printed label, while what it *holds* becomes the next
 * version. Somebody who wanted a separate file wanted the upload button.
 *
 * `accept` and the size limits come from `UploadPolicy::limits()`, never from a
 * literal: the policy is a row an administrator edits, and a hard-coded accept
 * list is how the document form came to hide a format the server had already
 * been told to permit.
 *
 * The note is optional on purpose. A required reason is one more thing to get
 * past on a correction somebody is in a hurry to make, and a box typed through
 * to be rid of it is worth less in the history than an honest blank.
 */
export default defineComponent({
    name: 'ReplaceFileModal',
    components: { ConfirmActionModal, UploadLimitsHint },
    emits: ['close', 'replaced'],
    props: {
        open: { type: Boolean, default: false },
        /** `{ public_id, name, version }`, or null. */
        item: { type: Object, default: null },
        /** `UploadPolicy::limits()`, verbatim. */
        limits: { type: Object, default: null },
    },
    setup(props, { emit }) {
        const form = useForm({ public_id: '', note: '', file: null });
        const input = ref(null);

        watch(() => props.open, (open) => {
            if (!open) return;

            form.reset();
            form.clearErrors();
            form.public_id = props.item?.public_id ?? '';

            if (input.value) input.value.value = '';
        });

        const nextVersion = computed(() => (props.item?.version ?? 1) + 1);

        /*
         * Draw the code into the new version, for the one format the server
         * cannot draw into.
         *
         * A replacement is a new version of the **same** document: the
         * tracking code is the one already printed on paper, so the new
         * bytes carry the same code — and none of the old version's mark,
         * because that was drawn into the bytes this just superseded. The
         * server resets the row and re-stamps an image itself; a PDF has no
         * PDF library here, so it is drawn in the browser exactly as it is
         * on the way in.
         *
         * Silent only where there is nothing to say — a file with no
         * tracking jacket, or a format that carries no mark. A real failure
         * is reported, because a reader cannot tell a version that carries
         * its code from one that does not by looking at it.
         */
        const stampReplacement = async (file) => {
            const code = props.item?.tracked?.code;
            const labelUrl = props.item?.tracked?.label_url;

            if (!file || !code || !labelUrl) return;

            try {
                const { stampable, stampPdf } = await import('../stamp');

                if (!stampable(file)) return;

                const label = await fetch(labelUrl, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                if (!label.ok) return;

                const { stamp_png: png, cover } = await label.json();

                if (!png) return;

                const stamped = await stampPdf(file, { labelPng: png, code, cover });

                if (!stamped) {
                    pushToast({
                        title: 'This version could not be stamped',
                        message: `${code} is saved as the new version. A signed, encrypted or very large PDF is left untouched.`,
                        tone: 'error',
                    });

                    return;
                }

                const body = new FormData();
                body.append('code', code);
                body.append('stamped', stamped, file.name);

                const saved = await fetch('/files/stamp', {
                    method: 'POST',
                    body,
                    headers: csrfHeaders(),
                    credentials: 'same-origin',
                });

                pushToast(saved.ok
                    ? { title: 'Tracking code stamped', message: `${code} is on the new version.`, tone: 'success' }
                    : { title: 'The stamped version could not be saved', message: `${code} is saved, but its stamp was refused (${saved.status}).`, tone: 'error' });
            } catch (error) {
                pushToast({
                    title: 'This version could not be stamped',
                    message: `${code} is saved. ${error?.message ?? 'The stamp could not be drawn.'}`,
                    tone: 'error',
                });
            }
        };

        const submit = () => {
            // Held before the post, because the bytes are what the stamp
            // needs and a successful post clears the form.
            const replacement = form.file;

            form.transform((data) => ({ ...data, public_id: props.item?.public_id ?? '' }))
                .post('/files/replace', {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: () => {
                        emit('replaced');
                        emit('close');
                        stampReplacement(replacement);
                    },
                });
        };

        return { form, input, nextVersion, submit };
    },
    template: `
    <ConfirmActionModal
        :open="open"
        title="Replace this file"
        :message="item ? 'The new file becomes version ' + nextVersion + ' of ' + item.name + '. Its name, its share link and anybody it is shared with all stay as they are, and every earlier version is kept.' : ''"
        confirm-label="Save as new version"
        icon="fa-solid fa-arrow-up-from-bracket"
        :processing="form.processing"
        :progress="form.progress ? form.progress.percentage : null"
        @close="$emit('close')"
        @confirm="submit">
        <div class="space-y-4 text-left">
            <label class="block">
                <span class="required-label text-sm font-semibold text-slate-700">New file</span>
                <input
                    ref="input"
                    type="file"
                    :accept="limits ? limits.accept : undefined"
                    class="field file:mr-4 file:rounded-lg file:border-0 file:bg-sky-100 file:px-4 file:py-2 file:text-sm file:font-bold file:text-[#07559e]"
                    @input="form.file = $event.target.files[0]" />
                <p v-if="form.errors.file" class="error">{{ form.errors.file }}</p>
            </label>

            <UploadLimitsHint :limits="limits" />

            <label class="block">
                <span class="text-sm font-semibold text-slate-700">What changed? (optional)</span>
                <!-- A textarea, and a tall one. The note is what the
                     version history and the document's audit trail show
                     beside this version forever, and a single line invited
                     five words where the useful answer is often a couple of
                     sentences about what moved. Resizable vertically only:
                     a textarea that can be dragged wider than the dialog is
                     a layout that breaks on a drag. -->
                <textarea
                    v-model="form.note"
                    maxlength="255"
                    class="field h-[250px] resize-y"
                    placeholder="e.g. Signed copy, corrected annexes"></textarea>
                <p v-if="form.errors.note" class="error">{{ form.errors.note }}</p>
                <p class="mt-1 text-xs text-slate-500">Shown in the version history and in the document's audit trail.</p>
            </label>
        </div>
    </ConfirmActionModal>
`,
});
