import { computed, defineComponent, ref, watch } from 'vue';
import ConfirmActionModal from './ConfirmActionModal';
import useCloseOnTutorial from '../composables/useCloseOnTutorial';
import { csrfHeaders } from '../csrf';

/**
 * Where should this go?
 *
 * `confirm` carries the chosen folder's public id **and its name**. A caller
 * that acts immediately — Move, Copy — needs only the id, but one that shows
 * the choice back to somebody before they submit, like the upload form's
 * destination, cannot get a name out of an id it never fetched the tree for.
 *
 * Move and Copy both used to send the folder the person was already looking at
 * as the destination, which made Move a no-op and Copy a one-way trip to the
 * root. There was nowhere to say "that one" — so this is that.
 *
 * The tree is fetched once, flat, from `/files/destinations`, and walked here:
 * one request rather than one per level, and the server has already narrowed it
 * to folders this person may actually write into. A picker that offered a
 * folder the move would then be refused from is worse than no picker.
 *
 * The item being moved is excluded along with everything inside it — a folder
 * cannot be moved into itself, and showing the option only to have the server
 * refuse it is a dialog that lies about what it can do.
 *
 * **A folder can be created from here.** "Put it in a new folder" is a thought
 * people have while choosing a destination, and sending them away to make one
 * first loses the selection they arrived with. It posts to the same
 * `files.folders.store` endpoint the New folder dialog uses — asking for JSON,
 * because a redirect would close this dialog — so the ability check and the
 * naming rules have one implementation rather than two. The new folder is then
 * entered, because somebody who just named a destination has chosen it.
 */
