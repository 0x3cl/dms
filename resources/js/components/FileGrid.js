import { Link } from '@inertiajs/vue3';
import { defineComponent } from 'vue';
import ActionMenu from './ActionMenu';
import Badge from './Badge';
import FileIcon from './FileIcon';
import { accessBadge, readableSize, rowActions } from '../utils/file-rows';

/**
 * The thumbnail view.
 *
 * Renders exactly the same `files` paginator page and `folders` collection the
 * table view does, which is the whole of "pagination works consistently and
 * evenly in both views" — the counts cannot differ because there is one query.
 *
 * Per-row actions come from the server as a `can` object on each item, not from
 * reading `auth.permissions` in the template: a module permission says the
 * capability exists for this user, and only the resolver knows whether it
 * applies to *this* item.
 */
export default defineComponent({
    name: 'FileGrid',
    components: { ActionMenu, Badge, FileIcon, Link },
    emits: ['open', 'preview', 'act', 'prefetch'],
    props: {
        folders: { type: Array, default: () => [] },
        files: { type: Array, default: () => [] },
        folderHref: { type: Function, required: true },
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

        const isSelected = (publicId) => props.selected.includes(publicId);
        const act = (item, kind, action) => emit('act', { item, kind, action: action.key });

        return { accessBadge, act, actionsFor, isSelected, readableSize };
    },
    template: `
    <div class="mt-6">
        <div v-if="folders.length === 0 && files.length === 0" class="rounded-2xl border border-dashed border-slate-300 px-6 py-16 text-center">
            <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-slate-100 text-xl text-slate-400" aria-hidden="true"><i class="fa-solid fa-folder-open"></i></span>
            <p class="mt-4 font-bold uppercase tracking-wide text-slate-700">Nothing here</p>
            <p class="mt-1 text-sm text-slate-500">Upload a file or create a folder to get started.</p>
        </div>

        <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            <!--
                A folder card and a file card are the same shape.

                They were not: the file card put a checkbox in the header and a
                7rem thumbnail below it, while the folder card put its icon
                where the checkbox goes and had no media block at all. So a
                folder holding both rendered a ragged row — folder cards ~7rem
                shorter, their controls on a different line from every file's —
                and folders carried no select affordance even though the grid
                was told it was selectable. One skeleton: select, menu, media,
                name, meta, badges.
            -->
            <article v-for="folder in folders" :key="'folder-' + folder.public_id" class="group relative flex flex-col gap-3 rounded-2xl border bg-white p-4 shadow-sm transition" :class="isSelected(folder.public_id) ? 'border-[#00aeef] ring-4 ring-sky-100' : 'border-slate-200 hover:border-sky-300'">
                <div class="flex items-start justify-between gap-2">
                    <label v-if="selectable" class="inline-flex items-center">
                        <span class="sr-only">Select {{ folder.name }}</span>
                        <input type="checkbox" class="h-4 w-4 rounded border-slate-300 accent-[#07559e]" :checked="isSelected(folder.public_id)" @change="$emit('open', { item: folder, kind: 'folder', action: 'select' })" />
                    </label>
                    <span v-else></span>
                    <ActionMenu v-if="actionsFor(folder, 'folder').length" :items="actionsFor(folder, 'folder')" @select="act(folder, 'folder', $event)" />
                </div>

                <!-- The media block, at the file card's height so a mixed row
                     lines up. A folder has no thumbnail, so it is its glyph. -->
                <Link :href="folderHref(folder.public_id)" class="flex min-h-[7rem] items-center justify-center overflow-hidden rounded-xl bg-slate-50 text-4xl text-amber-500">
                    <i class="fa-solid fa-folder" aria-hidden="true"></i>
                </Link>

                <Link :href="folderHref(folder.public_id)" class="min-w-0 text-left">
                    <p class="truncate font-bold text-slate-800 group-hover:text-[#07559e]">{{ folder.name }}</p>
                    <p v-if="mode === 'trash'" class="mt-1 truncate text-xs text-slate-500">{{ folder.contains.folders }} folders · {{ folder.contains.files }} files</p>
                    <p v-else class="mt-1 truncate text-xs text-slate-500">Folder<span v-if="!folder.is_mine"> · {{ folder.owner_name }}</span></p>
                </Link>
                <div class="flex flex-wrap items-center gap-2">
                    <Badge v-if="mode !== 'trash'" :tone="folder.visibility_tone">{{ folder.visibility_label }}</Badge>
                    <Badge v-if="accessBadge(folder)" :tone="accessBadge(folder).tone" :title="accessBadge(folder).hint">
                        <i :class="accessBadge(folder).icon + ' mr-1.5'" aria-hidden="true"></i>{{ accessBadge(folder).label }}
                    </Badge>
                </div>
            </article>

            <article v-for="(file, fileIndex) in files" :data-tour="fileIndex === 0 ? 'file-row' : null" :key="'file-' + file.public_id" class="group relative flex flex-col gap-3 rounded-2xl border bg-white p-4 shadow-sm transition" :class="isSelected(file.public_id) ? 'border-[#00aeef] ring-4 ring-sky-100' : 'border-slate-200 hover:border-sky-300'">
                <div class="flex items-start justify-between gap-2">
                    <label v-if="selectable" class="inline-flex items-center">
                        <span class="sr-only">Select {{ file.display_name || file.name }}</span>
                        <input type="checkbox" class="h-4 w-4 rounded border-slate-300 accent-[#07559e]" :checked="isSelected(file.public_id)" @change="$emit('open', { item: file, kind: 'file', action: 'select' })" />
                    </label>
                    <span v-else></span>
                    <ActionMenu v-if="actionsFor(file, 'file').length" :items="actionsFor(file, 'file')" @select="act(file, 'file', $event)" />
                </div>

                <button type="button" class="flex min-h-[7rem] items-center justify-center overflow-hidden rounded-xl bg-slate-50" :disabled="mode === 'trash'" @click="$emit('preview', file)" @mouseenter="$emit('prefetch', file)" @focus="$emit('prefetch', file)">
                    <!-- The version is in the URL for the reason the preview's is: a
                         thumbnail response is cacheable and this URL is stable
                         across a replacement, so without it a replaced file
                         keeps showing the picture of what it used to be. -->
                    <img v-if="file.has_thumbnail" :src="'/files/' + file.owner_public_id + '/' + file.public_id + '?variant=thumb&version=' + (file.version ?? 1)" :alt="file.name" class="h-28 w-full object-cover" loading="lazy" />
                    <FileIcon v-else :mime-type="file.mime_type" />
                </button>

                <button type="button" class="min-w-0 text-left" @click="$emit('preview', file)">
                    <p class="truncate font-bold text-slate-800 group-hover:text-[#07559e]">{{ file.display_name || file.name }}</p>
                    <p class="mt-1 truncate text-xs text-slate-500">{{ readableSize(file.size) }}<span v-if="!file.is_mine"> · {{ file.owner_name }}</span></p>
                </button>
                <div class="flex flex-wrap items-center gap-2">
                    <!-- A tracked file is a document. Linking to it is the
                         whole point: it used to sit here looking like any
                         other file, with no way to reach its trail. -->
                    <Link v-if="file.tracked" :href="file.tracked.url" @click.stop>
                        <Badge tone="sky" :title="'Tracked as ' + file.tracked.code">
                            <i class="fa-solid fa-qrcode mr-1.5" aria-hidden="true"></i>{{ file.tracked.code }}
                        </Badge>
                    </Link>
                    <Badge v-if="mode !== 'trash'" :tone="file.visibility_tone">{{ file.visibility_label }}</Badge>
                    <Badge v-if="accessBadge(file)" :tone="accessBadge(file).tone" :title="accessBadge(file).hint">
                        <i :class="accessBadge(file).icon + ' mr-1.5'" aria-hidden="true"></i>{{ accessBadge(file).label }}
                    </Badge>
                </div>
            </article>
        </div>
    </div>
`,
});
