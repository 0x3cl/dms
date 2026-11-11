import { computed, defineComponent, ref, watch } from 'vue';
import ConfirmActionModal from './ConfirmActionModal';
import useCloseOnTutorial from '../composables/useCloseOnTutorial';

/**
 * Where should this go?
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
 */
export default defineComponent({
    name: 'FolderPickerModal',
    components: { ConfirmActionModal },
    emits: ['close', 'confirm'],
    props: {
        open: { type: Boolean, default: false },
        /** 'move' or 'copy' — wording only; the endpoint is the caller's. */
        action: { type: String, default: 'move' },
        /** The rows being moved or copied: [{ kind, public_id, name }]. */
        items: { type: Array, default: () => [] },
        processing: { type: Boolean, default: false },
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

        const target = computed(() => selected.value?.public_id ?? root.value?.public_id ?? null);
        const targetLabel = computed(() => selected.value?.name ?? root.value?.name ?? 'My Files');

        const title = computed(() => (props.action === 'copy' ? 'Copy to…' : 'Move to…'));
        const confirmLabel = computed(() => (props.action === 'copy'
            ? `Copy here`
            : `Move here`));
        const summary = computed(() => {
            if (props.items.length === 1) return props.items[0].name;

            return `${props.items.length} items`;
        });

        return {
            children, confirmLabel, current, enter, failed, loading, root, selected,
            summary, target, targetLabel, title, trail, up,
        };
    },
    template: `
    <ConfirmActionModal
        :open="open"
        :processing="processing"
        :title="title"
        :message="summary + ' will be ' + (action === 'copy' ? 'copied' : 'moved') + ' into the folder you choose.'"
        :confirm-label="confirmLabel"
        :confirm-disabled="target === null"
        icon="fa-solid fa-folder-tree"
        wider
        @close="$emit('close')"
        @confirm="$emit('confirm', target)">
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
                <p v-else-if="children.length === 0" class="px-4 py-8 text-center text-sm text-slate-500">No subfolders here. Choosing this folder puts it straight in.</p>
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

            <p class="mt-3 rounded-xl bg-sky-50 px-4 py-3 text-sm font-semibold text-[#07559e]">
                Destination: {{ targetLabel }}
            </p>
            <p v-if="error" class="error">{{ error }}</p>
        </div>
    </ConfirmActionModal>
`,
});
