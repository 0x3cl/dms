import { Link } from '@inertiajs/vue3';
import { defineComponent } from 'vue';
import ActionMenu from './ActionMenu';
import Badge from './Badge';
import FileIcon from './FileIcon';
import { formatTimestamp } from '../utils/format-date';
import { accessBadge, readableSize, rowActions } from '../utils/file-rows';

/**
 * The table view.
 *
 * Renders the same `files` paginator page and `folders` collection the grid
 * does — one query, so the two views cannot disagree about counts or contents.
 *
 * Sortable headers are new to this codebase: no controller read a `sort`
 * parameter before, and no table header was clickable. The column whitelist
 * lives server-side in FileManagerController.
 */
export default defineComponent({
    name: 'FileTable',
    components: { ActionMenu, Badge, FileIcon, Link },
    emits: ['open', 'preview', 'act', 'prefetch', 'sort'],
    props: {
        folders: { type: Array, default: () => [] },
        files: { type: Array, default: () => [] },
        /**
         * Withdrawn documents, in `trash` mode only.
         *
         * A third kind of row rather than a second list. They were a panel of
         * their own above the table, which read as two trashes on one screen
         * — and left the table beneath them announcing "nothing here" while
         * the trash plainly held five things. What a row *is* differs, which
         * is what the columns are for; what a trash *is* does not.
         */
        documents: { type: Array, default: () => [] },
        folderHref: { type: Function, required: true },
        sort: { type: String, default: 'name' },
        direction: { type: String, default: 'asc' },
        selected: { type: Array, default: () => [] },
        selectable: { type: Boolean, default: true },
        /**
         * 'browse' or 'trash'. The trash renders the same two views over the
         * same payload shape and only the action list differs, which is what
         * gives the Trash page a grid and a table without a second listing
         * component to keep in step.
         */
        mode: { type: String, default: 'browse' },
    },
    setup(props, { emit }) {
        // Both views share one definition of these — see utils/file-rows.js for
        // why they are not written out here twice.
        const actionsFor = (item, kind) => rowActions(item, kind, props.mode);

        const headerIcon = (column) => {
            if (props.sort !== column) return 'fa-solid fa-sort text-slate-300';
            return props.direction === 'asc' ? 'fa-solid fa-sort-up' : 'fa-solid fa-sort-down';
        };

        const isSelected = (publicId) => props.selected.includes(publicId);
        const act = (item, kind, action) => emit('act', { item, kind, action: action.key });

        return { accessBadge, act, actionsFor, formatTimestamp, headerIcon, isSelected, readableSize };
    },
    template: `
    <div class="mt-6 overflow-x-auto">
        <table class="w-full min-w-[980px] text-left">
            <thead class="border-y border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th v-if="selectable" class="w-10 px-4 py-4"><span class="sr-only">Select</span></th>
                    <th class="px-4 py-4">
                        <button type="button" class="inline-flex items-center gap-2 uppercase tracking-wide" @click="$emit('sort', 'name')">Name <i :class="headerIcon('name')" aria-hidden="true"></i></button>
                    </th>
                    <th class="px-4 py-4">{{ mode === 'trash' ? 'Restores to' : 'Privacy' }}</th>
                    <th class="px-4 py-4">Owner</th>
                    <th class="px-4 py-4">
                        <button type="button" class="inline-flex items-center gap-2 uppercase tracking-wide" @click="$emit('sort', 'size')">Size <i :class="headerIcon('size')" aria-hidden="true"></i></button>
                    </th>
                    <th class="px-4 py-4">
                        <button type="button" class="inline-flex items-center gap-2 uppercase tracking-wide" @click="$emit('sort', mode === 'trash' ? 'deleted' : 'updated')">{{ mode === 'trash' ? 'Deleted' : 'Modified' }} <i :class="headerIcon(mode === 'trash' ? 'deleted' : 'updated')" aria-hidden="true"></i></button>
                    </th>
                    <th class="px-4 py-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <!-- A folder row carries the same select control as a file row.
                     It had a blank cell, so a folder could not be part of a
                     bulk Move, Copy or Delete — which FileManager has always
                     accepted. -->
                <tr v-for="folder in folders" :key="'folder-' + folder.public_id" class="border-b border-slate-100 hover:bg-sky-50/50">
                    <td v-if="selectable" class="px-4 py-4">
                        <label class="inline-flex items-center">
                            <span class="sr-only">Select {{ folder.name }}</span>
                            <input type="checkbox" class="h-4 w-4 rounded border-slate-300 accent-[#07559e]" :checked="isSelected(folder.public_id)" @change="$emit('open', { item: folder, kind: 'folder', action: 'select' })" />
                        </label>
                    </td>
                    <td class="px-4 py-4">
                        <Link :href="folderHref(folder.public_id)" class="inline-flex items-center gap-3 font-bold text-slate-800 hover:text-[#07559e]">
                            <FileIcon folder size="sm" />{{ folder.name }}
                        </Link>
                    </td>
                    <td class="px-4 py-4">
                        <Badge v-if="folder.partly_restored" tone="amber" title="This folder has been restored. What is listed inside it is still deleted.">Restored</Badge>
                        <span v-else-if="mode === 'trash'" class="text-sm text-slate-600">{{ folder.restores_to }}</span>
                        <Badge v-else :tone="folder.visibility_tone">{{ folder.visibility_label }}</Badge>
                    </td>
                    <td class="px-4 py-4 text-slate-600">
                        {{ folder.is_mine ? 'Me' : folder.owner_name }}
                        <span v-if="accessBadge(folder)?.highlight" class="ml-1 text-xs text-emerald-700" :title="accessBadge(folder).hint"><i class="fa-solid fa-share-nodes" aria-hidden="true"></i></span>
                    </td>
                    <td class="px-4 py-4 text-slate-400">{{ mode === 'trash' ? folder.contains.files + ' files' : '—' }}</td>
                    <td class="px-4 py-4 text-slate-600">{{ formatTimestamp(mode === 'trash' ? folder.deleted_at : folder.updated_at) }}</td>
                    <td class="whitespace-nowrap px-4 py-4 text-right">
                        <ActionMenu v-if="actionsFor(folder, 'folder').length" :items="actionsFor(folder, 'folder')" @select="act(folder, 'folder', $event)" />
                    </td>
                </tr>
                <tr v-for="(file, fileIndex) in files" :data-tour="fileIndex === 0 ? 'file-row' : null" :key="'file-' + file.public_id" class="border-b border-slate-100 hover:bg-sky-50/50">
                    <td v-if="selectable" class="px-4 py-4">
                        <label class="inline-flex items-center">
                            <span class="sr-only">Select {{ file.display_name || file.name }}</span>
                            <input type="checkbox" class="h-4 w-4 rounded border-slate-300 accent-[#07559e]" :checked="isSelected(file.public_id)" @change="$emit('open', { item: file, kind: 'file', action: 'select' })" />
                        </label>
                    </td>
                    <!--
                        The icon is its own column, and the name and the
                        tracking code share the one beside it.

                        The code used to be a sibling of the whole button, so
                        it began at the cell's left edge - under the icon,
                        while the name it belongs to started three rems to the
                        right. Two lines about one file, neither lining up with
                        the other. Giving the icon a column of its own puts
                        both lines on the same left edge and lets a long name
                        truncate instead of pushing the row wide.
                    -->
                    <td class="px-4 py-4">
                        <div class="flex items-start gap-3">
                            <FileIcon :mime-type="file.mime_type" size="sm" class="mt-0.5 shrink-0" />
                            <div class="min-w-0">
                                <button type="button" class="block max-w-full truncate text-left font-bold text-slate-800 hover:text-[#07559e] disabled:text-slate-800 disabled:hover:text-slate-800" :disabled="mode === 'trash'" @click="$emit('preview', file)" @mouseenter="$emit('prefetch', file)" @focus="$emit('prefetch', file)">
                                    {{ file.display_name || file.name }}
                                </button>
                                <!-- A tracked file is a document; this is the way to
                                     its trail, which it previously had none of. -->
                                <Link v-if="file.tracked" :href="file.tracked.url" class="mt-1 inline-flex items-center gap-1.5 font-mono text-xs font-semibold text-[#07559e] hover:text-[#043d78]" :title="'Tracked as ' + file.tracked.code">
                                    <i class="fa-solid fa-qrcode" aria-hidden="true"></i>{{ file.tracked.code }}
                                </Link>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-4">
                        <span v-if="mode === 'trash'" class="text-sm text-slate-600">{{ file.restores_to }}</span>
                        <Badge v-else :tone="file.visibility_tone">{{ file.visibility_label }}</Badge>
                    </td>
                    <td class="px-4 py-4 text-slate-600">
                        {{ file.is_mine ? 'Me' : file.owner_name }}
                        <span v-if="accessBadge(file)?.highlight" class="ml-1 text-xs text-emerald-700" :title="accessBadge(file).hint"><i class="fa-solid fa-share-nodes" aria-hidden="true"></i></span>
                    </td>
                    <td class="px-4 py-4 text-slate-600">{{ readableSize(file.size) }}</td>
                    <td class="px-4 py-4 text-slate-600">{{ formatTimestamp(mode === 'trash' ? file.deleted_at : file.updated_at) }}</td>
                    <td class="whitespace-nowrap px-4 py-4 text-right">
                        <ActionMenu v-if="actionsFor(file, 'file').length" :items="actionsFor(file, 'file')" @select="act(file, 'file', $event)" />
                    </td>
                </tr>
                <!--
                    A withdrawn document: no folder, no size, and the only way
                    back is the register. The columns say so rather than being
                    left blank, because an empty cell reads as missing data.
                -->
                <tr v-for="document in documents" :key="'document-' + document.id" class="border-b border-slate-100 hover:bg-sky-50/50">
                    <td v-if="selectable" class="px-4 py-4"></td>
                    <td class="px-4 py-4">
                        <div class="flex items-start gap-3">
                            <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500">
                                <i class="fa-solid fa-file-circle-xmark" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0">
                                <p class="block max-w-[300px] truncate font-bold text-slate-800" :title="document.title">{{ document.title }}</p>
                                <p class="mt-1 font-mono text-xs font-semibold text-slate-500">{{ document.code }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-4"><span class="text-sm text-slate-600">The register</span></td>
                    <td class="px-4 py-4 text-slate-600">Me</td>
                    <!-- Withdrawing a document never deleted its file, and
                         this is where somebody deciding whether to restore it
                         needs to be told so. -->
                    <td class="px-4 py-4 text-xs text-slate-400">File kept</td>
                    <td class="px-4 py-4 text-slate-600">{{ formatTimestamp(document.deleted_at) }}</td>
                    <td class="whitespace-nowrap px-4 py-4 text-right">
                        <ActionMenu
                            v-if="document.can_restore"
                            :items="[{ key: 'restore', label: 'Restore', icon: 'fa-solid fa-rotate-left', hint: 'Back onto the register under ' + document.code }]"
                            @select="$emit('act', { item: document, kind: 'document', action: 'restore' })" />
                    </td>
                </tr>
                <tr v-if="folders.length === 0 && files.length === 0 && documents.length === 0">
                    <td :colspan="selectable ? 7 : 6" class="px-4 py-12 text-center text-slate-500">{{ mode === 'trash' ? 'Nothing in the trash.' : 'Nothing here yet.' }}</td>
                </tr>
            </tbody>
        </table>
    </div>
`,
});