export default defineComponent({
    name: 'FolderPickerModal',
    components: { ConfirmActionModal },
    emits: ['close', 'confirm'],
    props: {
        open: { type: Boolean, default: false },
        /** 'move', 'copy' or 'upload' — wording only; the endpoint is the caller's. */
        action: { type: String, default: 'move' },
        /** The rows being moved or copied: [{ kind, public_id, name }]. */
        items: { type: Array, default: () => [] },
        processing: { type: Boolean, default: false },
        /**
         * Whether to offer "New folder in …".
         *
         * The endpoint behind it is gated on `files.create`, so a caller
         * whose person does not hold that grant passes false rather than
         * showing a control that answers 403.
         */
        canCreate: { type: Boolean, default: true },
        error: { type: String, default: '' },
    },
    setup(props, { emit }) {
        const root = ref(null);
        const folders = ref([]);
        const loading = ref(false);
        const failed = ref('');

        // Where the picker is currently looking, as a stack of folder rows. An
        // empty stack is the top of the person's own storage.
        const trail = ref([]);
        const current = computed(() => trail.value[trail.value.length - 1] ?? null);
        const selected = ref(null);

        const load = async () => {
            loading.value = true;
            failed.value = '';

            try {
                const response = await fetch('/files/destinations', {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                if (!response.ok) throw new Error(String(response.status));

                const payload = await response.json();
                root.value = payload.root;
                folders.value = payload.folders;
            } catch {
                failed.value = 'The folder list could not be loaded. Close this and try again.';
            } finally {
                loading.value = false;
            }
        };

        watch(() => props.open, (open) => {
            if (!open) return;

            trail.value = [];
            selected.value = null;
            load();
        });

        useCloseOnTutorial(() => emit('close'));

        /** The ids of the folders being moved, so they cannot be their own target. */
        const excluded = computed(() => props.items
            .filter((item) => item.kind === 'folder')
            .map((item) => item.public_id));

        /**
         * A folder inside one of the folders being moved is excluded too.
         *
         * Walked from the flat list rather than asked of the server: the answer
         * is already here, and a request per expansion would make the dialog
         * feel like a page load.
         */
        const isInsideExcluded = (publicId) => {
            let cursor = publicId;
            const parents = new Map(folders.value.map((folder) => [folder.public_id, folder.parent_public_id]));

            while (cursor) {
                if (excluded.value.includes(cursor)) return true;
                cursor = parents.get(cursor) ?? null;
            }

            return false;
        };

        const children = computed(() => folders.value
            .filter((folder) => (folder.parent_public_id ?? null) === (current.value?.public_id ?? null))
            .filter((folder) => !isInsideExcluded(folder.public_id)));

        // Named `enter`, not `open`: a setup binding called `open` would
        // shadow the `open` prop in the template, and the modal would be handed
        // a function where it expects a boolean.
        const enter = (folder) => {
            trail.value = [...trail.value, folder];
            selected.value = folder;
        };
        const up = (index) => {
            trail.value = trail.value.slice(0, index);
            selected.value = trail.value[trail.value.length - 1] ?? null;
        };

        // ------------------------------------------------------- new folder
        const creating = ref(false);
        const newName = ref('');
        const createError = ref('');
        const saving = ref(false);



        const startCreating = () => {
            creating.value = true;
            newName.value = '';
            createError.value = '';
        };

        const createFolder = async () => {
            const name = newName.value.trim();

            if (name === '' || saving.value) return;

            saving.value = true;
            createError.value = '';

            try {
                const response = await fetch('/files/folders', {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        // See resources/js/csrf.js: the meta tag is rendered
                        // once per full page load and goes stale across a
                        // session regeneration, which is the `CSRF token
                        // mismatch` this dialog used to meet.
                        ...csrfHeaders(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        // The folder currently being looked at, or the storage
                        // root when the picker is at the top.
                        parent: current.value?.public_id ?? root.value?.public_id,
                        name,
                    }),
                });

                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    // 422 carries Laravel's bag; anything else is unexplained.
                    createError.value = payload?.errors?.name?.[0]
                        ?? payload?.message
                        ?? 'That folder could not be created.';

                    return;
                }

                folders.value = [...folders.value, payload.folder];
                creating.value = false;
                newName.value = '';

                // Entered, not merely listed: naming a destination is choosing it.
                enter(payload.folder);
            } catch {
                createError.value = 'That folder could not be created.';
            } finally {
                saving.value = false;
            }
        };

        const target = computed(() => selected.value?.public_id ?? root.value?.public_id ?? null);
        const targetLabel = computed(() => selected.value?.name ?? root.value?.name ?? 'My Files');

        const title = computed(() => ({
            copy: 'Copy to…',
            upload: 'Save to…',
            move: 'Move to…',
        })[props.action] ?? 'Move to…');
        const confirmLabel = computed(() => ({
            copy: 'Copy here',
            upload: 'Save here',
            move: 'Move here',
        })[props.action] ?? 'Move here');
        const summary = computed(() => {
            if (props.items.length === 1) return props.items[0].name;

            return `${props.items.length} items`;
        });

        return {
            children, confirmLabel, createError, createFolder, creating, current,
            enter, failed, loading, newName, root, saving, selected, startCreating,
            summary, target, targetLabel, title, trail, up,
        };
    },
    template: `
    <ConfirmActionModal
        :open="open"
        :processing="processing"
        :title="title"
        :message="summary + ' will be ' + ({ copy: 'copied', upload: 'saved', move: 'moved' }[action] ?? 'moved') + ' into the folder you choose.'"
        :confirm-label="confirmLabel"
        :confirm-disabled="target === null"
        icon="fa-solid fa-folder-tree"
        wider
        @close="$emit('close')"
        @confirm="$emit('confirm', target, targetLabel)">
        <div class="text-left">
            <!-- Where the picker is looking. The first crumb is always the top
                 of the person's own storage, which is a real destination. -->
            <nav class="flex flex-wrap items-center gap-1 text-sm" aria-label="Destination">
                <button type="button" class="inline-flex items-center gap-2 rounded-lg px-2 py-1 font-bold text-[#07559e] hover:bg-sky-100" @click="up(0)">
                    <i class="fa-solid fa-hard-drive" aria-hidden="true"></i>{{ root?.name ?? 'My Files' }}
                </button>
                <template v-for="(crumb, index) in trail" :key="crumb.public_id">
                    <i class="fa-solid fa-chevron-right text-xs text-slate-400" aria-hidden="true"></i>
                    <button type="button" class="rounded-lg px-2 py-1 font-semibold text-[#07559e] hover:bg-sky-100" @click="up(index + 1)">{{ crumb.name }}</button>
                </template>
            </nav>

            <div class="mt-3 max-h-72 overflow-y-auto rounded-xl border border-slate-200">
                <p v-if="loading" class="px-4 py-8 text-center text-sm text-slate-500">Loading folders…</p>
                <p v-else-if="failed" class="px-4 py-8 text-center text-sm font-semibold text-rose-600">{{ failed }}</p>
                <p v-else-if="children.length === 0" class="px-4 py-8 text-center text-sm text-slate-500">No subfolders here. Choosing this folder puts it straight in, or make a new one below.</p>
                <button
                    v-for="folder in children"
                    :key="folder.public_id"
                    type="button"
                    class="flex w-full items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 text-left transition last:border-0 hover:bg-sky-50"
                    @click="enter(folder)">
                    <span class="flex min-w-0 items-center gap-3">
                        <i class="fa-solid fa-folder text-amber-500" aria-hidden="true"></i>
                        <span class="min-w-0">
                            <span class="block truncate font-semibold text-slate-800">{{ folder.name }}</span>
                            <span v-if="!folder.is_mine" class="block truncate text-xs text-slate-500">Shared by {{ folder.owner_name }}</span>
                        </span>
                    </span>
                    <i class="fa-solid fa-chevron-right text-xs text-slate-400" aria-hidden="true"></i>
                </button>
            </div>

            <!-- Create a destination without leaving the dialog. Collapsed to
                 a single affordance until it is wanted, so the common case —
                 choosing somewhere that already exists — stays uncluttered. -->
            <div class="mt-3">
                <button
                    v-if="canCreate && !creating"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm font-bold text-[#07559e] transition hover:bg-sky-50"
                    @click="startCreating">
                    <i class="fa-solid fa-folder-plus" aria-hidden="true"></i>New folder in {{ targetLabel }}
                </button>
                <div v-else-if="canCreate" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">New folder in {{ targetLabel }}</span>
                    <div class="mt-2 flex flex-wrap items-start gap-2">
                        <label class="min-w-0 flex-1">
                            <span class="sr-only">Folder name</span>
                            <input
                                v-model="newName"
                                class="field !mt-0"
                                :class="{ 'border-rose-500': createError }"
                                placeholder="Folder name"
                                maxlength="255"
                                @keydown.enter.prevent="createFolder"
                                @keydown.esc.prevent="creating = false" />
                        </label>
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8] disabled:opacity-60"
                            :disabled="saving || newName.trim() === ''"
                            @click="createFolder">
                            <i class="fa-solid fa-check" aria-hidden="true"></i>{{ saving ? 'Creating…' : 'Create' }}
                        </button>
                        <button type="button" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="creating = false">Cancel</button>
                    </div>
                    <p v-if="createError" class="error">{{ createError }}</p>
                </div>
            </div>

            <p class="mt-3 rounded-xl bg-sky-50 px-4 py-3 text-sm font-semibold text-[#07559e]">
                Destination: {{ targetLabel }}
            </p>
            <p v-if="error" class="error">{{ error }}</p>
        </div>
    </ConfirmActionModal>
`,
});
