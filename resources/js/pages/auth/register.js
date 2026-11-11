import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, defineComponent, watch } from 'vue';
import SearchableSelect from '../../components/SearchableSelect';
import { organization } from '../../organization';

/**
 * Divisions arrive with their sections nested, so the section picker narrows
 * client-side. The Blade version pulled jQuery from a CDN to fetch
 * /sections/by-division/{id} on every change; that endpoint still exists for
 * other callers, but this page no longer needs it.
 */
export default defineComponent({
    name: 'Register',
    components: { Head, Link, SearchableSelect },
    props: {
        divisions: { type: Array, default: () => [] },
    },
    setup(props) {
        const form = useForm({
            first_name: '',
            last_name: '',
            employee_id: '',
            division_id: '',
            section_id: '',
            password: '',
            password_confirmation: '',
        });

        const divisionOptions = computed(() => props.divisions.map((division) => ({
            value: division.id,
            label: division.name,
        })));

        const sectionOptions = computed(() => {
            const division = props.divisions.find((candidate) => candidate.id === form.division_id);

            return (division?.sections ?? []).map((section) => ({
                value: section.id,
                label: section.name,
            }));
        });

        // A section belongs to exactly one division, so a division change can
        // leave a section selected that no longer belongs to it.
        watch(() => form.division_id, () => { form.section_id = ''; });

        const submit = () => form.post('/register', {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });

        return { divisionOptions, form, org: organization, sectionOptions, submit };
    },
    template: `
    <Head title="Register" />
    <main class="flex flex-1 items-center bg-slate-50 px-5 py-10 sm:px-8 sm:py-14 lg:px-12 lg:py-16">
        <section class="mx-auto w-full max-w-2xl">
            <form class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm sm:p-11" @submit.prevent="submit">
                <div class="flex items-center gap-3 border-b border-slate-200 pb-5">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-sky-100 text-lg text-[#07559e]">
                        <i class="fa-solid fa-user-plus" aria-hidden="true"></i>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-[#07559e]">{{ org.title }}</p>
                        <h1 class="text-xl font-semibold uppercase tracking-wide text-slate-900">Create an account</h1>
                    </div>
                </div>
                <p class="mt-5 text-sm text-slate-600">Fields marked with <span class="font-bold text-rose-600">*</span> are required.</p>

                <div class="mt-7 grid gap-5 sm:grid-cols-2">
                    <label>
                        <span class="required-label text-sm font-semibold text-slate-700">First name</span>
                        <input v-model="form.first_name" autocomplete="given-name" class="field" :class="{ 'border-rose-500': form.errors.first_name }" />
                        <p v-if="form.errors.first_name" class="error">{{ form.errors.first_name }}</p>
                    </label>
                    <label>
                        <span class="required-label text-sm font-semibold text-slate-700">Last name</span>
                        <input v-model="form.last_name" autocomplete="family-name" class="field" :class="{ 'border-rose-500': form.errors.last_name }" />
                        <p v-if="form.errors.last_name" class="error">{{ form.errors.last_name }}</p>
                    </label>
                    <label class="sm:col-span-2">
                        <span class="required-label text-sm font-semibold text-slate-700">Employee ID</span>
                        <input v-model="form.employee_id" autocomplete="username" placeholder="e.g. EMP-0001" class="field" :class="{ 'border-rose-500': form.errors.employee_id }" />
                        <p v-if="form.errors.employee_id" class="error">{{ form.errors.employee_id }}</p>
                    </label>
                    <div>
                        <span class="required-label text-sm font-semibold text-slate-700">Division</span>
                        <div class="mt-2">
                            <SearchableSelect v-model="form.division_id" :options="divisionOptions" placeholder="Select a division" :invalid="Boolean(form.errors.division_id)" />
                        </div>
                        <p v-if="form.errors.division_id" class="error">{{ form.errors.division_id }}</p>
                    </div>
                    <div>
                        <span class="required-label text-sm font-semibold text-slate-700">Section</span>
                        <div class="mt-2">
                            <SearchableSelect v-model="form.section_id" :options="sectionOptions" :disabled="!form.division_id" :placeholder="form.division_id ? 'Select a section' : 'Pick a division first'" :invalid="Boolean(form.errors.section_id)" />
                        </div>
                        <p v-if="form.errors.section_id" class="error">{{ form.errors.section_id }}</p>
                    </div>
                    <label>
                        <span class="required-label text-sm font-semibold text-slate-700">Password</span>
                        <input v-model="form.password" type="password" autocomplete="new-password" class="field" :class="{ 'border-rose-500': form.errors.password }" />
                        <p v-if="form.errors.password" class="error">{{ form.errors.password }}</p>
                    </label>
                    <label>
                        <span class="required-label text-sm font-semibold text-slate-700">Confirm password</span>
                        <input v-model="form.password_confirmation" type="password" autocomplete="new-password" class="field" :class="{ 'border-rose-500': form.errors.password_confirmation }" />
                        <p v-if="form.errors.password_confirmation" class="error">{{ form.errors.password_confirmation }}</p>
                    </label>
                </div>

                <div class="mt-8 flex flex-wrap items-center justify-between gap-4 border-t border-slate-200 pt-5">
                    <Link href="/login" class="text-sm font-semibold text-[#07559e] hover:text-[#043d78]">Already registered? Sign in</Link>
                    <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#00aeef] px-5 py-3 text-sm font-bold uppercase tracking-wide text-white transition hover:bg-[#008dcc] disabled:bg-slate-400">{{ form.processing ? 'Creating…' : 'Create account' }}</button>
                </div>
            </form>
        </section>
    </main>
`,
});
