import { Head, Link, router } from '@inertiajs/vue3';
import { computed, defineComponent, reactive, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import Pagination from '../../components/Pagination';
import RowActions from '../../components/RowActions';
import UserAvatar from '../../components/UserAvatar';
import TrailFilters from '../../components/TrailFilters';
import TrailVersionTabs from '../../components/TrailVersionTabs';
import { formatTimestamp } from '../../utils/format-date';

/**
 * A document's full travel history. The route used to return raw JSON that
 * nothing consumed.
 *
 * The table carries only what distinguishes one entry from another — when,
 * what, and who. Division, section, the employee id and the complete remarks
 * were columns, which is what forced the `min-w-[900px]` that pushed the right
 * of the table off a laptop screen; they are the *same two values* on nearly
 * every row of a given document's trail, so they crowded out the parts that
 * differ. They live in the detail modal now.
 */
export default defineComponent({
    name: 'DocumentLogs',
    components: { AdminShell, Card, ConfirmActionModal, Head, Link, Pagination, RowActions, TrailFilters, TrailVersionTabs, UserAvatar },
    props: {
        document: { type: Object, required: true },
        logs: { type: Object, required: true },
        actions: { type: Array, default: () => [] },
        filters: { type: Object, default: () => ({ search: '', action: '' }) },
        /** Only the offices this trail actually contains. */
        offices: { type: Array, default: () => [] },
        /** Only the file versions this trail has rows against. */
        versions: { type: Array, default: () => [] },
        /** The same split as `[{version, entries}]`, for the tabs. */
        versionTabs: { type: Array, default: () => [] },
    },
    setup(props) {
        const page = (url) => url && router.get(url, {}, { preserveScroll: true });

        const trail = reactive({
            search: props.filters.search ?? '',
            action: props.filters.action ?? '',
            from: props.filters.from ?? '',
            to: props.filters.to ?? '',
            office: props.filters.office ?? '',
            version: props.filters.version ?? '',
            entries: props.filters.entries ?? 12,
        });
        let timer;
        const load = () => router.get(window.location.pathname, { ...trail }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
        const search = () => {
            clearTimeout(timer);
            timer = setTimeout(load, 350);
        };

        /*
         * Everything but the search lives behind the Filter button, in the
         * same shape My Files uses: the modal holds a draft and only Apply
         * navigates, because five controls that each reload the page is five
         * reloads to describe one question.
         */
        const filtering = ref(false);
        const applyFilters = (values) => {
            Object.assign(trail, values);
            filtering.value = false;
            load();
        };

        /*
         * What the button's badge counts: anything narrowed away from the
         * default, with two deliberate exclusions. Search has its own box in
         * plain sight, and the version is the tab bar — a badge counting a
         * choice the reader can see spelled out above the table would be
         * telling them the trail is narrowed by something hidden.
         */
        const activeFilters = computed(() => [
            trail.action, trail.office, trail.from, trail.to,
        ].filter(Boolean).length + (Number(trail.entries) !== 12 ? 1 : 0));

        /*
         * Switching version is the version filter, set from the tabs instead
         * of from the dialog. Page one, because the page in the address bar
         * belongs to the trail that was on screen: version 2's page three is
         * very often past the end of version 1.
         */
        const selectVersion = (version) => {
            trail.version = version;
            router.get(window.location.pathname, { ...trail, page: 1 }, {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            });
        };

        /*
         * A heading whenever the version changes, and on the first row of
         * every page so a page boundary cannot hide one.
         *
         * Grouped on the client because the rows already arrive ordered
         * newest-first and each carries its own version: grouping on the
         * server would mean a second ordering to keep in step with the
         * paginator's. It degrades to nothing when every row is null, which is
         * every trail written before versioning.
         */
        const startsVersion = (log, index) => {
            if (!log.version || props.versions.length < 2 || trail.version) return false;

            return index === 0 || props.logs.data[index - 1]?.version !== log.version;
        };

        const inspecting = ref(null);

        return { activeFilters, applyFilters, filtering, formatTimestamp, page, selectVersion, startsVersion, trail, load, search, inspecting };
    },
    template: `
    <Head :title="'History — ' + document.title" />
    <AdminShell active="documents" :title="'History — ' + document.title">
        <Card>
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-5">
                <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#07559e]">Audit trail</p>
                    <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">Tracking history</h2>
                    <p class="mt-2 font-mono text-sm text-slate-600">{{ document.code }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <Link :href="'/documents/view/' + document.code" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 hover:border-[#07559e] hover:text-[#07559e]"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back</Link>
                </div>
            </div>

            <!-- Search in plain sight, everything else behind the button:
                 the same division of labour as My Files. -->
            <div class="mt-5 grid gap-3 sm:grid-cols-[1fr_auto]">
                <label class="block">
                    <span class="sr-only">Search the trail</span>
                    <input v-model="trail.search" class="field !mt-0" placeholder="Search by person, employee ID or remarks" @input="search" />
                </label>
                <button type="button" class="relative inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="filtering = true">
                    <i class="fa-solid fa-filter" aria-hidden="true"></i>Filter
                    <span v-if="activeFilters" class="ml-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-[#00aeef] px-1.5 text-xs font-bold text-white">{{ activeFilters }}</span>
                </button>
            </div>

            <!-- The trail read version by version. Rendered only once a
                 file has been replaced: one version is not a choice. -->
            <TrailVersionTabs :tabs="versionTabs" :current="trail.version" @select="selectVersion" />

            <div class="mt-6">
                <table class="w-full text-left">
                    <thead class="border-y border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-4">When</th>
                            <th class="px-4 py-4">Action</th>
                            <th class="px-4 py-4">By</th>
                            <th class="px-4 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="(log, index) in logs.data" :key="log.id">
                        <tr v-if="startsVersion(log, index)" class="bg-slate-50">
                            <td colspan="4" class="px-4 py-2 text-xs font-bold uppercase tracking-wide text-slate-500">
                                <i class="fa-solid fa-clock-rotate-left mr-1.5" aria-hidden="true"></i>File version {{ log.version }}
                            </td>
                        </tr>
                        <tr class="border-b border-slate-100 hover:bg-sky-50/50">
                            <td class="px-4 py-4 text-slate-600">{{ formatTimestamp(log.occurred_at ?? log.created_at) }}</td>
                            <td class="px-4 py-4 font-bold text-slate-800">
                                <span class="inline-flex items-center gap-2">
                                    <span class="flex h-6 w-6 items-center justify-center rounded-full text-[11px]" :class="log.action_tone"><i :class="log.action_icon" aria-hidden="true"></i></span>
                                    {{ log.action_label }}
                                    <!-- Which bytes the entry was about. The
                                         headings above group the list, but a
                                         row read on its own — found by a
                                         search, or the one row a filter left
                                         — carried no answer, and "downloaded
                                         this document" is not a complete
                                         sentence once a file has a history. -->
                                    <span v-if="log.version" class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-bold text-slate-500">v{{ log.version }}</span>
                                </span>
                            </td>
                            <!-- The actor and their office are read off the row,
                                 not joined: each entry snapshots who they were
                                 at the time, so a later rename cannot rewrite
                                 where this document has been. -->
                            <td class="px-4 py-4 text-slate-600">
                                <span class="inline-flex items-center gap-2">
                                    <UserAvatar
                                        :person="{ name: log.actor_name, profile_image: log.actor_profile_image, avatar_seed: log.actor_avatar_seed }"
                                        class="!h-8 !w-8 !text-[10px]" />
                                    <span class="truncate">{{ log.actor_name }}</span>
                                </span>
                            </td>
                            <td class="px-4 py-4">
                                <RowActions
                                    :label="'Actions for ' + log.action_label"
                                    :actions="[{ key: 'view', label: 'View full entry', icon: 'fa-solid fa-eye', tone: 'primary' }]"
                                    @select="inspecting = log" />
                            </td>
                        </tr>
                        </template>
                        <tr v-if="logs.data.length === 0">
                            <td colspan="4" class="px-4 py-12 text-center text-slate-500">
                                <!-- The version is named rather than folded
                                     into "those filters": the tab is the
                                     thing the reader just clicked, so saying
                                     so is the difference between an answer
                                     and a dead end. -->
                                <span v-if="trail.version && !(trail.search || trail.action || trail.from || trail.to || trail.office)">Nothing was recorded against {{ trail.version === 'none' ? 'the unversioned file' : 'file version ' + trail.version }}.</span>
                                <span v-else-if="trail.search || trail.action || trail.from || trail.to || trail.office || trail.version">No entries match those filters.</span>
                                <span v-else>Nothing has been logged against this document yet.</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pagination :pagination="logs" @page="page" />
        </Card>

        <!--
            One entry in full — the office it happened in, the exact timestamp,
            the whole remark. These were columns; they are the same two values
            on nearly every row of a document's trail, so as columns they
            crowded out what differs and forced the table to scroll sideways.
        -->
        <ConfirmActionModal
            :open="Boolean(inspecting)"
            :title="inspecting ? inspecting.action_label : 'Trail entry'"
            :cancel-label="null"
            confirm-label="Close"
            :icon="inspecting ? inspecting.action_icon : 'fa-solid fa-clock-rotate-left'"
            wide
            @close="inspecting = null"
            @confirm="inspecting = null">
            <div v-if="inspecting" class="text-left">
                <div class="flex items-center gap-4 rounded-xl bg-slate-50 px-4 py-3">
                    <UserAvatar
                        :person="{ name: inspecting.actor_name, profile_image: inspecting.actor_profile_image, avatar_seed: inspecting.actor_avatar_seed }"
                        class="!h-14 !w-14 !text-sm" />
                    <span class="min-w-0">
                        <span class="block truncate font-bold text-slate-800">{{ inspecting.actor_name }}</span>
                        <span v-if="inspecting.actor_employee_id" class="block truncate text-sm text-slate-600">{{ inspecting.actor_employee_id }}</span>
                    </span>
                </div>

                <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">When</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">{{ formatTimestamp(inspecting.occurred_at ?? inspecting.created_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Action</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">{{ inspecting.action_label }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Division</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">{{ inspecting.division_name || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Section</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">{{ inspecting.section_name || '—' }}</dd>
                    </div>
                    <!-- A snapshot like every other field here: which bytes
                         the event concerned, not which are current. -->
                    <div v-if="inspecting.version">
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">File version</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">Version {{ inspecting.version }}</dd>
                    </div>
                </dl>

                <div class="mt-5">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Remarks</p>
                    <p v-if="inspecting.remarks" class="mt-2 whitespace-pre-line break-words rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-700">{{ inspecting.remarks }}</p>
                    <p v-else class="mt-2 text-sm text-slate-500">None recorded.</p>
                </div>
            </div>
        </ConfirmActionModal>
        <TrailFilters
            :open="filtering"
            :filters="trail"
            :actions="actions"
            :offices="offices"
            @close="filtering = false"
            @apply="applyFilters" />
    </AdminShell>
`,
});
