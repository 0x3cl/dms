import { computed, defineComponent, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ConfirmActionModal from './ConfirmActionModal';
import UploadLimitsHint from './UploadLimitsHint';

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

        const submit = () => {
            form.transform((data) => ({ ...data, public_id: props.item?.public_id ?? '' }))
                .post('/files/replace', {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: () => {
                        emit('replaced');
                        emit('close');
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
