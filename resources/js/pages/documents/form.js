import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, defineComponent, watch } from 'vue';
import AdminShell from '../../components/AdminShell';
import Button from '../../components/Button';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import RecipientPicker from '../../components/RecipientPicker';
import SearchableSelect from '../../components/SearchableSelect';
import useConfirmSubmit from '../../composables/useConfirmSubmit';

/**
 * Upload and edit in one component.
 *
 * Visibility and recipients are only part of the upload: changing who a
 * document was shared with after the fact would rewrite the record the
 * notifications already went out about, so edit covers the title, the type and
 * replacing the file.
 */
export default defineComponent({
    name: 'DocumentForm',
    components: { AdminShell, Button, Card, ConfirmActionModal, Head, Link, RecipientPicker, SearchableSelect },
    props: {
        document: { type: Object, default: null },
        types: { type: Array, default: () => [] },
        visibilities: { type: Array, default: () => [] },
        users: { type: Array, default: () => [] },
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
            visibility: 'public',
            recipients: [],
        });

        const typeOptions = computed(() => props.types.map((type) => ({
            value: type.id,
            label: `${type.name} (${type.code})`,
        })));

        // Switching back to public drops any recipients already picked, so a
        // public upload can never carry a recipient list the server would then
        // have to ignore.
        watch(() => form.visibility, (visibility) => {
            if (visibility === 'public') {
                form.recipients = [];
            }
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

        return { cancel, confirm, form, isEditing, proceed, request, typeOptions };
    },
    template: `
    <Head :title="isEditing ? 'Edit document' : 'Upload document'" />
    <AdminShell :active="isEditing ? 'documents' : 'documents-create'" :title="isEditing ? 'Edit document' : 'Upload document'">
        <Card class="w-full">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-5">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#07559e]">Documents</p>
                    <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">{{ isEditing ? 'Update document' : 'New document' }}</h2>
                    <p class="mt-1 text-slate-600">Fields marked with <span class="font-bold text-rose-600">*</span> are required. Accepted files: PDF, DOC, DOCX, up to 10 MB.</p>
                </div>
                <Link :href="isEditing ? '/documents/view/' + document.code : '/documents'" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 hover:border-[#07559e] hover:text-[#07559e]"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back</Link>
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
                    <input data-tour="document-file" type="file" accept=".pdf,.doc,.docx" class="field file:mr-3 file:rounded-lg file:border-0 file:bg-sky-100 file:px-3 file:py-1.5 file:text-sm file:font-bold file:text-[#07559e]" :class="{ 'border-rose-500': form.errors.file }" @input="form.file = $event.target.files[0]" />
                    <p v-if="form.errors.file" class="error">{{ form.errors.file }}</p>
                    <p v-if="isEditing" class="mt-1 text-xs text-slate-500">Leave empty to keep the current file.</p>
                </label>

                <template v-if="!isEditing">
                    <fieldset data-tour="document-visibility" class="md:col-span-2">
                        <legend class="text-sm font-semibold text-slate-700">Who can open this document?</legend>
                        <!-- The two cards come from App\Enums\DocumentVisibility, so the
                             wording lives with the rule it describes. -->
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <label v-for="option in visibilities" :key="option.value" class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition" :class="form.visibility === option.value ? 'border-[#00aeef] bg-sky-50' : 'border-slate-300 hover:border-sky-300'">
                                <input v-model="form.visibility" type="radio" :value="option.value" class="mt-1 h-4 w-4 accent-[#00aeef]" />
                                <span>
                                    <span class="block text-sm font-bold uppercase tracking-wide text-slate-800">{{ option.label }}</span>
                                    <span class="block text-xs text-slate-500">{{ option.description }}</span>
                                </span>
                            </label>
                        </div>
                        <p v-if="form.errors.visibility" class="error">{{ form.errors.visibility }}</p>
                    </fieldset>

                    <div v-if="form.visibility === 'private'" class="md:col-span-2">
                        <span class="required-label text-sm font-semibold text-slate-700">Recipients</span>
                        <p class="mt-1 text-xs text-slate-500">Pick everyone who needs to open this document.</p>
                        <RecipientPicker
                            v-model="form.recipients"
                            :people="users"
                            :invalid="Boolean(form.errors.recipients)"
                            empty-text="Nobody added yet. Use Add to choose who can open this." />
                        <!--
                            Who has been tagged, stated in one line rather than
                            left to be counted off the chips. This is the last
                            moment it can be corrected: visibility and
                            recipients are set at upload only, because changing
                            them afterwards would rewrite the record the
                            notifications already went out about.
                        -->
                        <p v-if="form.recipients.length" class="mt-2 rounded-xl bg-emerald-50 px-4 py-2.5 text-sm font-semibold text-emerald-700">
                            <i class="fa-solid fa-user-check mr-1.5" aria-hidden="true"></i>{{ form.recipients.length }} {{ form.recipients.length === 1 ? 'person' : 'people' }} tagged — they will be notified and can open this document.
                        </p>
                        <p v-else class="mt-2 rounded-xl bg-amber-50 px-4 py-2.5 text-sm font-semibold text-amber-700">
                            <i class="fa-solid fa-triangle-exclamation mr-1.5" aria-hidden="true"></i>Nobody is tagged yet, so nobody but you would be able to open this.
                        </p>
                        <p v-if="form.errors.recipients" class="error">{{ form.errors.recipients }}</p>
                    </div>
                </template>

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
            :message="isEditing ? 'Are you sure you want to proceed?' : 'A tracking code and QR label will be generated once it is uploaded.'"
            @close="cancel"
            @confirm="proceed" />
    </AdminShell>
`,
});
