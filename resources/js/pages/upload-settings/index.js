import { Head, useForm } from '@inertiajs/vue3';
import { computed, defineComponent, reactive, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Button from '../../components/Button';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import ToggleSwitch from '../../components/ToggleSwitch';
import useTabbedErrors from '../../composables/useTabbedErrors';
import useUrlTab from '../../composables/useUrlTab';

/**
 * The upload policy, as tabs rather than as one long form.
 *
 * ## One policy
 *
 * This screen used to edit two — `documents` and `files` — as two stacked
 * forms, on the reasoning that a tracked document and a file in personal
 * storage are different kinds of thing. They are one store and one workflow, so
 * the split was two answers to one question ("may this office upload a
 * spreadsheet?"), and nothing on screen said which upload form read which form.
 * An administrator could widen the rules and watch the upload fail anyway.
 *
 * ## Why tabs
 *
 * What is left is still three unrelated questions — what may be uploaded, how
 * much of it, and what happens to archives — and stacking them was about two
 * thousand pixels of form in which the field somebody came to change was never
 * on screen beside the thing it affects. The tab lives in the query string, so
 * "the file types" is a link somebody can be sent.
 *
 * ## Why the file types are a catalogue and not a text box
 *
 * The form used to ask for extensions *and* MIME types *and* a never-allowed
 * list, and an upload had to satisfy all three. That is what rejected a real
 * spreadsheet with "is not the kind of file it claims to be": `xlsx` had been
 * typed into the extensions box, and the MIME box — whose vocabulary nobody
 * knows by hand — had not gained the matching OOXML type.
 *
 * So the form offers what the server knows: a tick per catalogue entry, and the
 * MIME types that entry accepts shown beneath it as information rather than as
 * a field. An administrator cannot now grant a type the gate could not verify,
 * and cannot grant one with a stray dot or a capital letter that silently never
 * matches.
 *
 * Executable formats are not in the catalogue at all. They are refused in code,
 * so there is nothing here to switch off.
 */
const SIZE_HINTS = [
    { kb: 1024, label: '1 MB' },
    { kb: 5120, label: '5 MB' },
    { kb: 10240, label: '10 MB' },
    { kb: 25600, label: '25 MB' },
    { kb: 51200, label: '50 MB' },
    { kb: 102400, label: '100 MB' },
    { kb: 512000, label: '500 MB' },
];

const readableKb = (kb) => {
    const value = Number(kb) || 0;

    if (value <= 0) {
        return '—';
    }

    return value >= 1024 ? `${Number((value / 1024).toFixed(1))} MB` : `${value} KB`;
};

export default defineComponent({
    name: 'UploadSettings',
    components: { AdminShell, Button, Card, ConfirmActionModal, Head, ToggleSwitch },
    props: {
        setting: { type: Object, required: true },
        catalogue: { type: Array, required: true },
        neverAllowed: { type: Array, required: true },
    },
    setup(props) {
        /*
         * The type list is held as a map keyed by extension rather than as the
         * array the server wants, because the form's unit of interaction is
         * "this extension" — a toggle and two numbers — and an array would mean
         * finding and splicing a row on every click. It is flattened back on
         * submit, which is also where a disabled row is dropped: an extension
         * nobody permitted should not travel to the server carrying the size
         * window it had before it was switched off.
         */
        const types = reactive({});

        props.catalogue.forEach((group) => {
            group.types.forEach((type) => {
                types[type.extension] = { enabled: false, min_size_kb: 0, max_size_kb: 0 };
            });
        });

        props.setting.allowed_types.forEach((type) => {
            types[type.extension] = {
                enabled: true,
                min_size_kb: type.min_size_kb || 0,
                max_size_kb: type.max_size_kb || 0,
            };
        });

        const form = useForm({
            min_size_kb: props.setting.min_size_kb,
            max_size_kb: props.setting.max_size_kb,
            max_files_per_upload: props.setting.max_files_per_upload,
            max_archive_size_kb: props.setting.max_archive_size_kb,
            max_archive_entries: props.setting.max_archive_entries,
            max_extracted_kb: props.setting.max_extracted_kb,
            max_archive_depth: props.setting.max_archive_depth,
            allow_nested_archives: props.setting.allow_nested_archives,
            allowed_types: [],
        });

        const confirming = ref(false);

        /*
         * Both selections live in the URL, so the screen somebody is looking at
         * is the screen they can send: `?tab=types&category=spreadsheets` opens
         * on the spreadsheet list rather than on whatever is first.
         *
         * `?tab=` is the panel, because it is the page's primary navigation and
         * `?tab=` is the name every tab set in this application uses. The
         * category chips take `?category=`, and they have to take *something*
         * else for a concrete reason rather than a stylistic one: both sets
         * contain a value literally named `archives` — the Archives panel and
         * the Archives file category — so one shared parameter would make
         * `?tab=archives` mean two different things, and every click in one row
         * would overwrite the other row's position.
         */
        const panel = useUrlTab('tab', ['types', 'limits', 'archives'], 'types');
        const groups = props.catalogue.map((entry) => entry.group);
        const group = useUrlTab('category', groups, groups[0] ?? 'documents');

        /*
         * A refusal lands on the panel that caused it.
         *
         * One form across three panels means the server can reject a field
         * the person is not looking at — an archive limit while they are on
         * Types — and the page would scroll to the top with no sentence
         * anywhere on screen saying why. Declared rather than read off the
         * DOM, in panel order, so "the first error" means the first one
         * somebody reading the tab row would reach.
         */
        useTabbedErrors(form, panel, {
            types: ['allowed_types'],
            limits: ['min_size_kb', 'max_size_kb', 'max_files_per_upload'],
            archives: [
                'max_archive_size_kb',
                'max_archive_entries',
                'max_extracted_kb',
                'max_archive_depth',
                'allow_nested_archives',
            ],
        });

        const permitted = computed(() => Object.entries(types).filter(([, type]) => type.enabled));

        const permittedInGroup = (groupKey) => {
            const entry = props.catalogue.find((candidate) => candidate.group === groupKey);

            return (entry?.types ?? []).filter((type) => types[type.extension]?.enabled).length;
        };

        const activeGroup = computed(
            () => props.catalogue.find((candidate) => candidate.group === group.value) ?? props.catalogue[0],
        );

        /** The error the server reported against this extension's row, if any. */
        const errorFor = (extension) => {
            const index = form.allowed_types.findIndex((type) => type.extension === extension);

            if (index < 0) {
                return null;
            }

            return form.errors[`allowed_types.${index}.min_size_kb`]
                ?? form.errors[`allowed_types.${index}.max_size_kb`]
                ?? form.errors[`allowed_types.${index}.extension`]
                ?? null;
        };

        const toggleGroup = (groupKey, enabled) => {
            const entry = props.catalogue.find((candidate) => candidate.group === groupKey);

            (entry?.types ?? []).forEach((type) => {
                types[type.extension].enabled = enabled;
            });
        };

        const submit = () => {
            form
                .transform((data) => ({
                    ...data,
                    allowed_types: Object.entries(types)
                        .filter(([, type]) => type.enabled)
                        .map(([extension, type]) => ({
                            extension,
                            min_size_kb: Number(type.min_size_kb) || 0,
                            max_size_kb: Number(type.max_size_kb) || 0,
                        })),
                }))
                // No id in the URL: there is one policy, so it has nothing to name.
                .put('/upload-settings', {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => { confirming.value = false; },
                });
        };

        return {
            activeGroup, confirming, errorFor, form, group, panel, permitted, permittedInGroup,
            readableKb, SIZE_HINTS, submit, toggleGroup, types,
        };
    },
    template: `
    <Head title="Upload Rules" />
    <AdminShell active="upload-settings" title="Upload Rules">
        <Card class="rounded-2xl">
            <div>
                <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Settings</p>
                <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">What can be uploaded</h2>
                <p class="mt-1 max-w-3xl text-slate-600">
                    One policy, enforced on the server for every upload — a tracked document, a file in someone's own
                    storage, or an entry inside an archive. The browser is told the limits too so it can refuse early,
                    but that is a courtesy rather than the control.
                </p>
            </div>

            <form class="mt-6" @submit.prevent="confirming = true">
                <div class="flex flex-wrap items-center gap-2 rounded-lg bg-slate-100 p-1">
                    <button
                        v-for="entry in [
                            { key: 'types', label: 'File types', icon: 'fa-solid fa-file-circle-check' },
                            { key: 'limits', label: 'Sizes & counts', icon: 'fa-solid fa-ruler' },
                            { key: 'archives', label: 'Archives', icon: 'fa-solid fa-file-zipper' },
                        ]"
                        :key="entry.key"
                        type="button"
                        class="flex-1 rounded-md px-4 py-2 text-xs font-bold uppercase tracking-wide transition"
                        :class="panel === entry.key ? 'bg-white text-[#07559e] shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                        @click="panel = entry.key">
                        <i :class="entry.icon" class="mr-2" aria-hidden="true"></i>{{ entry.label }}
                    </button>
                </div>

                <!-- ------------------------------------------------ File types -->
                <div v-show="panel === 'types'" class="mt-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-bold uppercase tracking-wide text-slate-900">What may be uploaded</h3>
                            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                                A file type not ticked here is refused. There is no second list to keep in step — the file's
                                real contents are checked against the type its name claims, using the formats shown under
                                each entry.
                            </p>
                        </div>
                        <p class="rounded-lg bg-sky-50 px-3 py-2 text-xs font-bold uppercase tracking-wide text-[#07559e]">
                            {{ permitted.length }} permitted
                        </p>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <button
                            v-for="entry in catalogue"
                            :key="entry.group"
                            type="button"
                            class="rounded-full border px-3 py-1.5 text-xs font-bold uppercase tracking-wide transition"
                            :class="group === entry.group
                                ? 'border-[#07559e] bg-[#07559e] text-white'
                                : 'border-slate-200 text-slate-500 hover:border-slate-300 hover:text-slate-700'"
                            @click="group = entry.group">
                            {{ entry.group_label }}
                            <span class="ml-1 opacity-80">{{ permittedInGroup(entry.group) }}/{{ entry.types.length }}</span>
                        </button>
                    </div>

                    <div v-if="activeGroup" class="mt-4">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 pb-3">
                            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">
                                {{ activeGroup.group_label }} · leave a size blank to use the default window
                            </p>
                            <div class="flex gap-2">
                                <button type="button" class="text-xs font-bold text-[#07559e] hover:underline" @click="toggleGroup(activeGroup.group, true)">Permit all</button>
                                <span class="text-slate-300">|</span>
                                <button type="button" class="text-xs font-bold text-slate-500 hover:underline" @click="toggleGroup(activeGroup.group, false)">Permit none</button>
                            </div>
                        </div>

                        <ul class="divide-y divide-slate-100">
                            <li v-for="type in activeGroup.types" :key="type.extension" class="py-3">
                                <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
                                    <label class="flex min-w-[220px] flex-1 items-center gap-3">
                                        <input
                                            v-model="types[type.extension].enabled"
                                            type="checkbox"
                                            class="h-4 w-4 rounded border-slate-300 text-[#07559e]" />
                                        <span>
                                            <span class="font-bold text-slate-900">.{{ type.extension }}</span>
                                            <span class="ml-2 text-sm text-slate-600">{{ type.label }}</span>
                                        </span>
                                    </label>

                                    <div v-if="types[type.extension].enabled" class="flex items-center gap-2">
                                        <label class="flex items-center gap-2">
                                            <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Min KB</span>
                                            <input v-model.number="types[type.extension].min_size_kb" type="number" min="0" class="field w-24 py-1.5" placeholder="0" />
                                        </label>
                                        <label class="flex items-center gap-2">
                                            <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Max KB</span>
                                            <input v-model.number="types[type.extension].max_size_kb" type="number" min="0" class="field w-28 py-1.5" :placeholder="String(form.max_size_kb)" />
                                        </label>
                                        <span class="text-xs text-slate-500">
                                            = {{ readableKb(types[type.extension].max_size_kb || form.max_size_kb) }}
                                        </span>
                                    </div>
                                </div>

                                <p v-if="types[type.extension].enabled" class="mt-1 pl-7 text-xs text-slate-400">
                                    Accepted as: {{ type.mimes.join(', ') }}
                                </p>
                                <p v-if="errorFor(type.extension)" class="error pl-7">{{ errorFor(type.extension) }}</p>
                            </li>
                        </ul>
                    </div>

                    <p v-if="form.errors.allowed_types" class="error">{{ form.errors.allowed_types }}</p>

                    <p class="mt-4 rounded-xl bg-slate-50 p-3 text-xs text-slate-500">
                        <i class="fa-solid fa-shield-halved mr-2 text-slate-400" aria-hidden="true"></i>
                        Program and script formats ({{ neverAllowed.slice(0, 8).join(', ') }} and {{ neverAllowed.length - 8 }} more)
                        are refused by the server whatever this screen says, so there is nothing here to switch off.
                    </p>
                </div>

                <!-- -------------------------------------------- Sizes & counts -->
                <div v-show="panel === 'limits'" class="mt-5 space-y-5">
                    <div>
                        <h3 class="text-sm font-bold uppercase tracking-wide text-slate-900">Default size window</h3>
                        <p class="mt-1 max-w-2xl text-sm text-slate-600">
                            Used by any permitted type whose own maximum is left blank. A type with its own window ignores these.
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <label>
                            <span class="text-sm font-semibold text-slate-700">Smallest allowed (KB)</span>
                            <input v-model.number="form.min_size_kb" type="number" min="0" class="field" :class="{ 'border-rose-500': form.errors.min_size_kb }" />
                            <p v-if="form.errors.min_size_kb" class="error">{{ form.errors.min_size_kb }}</p>
                        </label>
                        <label>
                            <span class="required-label text-sm font-semibold text-slate-700">Largest allowed (KB)</span>
                            <input v-model.number="form.max_size_kb" type="number" min="1" class="field" :class="{ 'border-rose-500': form.errors.max_size_kb }" />
                            <p class="mt-1 text-xs text-slate-500">{{ readableKb(form.max_size_kb) }}</p>
                            <p v-if="form.errors.max_size_kb" class="error">{{ form.errors.max_size_kb }}</p>
                        </label>
                        <label>
                            <span class="required-label text-sm font-semibold text-slate-700">Files per upload</span>
                            <input v-model.number="form.max_files_per_upload" type="number" min="1" class="field" :class="{ 'border-rose-500': form.errors.max_files_per_upload }" />
                            <p class="mt-1 text-xs text-slate-500">A tracked document is always one file; this is the multi-file upload.</p>
                            <p v-if="form.errors.max_files_per_upload" class="error">{{ form.errors.max_files_per_upload }}</p>
                        </label>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-bold uppercase tracking-wide text-slate-500">Set largest to</span>
                        <button
                            v-for="hint in SIZE_HINTS"
                            :key="hint.kb"
                            type="button"
                            class="rounded-full border border-slate-200 px-3 py-1 text-xs font-bold text-slate-600 transition hover:border-[#07559e] hover:text-[#07559e]"
                            @click="form.max_size_kb = hint.kb">
                            {{ hint.label }}
                        </button>
                    </div>

                    <div class="rounded-xl bg-slate-50 p-4">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Permitted types and their ceilings</p>
                        <p v-if="permitted.length === 0" class="mt-2 text-sm text-slate-500">Nothing is permitted yet.</p>
                        <ul v-else class="mt-2 flex flex-wrap gap-2">
                            <li
                                v-for="[extension, type] in permitted"
                                :key="extension"
                                class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-600">
                                <span class="font-bold text-slate-900">.{{ extension }}</span>
                                <span class="ml-2">{{ readableKb(type.max_size_kb || form.max_size_kb) }}</span>
                                <span v-if="!type.max_size_kb" class="ml-1 text-slate-400">(default)</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- -------------------------------------------------- Archives -->
                <div v-show="panel === 'archives'" class="mt-5 space-y-5">
                    <div>
                        <h3 class="text-sm font-bold uppercase tracking-wide text-slate-900">Archives</h3>
                        <p class="mt-1 max-w-2xl text-sm text-slate-600">
                            Set the entry limit to 0 to forbid extracting archives entirely. Extraction never uses a path
                            from inside the archive, so these limits are about cost rather than about safety. Every entry
                            is checked against the same file types as a direct upload.
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <label>
                            <span class="text-sm font-semibold text-slate-700">Largest archive (KB)</span>
                            <input v-model.number="form.max_archive_size_kb" type="number" min="0" class="field" />
                            <p class="mt-1 text-xs text-slate-500">{{ readableKb(form.max_archive_size_kb) }}</p>
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

                    <ToggleSwitch v-model="form.allow_nested_archives" label="Allow archives inside archives" />
                </div>

                <div class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-5">
                    <p v-if="setting.updated_by" class="text-xs text-slate-500">Last changed by {{ setting.updated_by }}.</p>
                    <span v-else></span>
                    <Button type="submit" :disabled="form.processing">Save upload rules</Button>
                </div>

                <ConfirmActionModal
                    :open="confirming"
                    :processing="form.processing"
                    title="Change upload rules?"
                    message="These rules are enforced on the server for every upload in the application. Widening them lets people upload file types the office did not accept before."
                    confirm-label="Save rules"
                    icon="fa-solid fa-sliders"
                    @close="confirming = false"
                    @confirm="submit" />
            </form>
        </Card>
    </AdminShell>
`,
});
