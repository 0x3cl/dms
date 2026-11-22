import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, defineComponent } from 'vue';
import AdminShell from '../../components/AdminShell';
import Button from '../../components/Button';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import ToggleSwitch from '../../components/ToggleSwitch';
import useConfirmSubmit from '../../composables/useConfirmSubmit';

/**
 * One component serves create and edit, branching on the nullable `type` prop.
 *
 * The one thing worth knowing: **the abbreviation is locked once the type has
 * issued a tracking code.** Every code it has handed out carries that
 * abbreviation, and those are printed on labels stuck to physical folders, so
 * changing it would not correct history — it would make the labels disagree
 * with the system. The input is disabled *and* the server ignores the field,
 * because a disabled input is a suggestion.
 */
export default defineComponent({
    name: 'DocumentTypeForm',
    components: { AdminShell, Button, Card, ConfirmActionModal, Head, Link, ToggleSwitch },
    props: {
        type: { type: Object, default: null },
        /** Existing headings. The picker also accepts a new one by name. */
        groups: { type: Array, default: () => [] },
        /** The group this form was opened from, if it was opened from one. */
        presetGroup: { type: Number, default: null },
    },
    setup(props) {
        const isEditing = computed(() => Boolean(props.type));
        const codeLocked = computed(() => Boolean(props.type?.has_issued));

        const form = useForm({
            code: props.type?.code ?? '',
            name: props.type?.name ?? '',
            description: props.type?.description ?? '',
            is_active: props.type?.is_active ?? true,
            position: props.type?.position ?? 0,
            /*
             * The heading this type sits under in the picker. Optional: an
             * ungrouped type shows under "Other" rather than disappearing,
             * which is also what a deleted group leaves behind.
             */
            group_id: props.type?.group_id ?? props.presetGroup ?? '',
        });

        const submitForm = (options) => {
            if (isEditing.value) {
                form.put(`/document-types/${props.type.id}`, options);
            } else {
                form.post('/document-types', options);
            }
        };

        const { confirm, request, cancel, proceed } = useConfirmSubmit(submitForm);

        // Shown live, so the consequence of the abbreviation is visible while
        // it is still editable.
        const samplePrefix = computed(() => {
            const code = (form.code || 'CODE').toUpperCase();
            const today = new Date().toISOString().slice(0, 10).replace(/-/g, '');

            return `${code}-${today}-0001`;
        });

        return { cancel, codeLocked, confirm, form, isEditing, proceed, request, samplePrefix };
    },
    template: `
    <Head :title="isEditing ? 'Edit document type' : 'Add document type'" />
    <AdminShell active="document-types" :title="isEditing ? 'Edit document type' : 'Add document type'">
        <Card class="w-full">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-5">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#07559e]">Settings</p>
                    <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">{{ isEditing ? 'Update type' : 'Create type' }}</h2>
                    <p class="mt-1 text-slate-600">Fields marked with <span class="font-bold text-rose-600">*</span> are required.</p>
                </div>
                <Link href="/document-types" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to types</Link>
            </div>

            <form class="mt-7 space-y-6" @submit.prevent="request">
                <div class="grid gap-5 md:grid-cols-2">
                    <label>
                        <span class="required-label text-sm font-semibold text-slate-700">Abbreviation</span>
                        <input
                            v-model="form.code"
                            :required="!codeLocked"
                            :disabled="codeLocked"
                            class="field font-mono uppercase disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500"
                            :class="{ 'border-rose-500': form.errors.code }"
                            placeholder="MEMO" />
                        <p v-if="form.errors.code" class="error">{{ form.errors.code }}</p>
                        <p v-else-if="codeLocked" class="mt-1 text-xs text-slate-500">
                            Locked. This type has already issued tracking codes, and those are printed on labels — changing the abbreviation now would make the labels disagree with the system.
                        </p>
                        <p v-else class="mt-1 text-xs text-slate-500">Letters, numbers and dashes. Becomes the front of every tracking code this type issues, and cannot be changed once it has issued one.</p>
                    </label>

                    <label>
                        <span class="required-label text-sm font-semibold text-slate-700">Name</span>
                        <input v-model="form.name" required class="field" :class="{ 'border-rose-500': form.errors.name }" placeholder="Memorandum" />
                        <p v-if="form.errors.name" class="error">{{ form.errors.name }}</p>
                        <p v-else class="mt-1 text-xs text-slate-500">What people choose from on the upload form.</p>
                    </label>
                </div>

                <div class="rounded-xl bg-slate-50 p-4">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">A tracking code for this type will look like</p>
                    <p class="mt-1 font-mono text-lg font-bold text-[#07559e]">{{ samplePrefix }}</p>
                    <p class="mt-1 text-xs text-slate-500">Abbreviation, the date, then a counter that runs per type per day.</p>
                </div>

                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Description</span>
                    <textarea v-model="form.description" rows="3" class="field" :class="{ 'border-rose-500': form.errors.description }" placeholder="When this type should be used."></textarea>
                    <p v-if="form.errors.description" class="error">{{ form.errors.description }}</p>
                </label>

                <div class="grid gap-5 md:grid-cols-2">
                    <label>
                        <span class="text-sm font-semibold text-slate-700">Group</span>
                        <select v-model="form.group_id" class="field" :class="{ 'border-rose-500': form.errors.group_id }">
                            <option value="">Other (ungrouped)</option>
                            <option v-for="group in groups" :key="group.id" :value="group.id">{{ group.name }}</option>
                        </select>
                        <p v-if="form.errors.group_id" class="error">{{ form.errors.group_id }}</p>
                        <p v-else class="mt-1 text-xs text-slate-500">The heading this type appears under when somebody picks a type.</p>
                    </label>

                    <!-- Groups have their own create, edit and delete on the
                         Document Types screen. A name typed in here would be
                         a second way to make one that could then never be
                         renamed or removed from the same place. -->
                    <div class="flex items-end">
                        <Link href="/document-type-groups/create" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                            <i class="fa-solid fa-plus" aria-hidden="true"></i>New group
                        </Link>
                    </div>
                </div>

                <div class="grid gap-5 md:grid-cols-2">
                    <label>
                        <span class="required-label text-sm font-semibold text-slate-700">Order on the form</span>
                        <input v-model.number="form.position" type="number" min="0" required class="field" :class="{ 'border-rose-500': form.errors.position }" />
                        <p v-if="form.errors.position" class="error">{{ form.errors.position }}</p>
                        <p v-else class="mt-1 text-xs text-slate-500">Lower comes first, so the dropdown can follow office convention rather than the alphabet.</p>
                    </label>

                    <div class="rounded-xl border border-slate-200 p-4">
                        <ToggleSwitch v-model="form.is_active" label="Offer this type on the upload form" />
                        <p class="mt-2 text-xs text-slate-500">
                            Turn it off to retire a type you have stopped using. It leaves the upload form and stays available for filtering and counting, and
                            <span v-if="type?.documents_count">the {{ type.documents_count }} document(s) already filed under it are untouched.</span>
                            <span v-else>nothing already filed is affected.</span>
                        </p>
                    </div>
                </div>

                <div class="flex justify-end gap-3 border-t border-slate-200 pt-5">
                    <Button href="/document-types" variant="secondary">Cancel</Button>
                    <Button type="submit" :disabled="form.processing">{{ isEditing ? 'Save changes' : 'Create type' }}</Button>
                </div>
            </form>
        </Card>

        <!-- confirm.open and confirm.processing, never the object itself: a
             reactive object is permanently truthy, so binding open to
             confirm renders this dialog on page load and cancel() cannot
             shut it — what it sets is not what the template reads. The
             processing flag is the composable's too, because that is what
             keeps the dialog open for the length of the request. See
             useConfirmSubmit. (No backticks in here: this is inside a
             template literal, and one would end it.) -->
        <ConfirmActionModal
            :open="confirm.open"
            :processing="confirm.processing"
            :title="isEditing ? 'Save this type?' : 'Create this type?'"
            :message="isEditing
                ? 'Renaming a type does not change what documents already filed under it are labelled — each one keeps the name it was filed as.'
                : 'It will appear on the upload form straight away, and start its own daily tracking-code series.'"
            :confirm-label="isEditing ? 'Save changes' : 'Create type'"
            icon="fa-solid fa-tags"
            @close="cancel"
            @confirm="proceed" />
    </AdminShell>
`,
});
