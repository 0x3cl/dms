import { Head, useForm } from '@inertiajs/vue3';
import { defineComponent, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Button from '../../components/Button';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import ToggleSwitch from '../../components/ToggleSwitch';

/**
 * The two upload policies.
 *
 * One form per scope, each its own `useForm`, so saving one cannot carry the
 * other's unsaved edits with it.
 *
 * Lists are edited as comma-separated text and split on submit. A tag widget
 * would be nicer and is not worth a new component here: these are short,
 * occasionally-edited lists of tokens, and the server normalises and
 * deduplicates whatever arrives anyway.
 */
const ScopeForm = defineComponent({
    name: 'UploadScopeForm',
    components: { Button, ConfirmActionModal, ToggleSwitch },
    props: {
        setting: { type: Object, required: true },
        dangerousDefaults: { type: Array, required: true },
    },
    setup(props) {
        const form = useForm({
            min_size_kb: props.setting.min_size_kb,
            max_size_kb: props.setting.max_size_kb,
            allowed_extensions: props.setting.allowed_extensions.join(', '),
            allowed_mime_types: props.setting.allowed_mime_types.join(', '),
            prohibited_extensions: props.setting.prohibited_extensions.join(', '),
            max_files_per_upload: props.setting.max_files_per_upload,
            max_archive_size_kb: props.setting.max_archive_size_kb,
            max_archive_entries: props.setting.max_archive_entries,
            max_extracted_kb: props.setting.max_extracted_kb,
            max_archive_depth: props.setting.max_archive_depth,
            allow_nested_archives: props.setting.allow_nested_archives,
        });

        const confirming = ref(false);

        const split = (value) => String(value)
            .split(/[,\s]+/)
            .map((token) => token.trim())
            .filter(Boolean);

        const submit = () => {
            form
                .transform((data) => ({
                    ...data,
                    allowed_extensions: split(data.allowed_extensions),
                    allowed_mime_types: split(data.allowed_mime_types),
                    prohibited_extensions: split(data.prohibited_extensions),
                }))
                .put(`/upload-settings/${props.setting.id}`, {
                    preserveScroll: true,
                    onSuccess: () => { confirming.value = false; },
                });
        };

        const restoreDangerous = () => {
            form.prohibited_extensions = props.dangerousDefaults.join(', ');
        };

        return { confirming, form, restoreDangerous, submit };
    },
    template: `
    <Card class="rounded-2xl">
        <div>
            <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">{{ setting.scope }}</p>
            <h2 class="mt-1 text-xl font-bold uppercase tracking-wide text-slate-900">{{ setting.label }}</h2>
            <p class="mt-1 text-slate-600">{{ setting.description }}</p>
        </div>

        <form class="mt-6 space-y-6" @submit.prevent="confirming = true">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <label>
                    <span class="text-sm font-semibold text-slate-700">Smallest allowed (KB)</span>
                    <input v-model.number="form.min_size_kb" type="number" min="0" class="field" :class="{ 'border-rose-500': form.errors.min_size_kb }" />
                    <p v-if="form.errors.min_size_kb" class="error">{{ form.errors.min_size_kb }}</p>
                </label>
                <label>
                    <span class="required-label text-sm font-semibold text-slate-700">Largest allowed (KB)</span>
                    <input v-model.number="form.max_size_kb" type="number" min="1" class="field" :class="{ 'border-rose-500': form.errors.max_size_kb }" />
                    <p v-if="form.errors.max_size_kb" class="error">{{ form.errors.max_size_kb }}</p>
                </label>
                <label>
                    <span class="required-label text-sm font-semibold text-slate-700">Files per upload</span>
                    <input v-model.number="form.max_files_per_upload" type="number" min="1" class="field" :class="{ 'border-rose-500': form.errors.max_files_per_upload }" />
                    <p v-if="form.errors.max_files_per_upload" class="error">{{ form.errors.max_files_per_upload }}</p>
                </label>
            </div>

            <label class="block">
                <span class="required-label text-sm font-semibold text-slate-700">Allowed extensions</span>
                <textarea v-model="form.allowed_extensions" rows="2" class="field" :class="{ 'border-rose-500': form.errors.allowed_extensions }" placeholder="pdf, docx, xlsx"></textarea>
                <p class="mt-1 text-xs text-slate-500">Comma separated, without the dot.</p>
                <p v-if="form.errors.allowed_extensions" class="error">{{ form.errors.allowed_extensions }}</p>
            </label>

            <label class="block">
                <span class="required-label text-sm font-semibold text-slate-700">Allowed MIME types</span>
                <textarea v-model="form.allowed_mime_types" rows="3" class="field" :class="{ 'border-rose-500': form.errors.allowed_mime_types }" placeholder="application/pdf, image/png"></textarea>
                <p class="mt-1 text-xs text-slate-500">Both the extension and the file's real type must be allowed. The extension is what people see; the type is what the file actually is.</p>
                <p v-if="form.errors.allowed_mime_types" class="error">{{ form.errors.allowed_mime_types }}</p>
            </label>

            <label class="block">
                <span class="text-sm font-semibold text-slate-700">Never allowed</span>
                <textarea v-model="form.prohibited_extensions" rows="3" class="field" :class="{ 'border-rose-500': form.errors.prohibited_extensions }"></textarea>
                <p class="mt-1 text-xs text-slate-500">
                    Checked last, so it overrides the allowed list. Use it to retire one risky format without re-auditing everything else.
                    <button type="button" class="font-bold text-[#07559e] hover:underline" @click="restoreDangerous">Restore recommended list</button>
                </p>
                <p v-if="form.errors.prohibited_extensions" class="error">{{ form.errors.prohibited_extensions }}</p>
            </label>

            <fieldset class="rounded-xl border border-slate-200 p-4">
                <legend class="px-2 text-sm font-bold uppercase tracking-wide text-slate-700">Archives</legend>
                <p class="text-sm text-slate-600">Set the entry limit to 0 to forbid extracting archives in this scope entirely.</p>
                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <label>
                        <span class="text-sm font-semibold text-slate-700">Largest archive (KB)</span>
                        <input v-model.number="form.max_archive_size_kb" type="number" min="0" class="field" />
                    </label>
                    <label>
                        <span class="text-sm font-semibold text-slate-700">Most entries</span>
                        <input v-model.number="form.max_archive_entries" type="number" min="0" class="field" />
                    </label>
                    <label>
                        <span class="text-sm font-semibold text-slate-700">Largest extracted total (KB)</span>
                        <input v-model.number="form.max_extracted_kb" type="number" min="0" class="field" />
                        <p class="mt-1 text-xs text-slate-500">The zip-bomb limit: what the archive expands to, not its own size.</p>
                    </label>
                    <label>
                        <span class="text-sm font-semibold text-slate-700">Deepest folder nesting</span>
                        <input v-model.number="form.max_archive_depth" type="number" min="1" class="field" />
                    </label>
                </div>
                <div class="mt-4">
                    <ToggleSwitch v-model="form.allow_nested_archives" label="Allow archives inside archives" />
                </div>
            </fieldset>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-5">
                <p v-if="setting.updated_by" class="text-xs text-slate-500">Last changed by {{ setting.updated_by }}.</p>
                <span v-else></span>
                <Button type="submit" :disabled="form.processing">Save {{ setting.label.toLowerCase() }} rules</Button>
            </div>
        </form>

        <ConfirmActionModal
            :open="confirming"
            :processing="form.processing"
            title="Change upload rules?"
            message="These rules are enforced on the server for every upload in this scope. Widening them lets people upload file types the office did not accept before."
            confirm-label="Save rules"
            icon="fa-solid fa-sliders"
            @close="confirming = false"
            @confirm="submit" />
    </Card>
`,
});

export default defineComponent({
    name: 'UploadSettings',
    components: { AdminShell, Card, Head, ScopeForm },
    props: {
        settings: { type: Array, required: true },
        dangerousDefaults: { type: Array, required: true },
    },
    template: `
    <Head title="Upload Rules" />
    <AdminShell active="upload-settings" title="Upload Rules">
        <div class="space-y-5">
            <Card class="rounded-2xl">
                <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Settings</p>
                <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">What can be uploaded</h2>
                <p class="mt-1 text-slate-600">
                    Two policies, because the two things being uploaded are not the same kind of thing. Every rule here is enforced on the server;
                    the browser is also told the limits so it can refuse early, but that is a courtesy rather than the control.
                </p>
            </Card>

            <ScopeForm v-for="setting in settings" :key="setting.id" :setting="setting" :dangerous-defaults="dangerousDefaults" />
        </div>
    </AdminShell>
`,
});
