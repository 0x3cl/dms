import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, defineComponent } from 'vue';
import AdminShell from '../../components/AdminShell';
import Button from '../../components/Button';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import SearchableSelect from '../../components/SearchableSelect';
import useConfirmSubmit from '../../composables/useConfirmSubmit';

export default defineComponent({
    name: 'SectionForm',
    components: { AdminShell, Button, Card, ConfirmActionModal, Head, Link, SearchableSelect },
    props: {
        section: { type: Object, default: null },
        divisions: { type: Array, default: () => [] },
    },
    setup(props) {
        const isEditing = computed(() => Boolean(props.section));
        // The code and the name are each unique *within* a division, not
        // globally: two divisions may both run a "Records" section.
        const form = useForm({
            division_id: props.section?.division_id ?? '',
            code: props.section?.code ?? '',
            name: props.section?.name ?? '',
            description: props.section?.description ?? '',
        });

        const divisionOptions = computed(() => props.divisions.map((division) => ({
            value: division.id,
            label: `${division.name} (${division.code})`,
        })));

        const submitForm = (options) => {
            if (isEditing.value) {
                form.put(`/sections/${props.section.id}`, options);
            } else {
                form.post('/sections', options);
            }
        };

        const { confirm, request, cancel, proceed } = useConfirmSubmit(submitForm);

        return { cancel, confirm, divisionOptions, form, isEditing, proceed, request };
    },
    template: `
    <Head :title="isEditing ? 'Edit section' : 'Add section'" />
    <AdminShell active="sections" :title="isEditing ? 'Edit section' : 'Add section'">
        <Card class="w-full">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-5">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#07559e]">Directory</p>
                    <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">{{ isEditing ? 'Update section' : 'Create section' }}</h2>
                    <p class="mt-1 text-slate-600">Fields marked with <span class="font-bold text-rose-600">*</span> are required.</p>
                </div>
                <Link href="/sections" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 hover:border-[#07559e] hover:text-[#07559e]"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back</Link>
            </div>
            <form class="mt-7 grid gap-5 md:grid-cols-2" @submit.prevent="request">
                <label>
                    <span class="required-label text-sm font-semibold text-slate-700">Section code</span>
                    <input v-model="form.code" class="field uppercase" :class="{ 'border-rose-500': form.errors.code }" placeholder="RECORDS" />
                    <p v-if="form.errors.code" class="error">{{ form.errors.code }}</p>
                    <p class="mt-1 text-xs text-slate-500">Unique within the division. It stays the same if the section is renamed.</p>
                </label>
                <label>
                    <span class="required-label text-sm font-semibold text-slate-700">Section name</span>
                    <input v-model="form.name" class="field" :class="{ 'border-rose-500': form.errors.name }" />
                    <p v-if="form.errors.name" class="error">{{ form.errors.name }}</p>
                </label>
                <div>
                    <span class="required-label text-sm font-semibold text-slate-700">Division</span>
                    <div class="mt-2">
                        <SearchableSelect v-model="form.division_id" :options="divisionOptions" placeholder="Select a division" :invalid="Boolean(form.errors.division_id)" />
                    </div>
                    <p v-if="form.errors.division_id" class="error">{{ form.errors.division_id }}</p>
                </div>
                <div class="md:col-span-2">
                    <span class="text-sm font-semibold text-slate-700">Description (optional)</span>
                    <textarea v-model="form.description" rows="2" class="field" :class="{ 'border-rose-500': form.errors.description }"></textarea>
                    <p v-if="form.errors.description" class="error">{{ form.errors.description }}</p>
                </div>
                <div class="flex justify-end gap-3 border-t border-slate-200 pt-5 md:col-span-2">
                    <Button variant="secondary" href="/sections">Cancel</Button>
                    <Button type="submit" variant="primary" :disabled="form.processing"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>{{ isEditing ? 'Save' : 'Create section' }}</Button>
                </div>
            </form>
        </Card>

        <ConfirmActionModal
            :open="confirm.open"
            :processing="confirm.processing"
            :title="isEditing ? 'Save this section?' : 'Create this section?'"
            message="Are you sure you want to proceed?"
            @close="cancel"
            @confirm="proceed" />
    </AdminShell>
`,
});
