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
        /** Withdrawn documents, in `trash` mode only — see FileTable. */
        documents: { type: Array, default: () => [] },
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
        <!-- The trash's empty state is its own sentence. "Upload a file or
             create a folder to get started" is advice about My Files, and
             reading it in the trash tells somebody they are on the wrong
             screen when they are on the right one. -->
        <div v-if="folders.length === 0 && files.length === 0 && documents.length === 0" class="rounded-2xl border border-dashed border-slate-300 px-6 py-16 text-center">
            <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-slate-100 text-xl text-slate-400" aria-hidden="true"><i :class="mode === 'trash' ? 'fa-solid fa-trash-can' : 'fa-solid fa-folder-open'"></i></span>
            <p class="mt-4 font-bold uppercase tracking-wide text-slate-700">{{ mode === 'trash' ? 'Trash is empty' : 'Nothing here' }}</p>
            <p class="mt-1 text-sm text-slate-500">{{ mode === 'trash' ? 'Files, folders and documents you delete wait here until you restore them.' : 'Upload a file or create a folder to get started.' }}</p>
        </div>

        <div v-else class="grid grid-cols-1 items-stretch gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            <!--
                A folder card, a file card and a withdrawn document card are
                one skeleton: media, name, meta, badges — in that order, at
                the same heights, in a card that fills its grid cell.

                They were not, and a mixed row came out ragged in two separate
                ways. The select/menu row sat *in* the flow, so a card whose
                item offered no actions — somebody else's file, read-only —
                rendered that row at zero height and started its thumbnail
                nine pixels above its neighbour's; and the badge row sat
                directly under a name of unpredictable length, so one card
                wrapping its badges to a second line pushed nothing while the
                card beside it left a gap.

                So the controls are *overlaid* on the media block, where their
                presence cannot move anything below them, and the badge row is
                pinned to the bottom with \`mt-auto\` on a card stretched to the
                row's height. Every card in a row now agrees on where its
                thumbnail starts, where its name sits and where its badges end,
                whatever each one happens to hold.
            -->
            <article v-for="folder in folders" :key="'folder-' + folder.public_id" class="group relative flex h-full flex-col overflow-hidden rounded-2xl border bg-white shadow-sm transition" :class="isSelected(folder.public_id) ? 'border-[#00aeef] ring-4 ring-sky-100' : 'border-slate-200 hover:border-sky-300 hover:shadow-md'">
                <div class="relative">
                    <Link :href="folderHref(folder.public_id)" class="flex h-32 items-center justify-center overflow-hidden bg-slate-50 text-4xl text-amber-500">
                        <i class="fa-solid fa-folder" aria-hidden="true"></i>
                    </Link>
                    <label v-if="selectable" class="absolute left-3 top-3 inline-flex items-center rounded-md bg-white/90 p-1.5 shadow-sm ring-1 ring-slate-200 backdrop-blur-sm">
                        <span class="sr-only">Select {{ folder.name }}</span>
                        <input type="checkbox" class="h-4 w-4 rounded border-slate-300 accent-[#07559e]" :checked="isSelected(folder.public_id)" @change="$emit('open', { item: folder, kind: 'folder', action: 'select' })" />
                    </label>
                    <div class="absolute right-3 top-3">
                        <ActionMenu v-if="actionsFor(folder, 'folder').length" :items="actionsFor(folder, 'folder')" @select="act(folder, 'folder', $event)" />
                    </div>
                </div>

                <div class="flex flex-1 flex-col gap-3 p-4">
                    <Link :href="folderHref(folder.public_id)" class="min-w-0 text-left">
                        <p class="truncate font-bold text-slate-800 group-hover:text-[#07559e]" :title="folder.name">{{ folder.name }}</p>
                        <p v-if="mode === 'trash'" class="mt-1 truncate text-xs text-slate-500">{{ folder.contains.folders }} folders · {{ folder.contains.files }} files</p>
                        <p v-else class="mt-1 truncate text-xs text-slate-500">Folder<span v-if="!folder.is_mine"> · {{ folder.owner_name }}</span></p>
                    </Link>
                    <div class="mt-auto flex min-h-7 flex-wrap items-center gap-1.5">
                        <Badge v-if="mode !== 'trash'" :tone="folder.visibility_tone">{{ folder.visibility_label }}</Badge>
                        <!-- Back in My Files already, and in the trash only
                             because part of what it held is not. -->
                        <Badge v-if="folder.partly_restored" tone="amber" title="This folder has been restored. What is listed inside it is still deleted.">Restored</Badge>
                        <Badge v-if="accessBadge(folder)" :tone="accessBadge(folder).tone" :title="accessBadge(folder).hint">
                            <i :class="accessBadge(folder).icon + ' mr-1.5'" aria-hidden="true"></i>{{ accessBadge(folder).label }}
                        </Badge>
                    </div>
                </div>
            </article>

            <article v-for="(file, fileIndex) in files" :data-tour="fileIndex === 0 ? 'file-row' : null" :key="'file-' + file.public_id" class="group relative flex h-full flex-col overflow-hidden rounded-2xl border bg-white shadow-sm transition" :class="isSelected(file.public_id) ? 'border-[#00aeef] ring-4 ring-sky-100' : 'border-slate-200 hover:border-sky-300 hover:shadow-md'">
                <div class="relative">
                    <button type="button" class="flex h-32 w-full items-center justify-center overflow-hidden bg-slate-50" :disabled="mode === 'trash'" @click="$emit('preview', file)" @mouseenter="$emit('prefetch', file)" @focus="$emit('prefetch', file)">
                        <!-- The version is in the URL for the reason the preview's is: a
                             thumbnail response is cacheable and this URL is stable
                             across a replacement, so without it a replaced file
                             keeps showing the picture of what it used to be. -->
                        <img v-if="file.has_thumbnail" :src="'/files/' + file.owner_public_id + '/' + file.public_id + '?variant=thumb&version=' + (file.version ?? 1)" :alt="file.name" class="h-32 w-full object-cover" loading="lazy" />
                        <FileIcon v-else :mime-type="file.mime_type" />
                    </button>
                    <label v-if="selectable" class="absolute left-3 top-3 inline-flex items-center rounded-md bg-white/90 p-1.5 shadow-sm ring-1 ring-slate-200 backdrop-blur-sm">
                        <span class="sr-only">Select {{ file.display_name || file.name }}</span>
                        <input type="checkbox" class="h-4 w-4 rounded border-slate-300 accent-[#07559e]" :checked="isSelected(file.public_id)" @change="$emit('open', { item: file, kind: 'file', action: 'select' })" />
                    </label>
                    <div class="absolute right-3 top-3">
                        <ActionMenu v-if="actionsFor(file, 'file').length" :items="actionsFor(file, 'file')" @select="act(file, 'file', $event)" />
                    </div>
                </div>

                <div class="flex flex-1 flex-col gap-3 p-4">
                    <button type="button" class="min-w-0 text-left" @click="$emit('preview', file)">
                        <p class="truncate font-bold text-slate-800 group-hover:text-[#07559e]" :title="file.display_name || file.name">{{ file.display_name || file.name }}</p>
                        <p class="mt-1 truncate text-xs text-slate-500">{{ readableSize(file.size) }}<span v-if="!file.is_mine"> · {{ file.owner_name }}</span></p>
                    </button>
                    <div class="mt-auto flex min-h-7 flex-wrap items-center gap-1.5">
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
                </div>
            </article>

            <!--
                A withdrawn document, as a card of the same skeleton.

                It is in the grid rather than in a panel of its own because
                the trash is one question — "what did I delete" — and a second
                list on that screen reads as a second trash. What a document
                *is* differs from a file, which is what the card says: a
                tracking code instead of a size, and the note that its file
                was never deleted.
            -->
            <article v-for="document in documents" :key="'document-' + document.id" class="group relative flex h-full flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:border-sky-300 hover:shadow-md">
                <div class="relative">
                    <span class="flex h-32 items-center justify-center overflow-hidden bg-slate-50 text-4xl text-slate-400">
                        <i class="fa-solid fa-file-circle-xmark" aria-hidden="true"></i>
                    </span>
                    <div class="absolute right-3 top-3">
                        <ActionMenu
                            v-if="document.can_restore"
                            :items="[{ key: 'restore', label: 'Restore', icon: 'fa-solid fa-rotate-left', hint: 'Back onto the register under ' + document.code }]"
                            @select="$emit('act', { item: document, kind: 'document', action: 'restore' })" />
                    </div>
                </div>

                <div class="flex flex-1 flex-col gap-3 p-4">
                    <div class="min-w-0 text-left">
                        <p class="truncate font-bold text-slate-800" :title="document.title">{{ document.title }}</p>
                        <p class="mt-1 truncate font-mono text-xs text-slate-500">{{ document.code }}</p>
                    </div>
                    <div class="mt-auto flex min-h-7 flex-wrap items-center gap-1.5">
                        <Badge tone="slate">Withdrawn document</Badge>
                        <span class="text-xs text-slate-400">File kept</span>
                    </div>
                </div>
            </article>
        </div>
    </div>
`,
});
