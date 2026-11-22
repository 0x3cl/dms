import { Head, Link, router } from '@inertiajs/vue3';
import { defineComponent, ref } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import RowActions from '../../components/RowActions';

/**
 * The groups a document type can sit in — the module's landing.
 *
 * It used to be a flat table of every type, which answered the wrong
 * question first: an office looking at twenty rows wants to know what *kinds*
 * of thing it files before which exact type. So this is the shelf, and a
 * group is opened to see what is on it.
 *
 * **"Other" is a row here, not an omission.** Types with no group are real
 * and have to be reachable — `group_id` is `nullOnDelete`, so deleting a
 * group produces some. A set that exists and cannot be opened is how rows go
 * missing from a screen that looks complete.
 */
export default defineComponent({
    name: 'DocumentTypesIndex',
    components: { AdminShell, Card, ConfirmActionModal, Head, Link, RowActions },
    props: {
        groups: { type: Array, required: true },
        /** The types with no group, as a set of their own. */
        ungrouped: { type: Object, required: true },
        totals: { type: Object, required: true },
    },
    setup() {
        const deleting = ref(null);
        const processing = ref(false);

        const confirmDelete = () => {
            processing.value = true;
            router.delete(`/document-type-groups/${deleting.value.id}`, {
                preserveScroll: true,
                onFinish: () => { processing.value = false; deleting.value = null; },
            });
        };

        return { confirmDelete, deleting, processing };
    },
    template: `
    <Head title="Document Types" />
    <AdminShell active="document-types" title="Document Types">
        <Card>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-2xl">
                    <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Settings</p>
                    <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">Kinds of document</h2>
                    <p class="mt-1 text-slate-600">
                        Types are arranged in groups, which is how the upload form offers them — the group is the heading,
                        the type is the choice, and the type's abbreviation is what starts a tracking code:
                        <span class="font-mono text-sm">MEMO</span> in <span class="font-mono text-sm">MEMO-20260110-0001</span>.
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-3">
                    <Link href="/document-types/group/all" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                        <i class="fa-solid fa-list" aria-hidden="true"></i>All types<span class="text-slate-400">{{ totals.types }}</span>
                    </Link>
                    <Link href="/document-type-groups/create" class="inline-flex items-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8]">
                        <i class="fa-solid fa-plus" aria-hidden="true"></i>Add group
                    </Link>
                </div>
            </div>

            <div class="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <!-- A card rather than a row: a group is a place you open, and
                     the two numbers on it are the ones somebody checks before
                     opening or deleting one. -->
                <div v-for="group in groups" :key="group.id" class="flex flex-col rounded-2xl border border-slate-200 p-5 transition hover:border-sky-300">
                    <div class="flex items-start justify-between gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-sky-100 text-[#07559e]" aria-hidden="true">
                            <i class="fa-solid fa-folder-tree"></i>
                        </span>
                        <RowActions
                            :label="'Actions for ' + group.name"
                            :actions="[
                                { key: 'edit', label: 'Edit group', icon: 'fa-solid fa-pen', tone: 'primary', href: '/document-type-groups/' + group.id + '/edit' },
                                { key: 'delete', label: 'Delete group', icon: 'fa-solid fa-trash', tone: 'danger' },
                            ]"
                            @select="deleting = group" />
                    </div>

                    <h3 class="mt-4 text-lg font-bold uppercase tracking-wide text-slate-900">{{ group.name }}</h3>
                    <p v-if="group.description" class="mt-1 text-sm text-slate-600">{{ group.description }}</p>

                    <p class="mt-3 text-sm text-slate-500">
                        <span class="font-bold text-slate-700">{{ group.active_types_count }}</span> offered on the upload form
                        <span v-if="group.types_count !== group.active_types_count"> · {{ group.types_count }} in all</span>
                    </p>

                    <div class="mt-5 flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                        <Link :href="'/document-types/group/' + group.id" class="inline-flex items-center gap-2 rounded-lg bg-[#00aeef] px-3.5 py-2 text-sm font-bold text-white transition hover:bg-[#009bd8]">
                            <i class="fa-solid fa-eye" aria-hidden="true"></i>View types
                        </Link>
                        <Link :href="'/document-types/create?group=' + group.id" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3.5 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                            <i class="fa-solid fa-plus" aria-hidden="true"></i>Add type
                        </Link>
                    </div>
                </div>

                <!-- Reachable, always, because types land here whenever a
                     group is deleted — group_id nulls rather than cascading. -->
                <div v-if="ungrouped.types_count" class="flex flex-col rounded-2xl border border-dashed border-slate-300 p-5">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-500" aria-hidden="true">
                        <i class="fa-solid fa-folder-open"></i>
                    </span>
                    <h3 class="mt-4 text-lg font-bold uppercase tracking-wide text-slate-900">Other</h3>
                    <p class="mt-1 text-sm text-slate-600">Types with no group. The upload form offers them last, under this heading.</p>
                    <p class="mt-3 text-sm text-slate-500">
                        <span class="font-bold text-slate-700">{{ ungrouped.active_types_count }}</span> offered on the upload form
                        <span v-if="ungrouped.types_count !== ungrouped.active_types_count"> · {{ ungrouped.types_count }} in all</span>
                    </p>
                    <div class="mt-5 border-t border-slate-100 pt-4">
                        <Link href="/document-types/group/other" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3.5 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                            <i class="fa-solid fa-eye" aria-hidden="true"></i>View types
                        </Link>
                    </div>
                </div>

                <p v-if="groups.length === 0 && !ungrouped.types_count" class="rounded-2xl border border-dashed border-slate-300 p-10 text-center text-slate-500 sm:col-span-2 xl:col-span-3">
                    No groups yet. Add one, then add the types that belong in it.
                </p>
            </div>

            <div class="mt-7 rounded-xl bg-sky-50 p-4 text-sm text-slate-700">
                <p><strong class="font-bold">A group is how the list is arranged.</strong> It gates nothing and never appears in a tracking code, so renaming or deleting one is safe — deleting moves its types to Other rather than retiring them.</p>
                <p class="mt-2"><strong class="font-bold">A type is part of the record.</strong> Its abbreviation is stamped into every tracking code it has issued, and those are printed on folders — which is why the abbreviation locks once the first code goes out.</p>
            </div>
        </Card>

        <ConfirmActionModal
            :open="deleting !== null"
            :processing="processing"
            title="Delete this group?"
            :message="deleting?.types_count
                ? 'The ' + deleting.types_count + ' type(s) under ' + deleting.name + ' are not retired — they move to Other and keep working. A group is only how the list is arranged.'
                : 'Nothing is filed under ' + (deleting?.name ?? '') + '.'"
            confirm-label="Delete group"
            tone="danger"
            icon="fa-solid fa-trash"
            @close="deleting = null"
            @confirm="confirmDelete" />
    </AdminShell>
`,
});
