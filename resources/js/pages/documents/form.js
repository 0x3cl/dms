import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, defineComponent, watch } from 'vue';
import AdminShell from '../../components/AdminShell';
import Button from '../../components/Button';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import RecipientPicker from '../../components/RecipientPicker';
import SearchableSelect from '../../components/SearchableSelect';
import ToggleSwitch from '../../components/ToggleSwitch';
import UploadLimitsHint from '../../components/UploadLimitsHint';
import useConfirmSubmit from '../../composables/useConfirmSubmit';
import { registerHref } from '../../registerReturn';

/**
 * Upload and edit in one component.
 *
 * **The level is part of the upload; the recipient list is not.** Who may open
 * a restricted document is editable here, because that is the part the office
 * corrects: a name left off, somebody who has moved on, or a document filed
 * before anybody knew who it was for — which this form invites explicitly by
 * making the list optional at every level. Tagging was once a one-shot
 * decision taken at upload, which meant the only way past a missing name was
 * for that person to request access to a document they had no reason to know
 * existed.
 *
 * Reclassifying stays out: that is a question about readers who already hold
 * the document, and the share panel answers the part of it that matters (the
 * link). So the three cards show on upload only, and the picker shows wherever
 * the level in force tags people — private and confidential.
 */
export default defineComponent({
    name: 'DocumentForm',
    components: { AdminShell, Button, Card, ConfirmActionModal, Head, Link, RecipientPicker, SearchableSelect, ToggleSwitch, UploadLimitsHint },
    props: {
        document: { type: Object, default: null },
        /** Who the document is shared with now — the live list, ids only. */
        recipients: { type: Array, default: () => [] },
        types: { type: Array, default: () => [] },
        visibilities: { type: Array, default: () => [] },
        users: { type: Array, default: () => [] },
        /*
         * What the server will actually accept, from `UploadPolicy::limits()`.
         * The picker's `accept` comes from here rather than from a literal: a
         * hard-coded `.pdf,.doc,.docx` outlived the policy it was copied from
         * and hid every permitted spreadsheet from the browser's own dialog.
         */
        limits: { type: Object, default: null },
    },
    setup(props) {
        const isEditing = computed(() => Boolean(props.document));

        const form = useForm({
            title: props.document?.title ?? '',
            // The type is a row in `document_types` now, not a name string, so
            // the form posts its id. Renaming a type no longer changes what
            // this field has to send.
            document_type_id: props.document?.document_type_id ?? '',
            file: null,
            // Only ever sent with a replacement — see the field's own comment.
            note: '',
            /*
             * The document's own level when editing, not the default. The
             * picker is rendered off `selected`, which is looked up by this
             * value — seeded as 'public' it would have hidden the picker on
             * every private document in the register.
             */
            visibility: props.document?.visibility ?? 'public',
            // Pre-ticked from the live list, so saving an unrelated edit
            // leaves the tagging exactly as it was. A removed viewer is
            // deliberately absent: see DocumentController::edit().
            recipients: [...props.recipients],
            /*
             * Whether readers may discuss this document.
             *
             * Defaults to on: a feature that has to be switched on per
             * document is one nobody finds. Turning it off closes the
             * thread and never deletes what was already written — erasing
             * other people's words as a side effect of a setting is not
             * something a toggle should do, so the panel stays readable.
             */
            comments_enabled: props.document?.comments_enabled ?? true,
        });

        /*
         * Grouped by the server — `[{ label, options: [...] }]` — and passed
         * through untouched. The arrangement is an office decision in
         * `document_type_groups`, not a shape this page invents, so the file
         * manager's tracking dialog offers exactly the same list.
         */
        const typeOptions = computed(() => props.types.map((group) => ({
            label: group.label,
            options: group.options.map((type) => ({
                value: type.value,
                label: `${type.label} (${type.code})`,
            })),
        })));

        /*
         * Which level does what is the server's answer, carried on each option
         * as `taggable` and `linkable`. The template used to test
         * `visibility === 'private'` directly, which is why adding
         * Confidential would have left the recipient picker hidden on a level
         * that requires it — correct on the server, invisible in the browser.
         */
        const selected = computed(
            () => props.visibilities.find((option) => option.value === form.visibility) ?? null,
        );

        /*
         * Switching to a level that tags nobody drops the recipients already
         * picked, so an upload can never carry a list the server would ignore.
         *
         * Only while uploading: the level is not editable afterwards, so on an
         * edit this watcher has nothing to react to — and were it to fire, it
         * would be clearing a list somebody is in the middle of correcting.
         */
        watch(() => form.visibility, () => {
            if (!isEditing.value && !selected.value?.taggable) {
                form.recipients = [];
            }
        });

        /** What the tagging section is about to do, stated before it is saved. */
        const tagChanges = computed(() => {
            const before = props.recipients.map(Number);
            const after = form.recipients.map(Number);

            return {
                added: after.filter((id) => !before.includes(id)).length,
                removed: before.filter((id) => !after.includes(id)).length,
            };
        });

        const submitForm = (options) => {
            if (isEditing.value) {
                // A file input needs multipart, and Laravel needs the method
                // spoofed for it.
                form.transform((data) => ({ ...data, _method: 'put' }))
                    .post(`/documents/${props.document.id}`, { ...options, forceFormData: true });
            } else {
                form.post('/documents', { ...options, forceFormData: true });
            }
        };

        const { confirm, request, cancel, proceed } = useConfirmSubmit(submitForm);

        /*
         * `selected` is returned, and that is not a tidying-up.
         *
         * It is what the whole tagging section is rendered on
         * (`v-if="selected && selected.taggable"`), and leaving it out of
         * setup's return meant the template read an undefined property,
         * evaluated it as falsey and drew nothing. So choosing Private or
         * Confidential offered no recipient picker at all: a private
         * document could only ever be uploaded with an empty list, and a
         * confidential one could only be addressed by hand-posting the form.
         *
         * It failed silently in both directions, which is why it survived:
         * Vue logs a warning and renders nothing, and every test that tags
         * somebody posts to the endpoint directly, where the field works.
         */
        /*
         * The last gate says what is about to happen to people who are not on
         * screen. "Are you sure you want to proceed?" is a question with no
         * information in it, and a tagging change is exactly the kind of edit
         * somebody makes by mistake while fixing a title.
         */
        const confirmMessage = computed(() => {
            if (!isEditing.value) {
                return 'A tracking code and QR Tracker will be generated once it is uploaded.';
            }

            const parts = [];

            if (tagChanges.value.added) {
                parts.push(`${tagChanges.value.added} ${tagChanges.value.added === 1 ? 'person' : 'people'} will be given this document and notified`);
            }

            if (tagChanges.value.removed) {
                parts.push(`${tagChanges.value.removed} ${tagChanges.value.removed === 1 ? 'person' : 'people'} will lose access to it`);
            }

            return parts.length ? `${parts.join(', and ')}.` : 'Are you sure you want to proceed?';
        });

        return { backHref: registerHref(), cancel, confirm, confirmMessage, form, isEditing, proceed, request, selected, tagChanges, typeOptions };
    },
    template: `
    <Head :title="isEditing ? 'Edit document' : 'Upload document'" />
    <AdminShell :active="isEditing ? 'documents' : 'documents-create'" :title="isEditing ? 'Edit document' : 'Upload document'">
        <Card class="w-full">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-5">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#07559e]">Documents</p>
                    <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">{{ isEditing ? 'Update document' : 'New document' }}</h2>
                    <p class="mt-1 text-slate-600">Fields marked with <span class="font-bold text-rose-600">*</span> are required.</p>
                </div>
                <Link :href="isEditing ? '/documents/view/' + document.code : backHref" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 hover:border-[#07559e] hover:text-[#07559e]"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back</Link>
            </div>

            <form class="mt-7 grid gap-5 md:grid-cols-2" @submit.prevent="request">
                <label class="md:col-span-2">
                    <span class="required-label text-sm font-semibold text-slate-700">Document title</span>
                    <input data-tour="document-title" v-model="form.title" class="field" :class="{ 'border-rose-500': form.errors.title }" />
                    <p v-if="form.errors.title" class="error">{{ form.errors.title }}</p>
                </label>

                <div>
                    <span class="required-label text-sm font-semibold text-slate-700">Document type</span>
                    <div class="mt-2">
                        <SearchableSelect v-model="form.document_type_id" :options="typeOptions" placeholder="Select a type" :invalid="Boolean(form.errors.document_type_id)" />
                    </div>
                    <p v-if="form.errors.document_type_id" class="error">{{ form.errors.document_type_id }}</p>
                    <p class="mt-1 text-xs text-slate-500">The type decides the prefix of the tracking code.</p>
                </div>

                <label>
                    <span class="text-sm font-semibold text-slate-700" :class="{ 'required-label': !isEditing }">{{ isEditing ? 'Replace file (optional)' : 'File' }}</span>
                    <input data-tour="document-file" type="file" :accept="limits ? limits.accept : undefined" class="field file:mr-3 file:rounded-lg file:border-0 file:bg-sky-100 file:px-3 file:py-1.5 file:text-sm file:font-bold file:text-[#07559e]" :class="{ 'border-rose-500': form.errors.file }" @input="form.file = $event.target.files[0]" />
                    <p v-if="form.errors.file" class="error">{{ form.errors.file }}</p>
                    <UploadLimitsHint :limits="limits" />
                    <p v-if="isEditing" class="mt-1 text-xs text-slate-500">
                        Leave empty to keep the current file. A new file is saved as the
                        <strong>next version</strong>: the tracking code, the QR Tracker and the share link stay
                        as they are, and the file it replaces is kept in the document's version history.
                    </p>
                </label>

                <!-- Only on edit, and only alongside a replacement: a note
                     about a file nobody is replacing has nothing to attach to.
                     Optional, because a required reason is one more thing to
                     get past on a correction somebody is in a hurry to make. -->
                <!-- The one setting on this form that is about what happens
                     to the document *after* it is filed, so it sits on its
                     own row rather than beside the type. -->
                <div class="md:col-span-2">
                    <div class="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-700">Allow comments</p>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Anyone who can read this document can leave a note on it. Comments are kept against the file version they were written about.
                            </p>
                        </div>
                        <ToggleSwitch v-model="form.comments_enabled" label="Allow comments on this document" />
                    </div>
                    <p v-if="isEditing" class="mt-1 text-xs text-slate-500">Turning this off closes the thread. Nothing already written is deleted.</p>
                </div>

                <label v-if="isEditing && form.file" class="md:col-span-2">
                    <span class="text-sm font-semibold text-slate-700">What changed? (optional)</span>
                    <!-- Full width and tall, because this is prose rather than
                         a value: it sits across both columns like the other
                         full-width fields on this form, and the height is set
                         rather than left to the rows attribute so it does not change with
                         the font. border-rose-500 is the marker app.js scrolls
                         to on a validation failure, so this field behaves like
                         the rest of them when the server refuses it. -->
                    <textarea v-model="form.note" maxlength="255" class="field h-[350px]" :class="{ 'border-rose-500': form.errors.note }" placeholder="e.g. Signed copy, corrected annexes"></textarea>
                    <p v-if="form.errors.note" class="error">{{ form.errors.note }}</p>
                    <p class="mt-1 text-xs text-slate-500">Recorded in the version history and on the document's audit trail.</p>
                </label>

                <!-- The level is set at upload and not here: reclassifying
                     is a question about the readers who already hold the
                     document. Who is tagged *is* editable, below. -->
                <fieldset v-if="!isEditing" data-tour="document-visibility" class="md:col-span-2">
                    <legend class="text-sm font-semibold text-slate-700">Who can open this document?</legend>
                    <!-- The cards come from App\Enums\DocumentVisibility, so the
                         wording lives with the rule it describes, and the row is
                         a three-column grid rather than a wrapping two-column one: the
                         three levels are one choice and are read by comparing them,
                         which a card orphaned onto a second line defeats. -->
                    <div class="mt-3 grid gap-3 md:grid-cols-3">
                        <label v-for="option in visibilities" :key="option.value" class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition" :class="form.visibility === option.value ? 'border-[#00aeef] bg-sky-50' : 'border-slate-300 hover:border-sky-300'">
                            <input v-model="form.visibility" type="radio" :value="option.value" class="mt-1 h-4 w-4 shrink-0 accent-[#00aeef]" />
                            <span class="min-w-0">
                                <span class="flex items-center gap-2">
                                    <span class="text-sm font-bold uppercase tracking-wide text-slate-800">{{ option.label }}</span>
                                    <i v-if="!option.linkable" class="fa-solid fa-link-slash text-xs text-rose-500" aria-hidden="true" title="Cannot be shared by link"></i>
                                </span>
                                <span class="mt-0.5 block text-xs text-slate-500">{{ option.description }}</span>
                            </span>
                        </label>
                    </div>
                    <p v-if="form.errors.visibility" class="error">{{ form.errors.visibility }}</p>
                </fieldset>

                <!--
                    Tagging, at upload and afterwards.

                    Rendered wherever the level in force tags people — Private
                    and Confidential — which on an edit is the document's own
                    level rather than a card somebody just clicked. It is the
                    same section answering the same question later, so the
                    control is identical and only the wording says which of the
                    two moments this is.
                -->
                <div v-if="selected && selected.taggable" class="md:col-span-2">
                    <!--
                        Optional at every level, confidential included,
                        and the label says so rather than carrying an
                        asterisk: the marker is a promise about what the
                        form will refuse, so one over a field nothing
                        refuses is a lie the reader acts on.
                    -->
                    <span class="text-sm font-semibold text-slate-700">
                        {{ isEditing ? 'Who may open this document' : 'Tag the people who may open it' }}
                        <span class="font-normal text-slate-500">(optional)</span>
                    </span>
                    <p class="mt-1 text-xs text-slate-500">
                        <span v-if="selected.linkable">Everyone tagged can open this document, and they will be notified. A link can be sent as well — for a public document it opens for anyone, and for a private one only for the people tagged here.</span>
                        <span v-else>Only the people tagged here can ever open this document — a confidential document cannot be shared by link. You can leave it empty and tag somebody once you know who it is for.</span>
                    </p>
                    <RecipientPicker
                        v-model="form.recipients"
                        :people="users"
                        :invalid="Boolean(form.errors.recipients)"
                        :empty-text="isEditing ? 'Nobody is tagged. Use Add to choose who can open this.' : 'Nobody added yet. Use Add to choose who can open this.'" />

                    <!--
                        What saving will do, stated before it is saved.

                        On an edit the list is a *change*, and the two halves of
                        it are not the same kind of act: somebody added gets a
                        notification, somebody taken off loses access and is
                        recorded on the trail as removed. A count of the chips
                        would say neither, and both are things the uploader is
                        about to do to people who are not on screen.
                    -->
                    <p v-if="isEditing && (tagChanges.added || tagChanges.removed)" class="mt-2 rounded-xl bg-amber-50 px-4 py-2.5 text-sm font-semibold text-amber-800">
                        <i class="fa-solid fa-triangle-exclamation mr-1.5" aria-hidden="true"></i>
                        <span v-if="tagChanges.added">{{ tagChanges.added }} {{ tagChanges.added === 1 ? 'person' : 'people' }} will be given this document and notified.</span>
                        <span v-if="tagChanges.added && tagChanges.removed"> </span>
                        <span v-if="tagChanges.removed">{{ tagChanges.removed }} {{ tagChanges.removed === 1 ? 'person' : 'people' }} will lose access, which is recorded on the audit trail.</span>
                    </p>
                    <!--
                        Who has been tagged, stated in one line rather than
                        left to be counted off the chips.
                    -->
                    <p v-else-if="form.recipients.length" class="mt-2 rounded-xl bg-emerald-50 px-4 py-2.5 text-sm font-semibold text-emerald-700">
                        <i class="fa-solid fa-user-check mr-1.5" aria-hidden="true"></i>{{ form.recipients.length }} {{ form.recipients.length === 1 ? 'person' : 'people' }} tagged — <span v-if="isEditing">they can open this document.</span><span v-else>they will be notified and can open this document.</span>
                    </p>
                    <!--
                        A statement, not a warning.

                        This used to be an amber caution beside a field
                        the server refused to accept empty, which made
                        people tag whoever came to mind to get past the
                        form — a tag somebody then has to notice and undo,
                        which is worse than an empty list. Filing a
                        confidential document before you know who it goes
                        to is ordinary, so the form says what the
                        consequence is and lets it through.
                    -->
                    <p v-else-if="!selected.linkable" class="mt-2 rounded-xl bg-slate-50 px-4 py-2.5 text-sm text-slate-600">
                        <i class="fa-solid fa-circle-info mr-1.5 text-[#07559e]" aria-hidden="true"></i>Nobody tagged — only you can open this, and a confidential document has no link. Tag somebody here whenever you know who it is for.
                    </p>
                    <p v-else class="mt-2 rounded-xl bg-slate-50 px-4 py-2.5 text-sm text-slate-600">
                        <i class="fa-solid fa-circle-info mr-1.5 text-[#07559e]" aria-hidden="true"></i>Nobody tagged — only you can open this. Tag somebody here, or send a share link.
                    </p>
                    <p v-if="!selected.linkable" class="mt-2 rounded-xl bg-rose-50 px-4 py-2.5 text-xs font-semibold text-rose-700">
                        <i class="fa-solid fa-link-slash mr-1.5" aria-hidden="true"></i>No share link can be created for a confidential document, and any link already issued stops working the moment it is classified this way.
                    </p>
                    <p v-if="form.errors.recipients" class="error">{{ form.errors.recipients }}</p>
                </div>

                <div class="flex justify-end gap-3 border-t border-slate-200 pt-5 md:col-span-2">
                    <Button variant="secondary" href="/documents">Cancel</Button>
                    <Button data-tour="document-submit" type="submit" variant="primary" :disabled="form.processing">
                        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                        <span v-if="form.processing && form.progress">Uploading… {{ form.progress.percentage }}%</span>
                        <span v-else-if="form.processing">Saving…</span>
                        <span v-else>{{ isEditing ? 'Save changes' : 'Upload document' }}</span>
                    </Button>
                </div>
            </form>
        </Card>

        <ConfirmActionModal
            :open="confirm.open"
            :processing="confirm.processing"
            :progress="form.progress ? form.progress.percentage : null"
            :title="isEditing ? 'Save these changes?' : 'Upload this document?'"
            :message="confirmMessage"
            @close="cancel"
            @confirm="proceed" />
    </AdminShell>
`,
});
