import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, defineComponent } from 'vue';
import AdminShell from '../../components/AdminShell';
import Button from '../../components/Button';
import Card from '../../components/Card';

/**
 * A heading in the document-type picker.
 *
 * The parent of a type, with its own create and edit — it began as a name
 * typed into the type form, which meant a group could be invented and then
 * never renamed, reordered or removed. A list the office is stuck with the
 * first time somebody mistypes one is not a list they control.
 *
 * No `code` field, and that is the difference from the type form beside it.
 * A *type's* code is stamped into every tracking code it has issued and is
 * printed on paper, so it is asked for and then locked; a group's code never
 * leaves the database — it is only the handle a seeder matches on — so it is
 * generated and never shown.
 */
export default defineComponent({
    name: 'DocumentTypeGroupForm',
    components: { AdminShell, Button, Card, Head, Link },
    props: {
        group: { type: Object, default: null },
    },
    setup(props) {
        const isEditing = computed(() => Boolean(props.group));

        const form = useForm({
            name: props.group?.name ?? '',
            description: props.group?.description ?? '',
            position: props.group?.position ?? 0,
        });

        const submit = () => (isEditing.value
            ? form.put(`/document-type-groups/${props.group.id}`)
            : form.post('/document-type-groups'));

        return { form, isEditing, submit };
    },
    template: `
    <Head :title="isEditing ? 'Edit group' : 'Add group'" />
    <AdminShell active="document-types" :title="isEditing ? 'Edit group' : 'Add group'">
        <Card class="w-full">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-5">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#07559e]">Document types</p>
                    <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">{{ isEditing ? 'Edit group' : 'Add group' }}</h2>
                    <p class="mt-2 max-w-2xl text-slate-600">A group is the heading types appear under when somebody picks one, so a long list reads as a few short ones. It gates nothing and is never part of a tracking code.</p>
                </div>
                <Link href="/document-types?tab=groups" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 transition hover:border-[#07559e] hover:text-[#07559e]">
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back
                </Link>
            </div>

            <form class="mt-7 space-y-5" @submit.prevent="submit">
                <label class="block">
                    <span class="required-label text-sm font-semibold text-slate-700">Name</span>
                    <input v-model="form.name" class="field" :class="{ 'border-rose-500': form.errors.name }" maxlength="60" placeholder="e.g. Videos" />
                    <p v-if="form.errors.name" class="error">{{ form.errors.name }}</p>
                </label>

                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Description</span>
                    <textarea v-model="form.description" rows="2" class="field" :class="{ 'border-rose-500': form.errors.description }" maxlength="255" placeholder="What belongs in this group."></textarea>
                    <p v-if="form.errors.description" class="error">{{ form.errors.description }}</p>
                </label>

                <label class="block md:w-64">
                    <span class="required-label text-sm font-semibold text-slate-700">Order in the picker</span>
                    <input v-model.number="form.position" type="number" min="0" class="field" :class="{ 'border-rose-500': form.errors.position }" />
                    <p v-if="form.errors.position" class="error">{{ form.errors.position }}</p>
                    <p v-else class="mt-1 text-xs text-slate-500">Lower comes first. "Other" — the types with no group — is always last.</p>
                </label>

                <!-- What deleting one does, said where somebody is editing it,
                     so the consequence is known before the Delete button on
                     the list is reached. -->
                <p v-if="isEditing && group.types_count" class="rounded-xl bg-slate-50 px-4 py-3 text-xs text-slate-600">
                    {{ group.types_count }} type(s) sit under this group. Deleting it moves them to <span class="font-bold">Other</span> — it never retires a type, because a type is part of the record and a group is only how the list is arranged.
                </p>

                <div class="flex flex-wrap gap-3 border-t border-slate-200 pt-5">
                    <Button type="submit" variant="primary" :disabled="form.processing">
                        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>{{ isEditing ? 'Save changes' : 'Add group' }}
                    </Button>
                    <Link href="/document-types?tab=groups" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50">Cancel</Link>
                </div>
            </form>
        </Card>
    </AdminShell>
`,
});
