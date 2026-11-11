import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, defineComponent } from 'vue';
import AdminShell from '../../components/AdminShell';
import Button from '../../components/Button';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import useConfirmSubmit from '../../composables/useConfirmSubmit';

/**
 * One component serves create and edit, branching on the nullable `division`
 * prop. This is the reference form page — see documentation/design-system.md.
 */
export default defineComponent({
    name: 'DivisionForm',
    components: { AdminShell, Button, Card, ConfirmActionModal, Head, Link },
    props: {
        division: { type: Object, default: null },
    },
    setup(props) {
        const isEditing = computed(() => Boolean(props.division));
        // `code` is the division's short, stable handle: it survives a rename,
        // which is what makes it safe to print or match across systems.
        const form = useForm({
            code: props.division?.code ?? '',
            name: props.division?.name ?? '',
            description: props.division?.description ?? '',
        });

        const submitForm = (options) => {
            if (isEditing.value) {
                form.put(`/divisions/${props.division.id}`, options);
            } else {
                form.post('/divisions', options);
            }
        };

        const { confirm, request, cancel, proceed } = useConfirmSubmit(submitForm);

        return { cancel, confirm, form, isEditing, proceed, request };
    },
    template: `
    <Head :title="isEditing ? 'Edit division' : 'Add division'" />
    <AdminShell active="divisions" :title="isEditing ? 'Edit division' : 'Add division'">
        <Card class="w-full">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-5">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#07559e]">Directory</p>
                    <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">{{ isEditing ? 'Update division' : 'Create division' }}</h2>
                    <p class="mt-1 text-slate-600">Fields marked with <span class="font-bold text-rose-600">*</span> are required.</p>
                </div>
                <Link href="/divisions" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 hover:border-[#07559e] hover:text-[#07559e]"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back</Link>
            </div>
            <form class="mt-7 grid gap-5 md:grid-cols-2" @submit.prevent="request">
                <label>
                    <span class="required-label text-sm font-semibold text-slate-700">Division code</span>
                    <input v-model="form.code" class="field uppercase" :class="{ 'border-rose-500': form.errors.code }" placeholder="ADMIN" />
                    <p v-if="form.errors.code" class="error">{{ form.errors.code }}</p>
                    <p class="mt-1 text-xs text-slate-500">A short handle, letters and dashes. It stays the same if the division is renamed.</p>
                </label>
                <label>
                    <span class="required-label text-sm font-semibold text-slate-700">Division name</span>
                    <input v-model="form.name" class="field" :class="{ 'border-rose-500': form.errors.name }" />
                    <p v-if="form.errors.name" class="error">{{ form.errors.name }}</p>
                </label>
                <label class="md:col-span-2">
                    <span class="text-sm font-semibold text-slate-700">Description (optional)</span>
                    <textarea v-model="form.description" rows="2" class="field" :class="{ 'border-rose-500': form.errors.description }"></textarea>
                    <p v-if="form.errors.description" class="error">{{ form.errors.description }}</p>
                </label>
                <div class="flex justify-end gap-3 border-t border-slate-200 pt-5 md:col-span-2">
                    <Button variant="secondary" href="/divisions">Cancel</Button>
                    <Button type="submit" variant="primary" :disabled="form.processing"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>{{ isEditing ? 'Save' : 'Create division' }}</Button>
                </div>
            </form>
        </Card>

        <ConfirmActionModal
            :open="confirm.open"
            :processing="confirm.processing"
            :title="isEditing ? 'Save this division?' : 'Create this division?'"
            message="Are you sure you want to proceed?"
            @close="cancel"
            @confirm="proceed" />
    </AdminShell>
`,
});
