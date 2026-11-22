import { computed, defineComponent, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import ConfirmActionModal from './ConfirmActionModal';
import FileIcon from './FileIcon';
import UserAvatar from './UserAvatar';
import { readableSize } from '../utils/file-rows';
import { formatTimestamp } from '../utils/format-date';

/**
 * What a file has been, and bringing one of those back.
 *
 * The history is **fetched when this opens**, never shipped with the listing,
 * for the reason the preview descriptor is: every entry carries URLs that open
 * bytes, and a folder of forty rows must not mint them for every row on every
 * navigation.
 *
 * Three things about the markup are not cosmetic.
 *
 * **View and Download are plain `<a href>`.** They are file responses, which
 * an Inertia XHR visit cannot follow — the same rule as every other download
 * in this application.
 *
 * **"Restore this version" is confirmed, not immediate.** It is not
 * destructive, since a revert copies the old bytes *forward* as a new version
 * and nothing is lost either way, but it changes what a tracking code names
 * and what the next person downloads, so it should not happen on one stray
 * click in a list.
 *
 * **Nothing here decides who may do what.** `can_download` and `can_revert`
 * arrive resolved, once, for the file — a version has no access rule of its
 * own, and asking per entry would read as though it might.
 */
export default defineComponent({
    name: 'FileVersionsModal',
    components: { ConfirmActionModal, FileIcon, UserAvatar },
    emits: ['close', 'reverted'],
    props: {
        open: { type: Boolean, default: false },
        /** `{ kind, public_id, owner_public_id, name, display_name, tracked }`, or null. */
        item: { type: Object, default: null },
    },
    setup(props, { emit }) {
        const loading = ref(false);
        const error = ref('');
        const history = ref(null);
        const reverting = ref(null);
        const processing = ref(false);

        /*
         * The document this file is tracked as, when it is one. Null for an
         * ordinary file in personal storage, which is what sends View back
         * to the raw bytes.
         */
        const documentUrl = computed(() => props.item?.tracked?.url ?? null);

        const load = async () => {
            if (!props.item) return;

            loading.value = true;
            error.value = '';

            try {
                const response = await fetch(
                    `/files/versions?owner=${props.item.owner_public_id}&file=${props.item.public_id}`,
                    { headers: { Accept: 'application/json' } },
                );

                if (!response.ok) throw new Error('unavailable');

                history.value = await response.json();
            } catch {
                // An honest empty state rather than a spinner that never
                // resolves — the same choice the preview pane makes.
                error.value = 'This file’s history could not be loaded.';
                history.value = null;
            } finally {
                loading.value = false;
            }
        };

        watch(() => props.open, (open) => {
            if (open) load();
            else {
                history.value = null;
                reverting.value = null;
                error.value = '';
            }
        });

        const versions = computed(() => history.value?.versions ?? []);

        const revert = () => {
            if (!reverting.value) return;

            processing.value = true;

            router.post('/files/revert', {
                public_id: props.item.public_id,
                version: reverting.value.version,
            }, {
                preserveScroll: true,
                onFinish: () => {
                    processing.value = false;
                    reverting.value = null;
                    emit('reverted');
                    emit('close');
                },
            });
        };

        return { documentUrl, error, formatTimestamp, history, loading, processing, readableSize, revert, reverting, versions };
    },
    template: `
    <div>
        <ConfirmActionModal
            :open="open && !reverting"
            title="Version history"
            :message="item ? 'Every version of ' + (item.display_name || item.name) + ', newest first. Nothing is ever thrown away: replacing a file keeps what was there before.' : ''"
            confirm-label="Done"
            cancel-label="Close"
            icon="fa-solid fa-clock-rotate-left"
            wider
            @close="$emit('close')"
            @confirm="$emit('close')">
            <div class="text-left">
                <p v-if="loading" class="py-8 text-center text-sm text-slate-500">
                    <i class="fa-solid fa-circle-notch fa-spin mr-2" aria-hidden="true"></i>Reading the history…
                </p>
                <p v-else-if="error" class="py-8 text-center text-sm text-rose-600">{{ error }}</p>
                <ul v-else class="divide-y divide-slate-200">
                    <li v-for="entry in versions" :key="entry.version" class="flex flex-wrap items-start gap-4 py-4">
                        <FileIcon :mime-type="entry.mime_type" size="sm" />
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2 font-bold text-slate-800">
                                Version {{ entry.version }}
                                <span v-if="entry.is_current" class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-emerald-600">Current</span>
                                <span v-if="entry.reverted_from" class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide text-slate-500">
                                    Restored from v{{ entry.reverted_from }}
                                </span>
                            </p>
                            <p class="mt-1 truncate text-sm text-slate-600">{{ entry.original_name }}</p>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ readableSize(entry.size) }}
                                <span v-if="entry.checksum"> · {{ entry.checksum }}</span>
                                <span v-if="entry.created_at"> · {{ formatTimestamp(entry.created_at) }}</span>
                            </p>
                            <p v-if="entry.note" class="mt-2 text-sm text-slate-600">“{{ entry.note }}”</p>
                            <!-- Null is honest rather than missing: a file
                                 never replaced since versioning landed has no
                                 recorded author for version 1, and borrowing
                                 the owner's name would be a snapshot nobody
                                 took. -->
                            <p class="mt-2 inline-flex items-center gap-2 text-xs text-slate-500">
                                <template v-if="entry.created_by">
                                    <UserAvatar :person="{ name: entry.created_by }" class="!h-6 !w-6 !text-[9px]" />
                                    <span>{{ entry.created_by }}</span>
                                </template>
                                <span v-else>Original upload</span>
                            </p>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            <!--
                                For a tracked file, View goes to the
                                document at that version rather than opening
                                the raw bytes in a new tab.

                                Both answer "show me version 9", and the
                                document page answers it better: the version
                                is drawn watermarked as superseded, beside
                                its trail, its comments and the rest of the
                                history. A raw tab is the right answer only
                                where there is no document to go to, which is
                                any file in personal storage that was never
                                tracked.
                            -->
                            <a v-if="documentUrl" :href="documentUrl + '?version=' + entry.version"
                               class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-50">
                                <i class="fa-solid fa-eye mr-1" aria-hidden="true"></i>View
                            </a>
                            <a v-else :href="entry.view_url" target="_blank" rel="noopener"
                               class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-50">
                                <i class="fa-solid fa-eye mr-1" aria-hidden="true"></i>View
                            </a>
                            <a v-if="entry.download_url" :href="entry.download_url"
                               class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-50">
                                <i class="fa-solid fa-download mr-1" aria-hidden="true"></i>Download
                            </a>
                            <button v-if="history && history.can_revert && !entry.is_current" type="button"
                                    class="rounded-lg bg-[#00aeef] px-3 py-1.5 text-xs font-bold text-white transition hover:bg-[#009bd8]"
                                    @click="reverting = entry">
                                <i class="fa-solid fa-rotate-left mr-1" aria-hidden="true"></i>Restore
                            </button>
                        </div>
                    </li>
                    <li v-if="versions.length === 0" class="py-8 text-center text-sm text-slate-500">
                        This file has only ever had one version.
                    </li>
                </ul>
            </div>
        </ConfirmActionModal>

        <ConfirmActionModal
            :open="Boolean(reverting)"
            title="Restore this version?"
            :message="reverting ? 'Version ' + reverting.version + ' will be copied forward as the new current version. Nothing is deleted — the history keeps every version, including the one being replaced.' : ''"
            confirm-label="Restore it"
            icon="fa-solid fa-rotate-left"
            :processing="processing"
            @close="reverting = null"
            @confirm="revert" />
    </div>
`,
});
