import { computed, defineComponent, ref } from 'vue';
import ConfirmActionModal from './ConfirmActionModal';
import { formatTimestamp } from '../utils/format-date';

/**
 * A file's versions, newest first.
 *
 * Extracted because this list is in two places — the drawer beside the
 * document and the aside inside the full-screen preview — and two copies of
 * "what does a version row look like" is how one of them quietly stops
 * showing something the other learned to show. The same reasoning that
 * produced `FilePreviewPane`.
 *
 * **A row carries what distinguishes one version from another, and nothing
 * else.** It grew to five lines — name, size, author, date, replaced-on,
 * note — which in a 21rem column is a paragraph per version and a list
 * nobody scans. What a reader needs to tell two versions apart is the
 * number, whether it is live, and who put it there; everything else is a
 * question about *one* version, so it is behind Info rather than printed on
 * all of them.
 *
 * **It decides nothing.** Which versions exist, whether they may be
 * downloaded and which one is on screen are all the caller's answers; this
 * renders them and emits `show`. A list that resolved its own access would
 * be a second gate beside `AccessResolver`.
 *
 * **One appearance, not two.** It carried a `dark` variant while the
 * full-screen aside was drawn straight onto the overlay's scrim; that aside
 * is a white panel now, so both places it renders are light. The variant
 * went with the need for it — a second set of colours that nothing asks for
 * is a second set to keep correct.
 */
export default defineComponent({
    name: 'VersionList',
    components: { ConfirmActionModal },
    emits: ['show'],
    props: {
        /** The `versions` array from `/documents/{id}/versions`. */
        entries: { type: Array, default: () => [] },
        loading: { type: Boolean, default: false },
        /** The version being drawn, or null for the current file. */
        shown: { type: Number, default: null },
        /**
         * The document this file is tracked as, or null.
         *
         * When there is one, Show becomes a **link** to that document at
         * that version rather than a request to swap the preview in place.
         * The two are different answers to the same click and which is right
         * depends on where the list is: inside the document page, swapping is
         * the whole point and navigating would reload the page somebody is
         * already on; inside the file manager's modal there is no preview
         * behind it to swap, and the document page is where a version can be
         * read beside its trail, its comments and its label.
         *
         * A link rather than an emit so it can be middle-clicked, and so the
         * modal does not have to re-implement what the document page already
         * does with `?version=`.
         */
        documentUrl: { type: String, default: null },
    },
    setup() {
        /** The entry whose full details are open, or null. */
        const detailed = ref(null);

        /** Bytes as somebody would say them. Blank when there is no size. */
        const readableSize = (bytes) => {
            if (! bytes) return '';

            const units = ['B', 'KB', 'MB', 'GB'];
            let value = bytes;
            let unit = 0;

            while (value >= 1024 && unit < units.length - 1) {
                value /= 1024;
                unit += 1;
            }

            return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
        };

        /**
         * Everything about one version, as label/value pairs.
         *
         * Only what is actually recorded. A version with no author
         * genuinely has none — a file that predates versioning has nobody
         * snapshotted against its first version — so that row reads
         * "Original upload" rather than a blank or an invented name, and a
         * row with nothing to say is left out entirely rather than printed
         * as an em dash.
         */
        const details = computed(() => {
            const entry = detailed.value;

            if (! entry) return [];

            return [
                { label: 'Version', value: String(entry.version) },
                { label: 'Status', value: entry.is_current ? 'Current — this is the live file' : 'Superseded' },
                { label: 'File name', value: entry.original_name },
                entry.extension ? { label: 'Format', value: String(entry.extension).toUpperCase() } : null,
                readableSize(entry.size) ? { label: 'Size', value: readableSize(entry.size) } : null,
                { label: 'Added by', value: entry.created_by ?? 'Original upload' },
                entry.created_at ? { label: 'Added', value: formatTimestamp(entry.created_at) } : null,
                entry.superseded_at ? { label: 'Replaced', value: formatTimestamp(entry.superseded_at) } : null,
                entry.reverted_from ? { label: 'Restored from', value: `Version ${entry.reverted_from}` } : null,
                entry.note ? { label: 'What changed', value: entry.note } : null,
                /*
                 * Truncated, and that is not squeamishness: the whole digest
                 * is 64 characters of hex that nobody reads off a screen,
                 * while the first twelve are enough to tell two versions
                 * apart by eye — which is the only thing it is here for.
                 */
                entry.checksum ? { label: 'Checksum', value: String(entry.checksum).slice(0, 12) } : null,
            ].filter(Boolean);
        });

        /**
         * The action buttons, sized as one set.
         *
         * They were three different boxes — an icon square, a label that
         * changed width between "Show" and "Showing", and a wider Download
         * — so no two rows lined up and the group looked assembled rather
         * than designed. One height for all three, and a fixed width on the
         * show control so its two states occupy the same box: a button that
         * resizes when its own label changes drags the row with it.
         *
         * Composed here rather than repeated in four class attributes, so
         * the set cannot drift one button at a time.
         */
        const action = 'inline-flex h-8 items-center justify-center rounded-lg text-xs font-bold transition';
        const outline = 'border border-slate-300 text-slate-700 hover:bg-slate-50';

        return { action, detailed, details, formatTimestamp, outline, readableSize };
    },
    template: `
    <div>
        <p v-if="loading" class="py-6 text-center text-sm text-slate-500">
            <i class="fa-solid fa-circle-notch fa-spin mr-2" aria-hidden="true"></i>Reading the history…
        </p>
        <p v-else-if="entries.length === 0" class="py-6 text-center text-sm text-slate-500">
            This file has only ever had one version.
        </p>
        <ul v-else class="divide-y divide-slate-200">
            <li v-for="entry in entries" :key="entry.version" class="py-3.5">
                <!--
                    A stack, not a row.

                    The actions come to about 15rem and both panels this
                    renders in are narrow — 21rem in the full-screen aside,
                    30rem in the drawer — so putting them beside the text
                    left the text a word per line. Stacking is also the only
                    arrangement that is the same in both, which is the point
                    of the component being shared.
                -->
                <!--
                    One phrase, not a title and a pill.

                    "Version 1 — Superseded" reads as a sentence and matches
                    the badge over the preview word for word. The status
                    keeps the colour — emerald is the live file, rose a
                    superseded one — so the list is still scannable by
                    colour, which a pill was only ever a heavier way to do.
                -->
                <p class="text-sm font-bold text-slate-800">
                    Version {{ entry.version }}
                    <span :class="entry.is_current ? 'text-emerald-700' : 'text-rose-700'">— {{ entry.is_current ? 'Current' : 'Superseded' }}</span>
                </p>

                <!-- Who and when, which is what tells two versions apart.
                     Everything else is behind Info. -->
                <p class="mt-1 text-xs text-slate-500">
                    {{ entry.created_by ?? 'Original upload' }}<span v-if="entry.created_at"> · {{ formatTimestamp(entry.created_at) }}</span>
                </p>

                <div class="mt-2.5 flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="w-8 shrink-0"
                        :class="[action, outline]"
                        :title="'Details of version ' + entry.version"
                        :aria-label="'Details of version ' + entry.version"
                        @click="detailed = entry">
                        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                    </button>
                    <!-- The one on screen says so instead of offering to
                         show itself again. -->
                    <span
                        v-if="(shown === null && entry.is_current) || shown === entry.version"
                        class="w-[5.5rem] shrink-0"
                        :class="[action, 'bg-sky-50 text-[#07559e]']">
                        <i class="fa-solid fa-eye mr-1.5" aria-hidden="true"></i>Showing
                    </span>
                    <a
                        v-else-if="documentUrl"
                        :href="documentUrl + '?version=' + entry.version"
                        class="w-[5.5rem] shrink-0"
                        :class="[action, outline]">
                        <i class="fa-solid fa-eye mr-1.5" aria-hidden="true"></i>View
                    </a>
                    <button
                        v-else
                        type="button"
                        class="w-[5.5rem] shrink-0"
                        :class="[action, outline]"
                        @click="$emit('show', entry)">
                        <i class="fa-solid fa-eye mr-1.5" aria-hidden="true"></i>Show
                    </button>
                    <!-- A file response, so a plain link. -->
                    <a
                        v-if="entry.download_url"
                        :href="entry.download_url"
                        class="w-[7rem] shrink-0"
                        :class="[action, outline]">
                        <i class="fa-solid fa-download mr-1.5" aria-hidden="true"></i>Download
                    </a>
                </div>
            </li>
        </ul>

        <!--
            The full record of one version.

            ConfirmActionModal rather than a dialog of its own: it already
            renders a label/value list, and a null cancel-label plus a Close
            confirm is the shape it provides for a modal that reports
            something and has nothing to decline.

            layer-class is load-bearing here. The default z-50 is fine over a
            page; this list also renders *inside* the full-screen preview,
            which is z-[70], so without lifting it the details would open
            behind the thing they were opened from.
        -->
        <ConfirmActionModal
            :open="detailed !== null"
            layer-class="z-[80]"
            icon="fa-solid fa-clock-rotate-left"
            :title="detailed ? 'Version ' + detailed.version : ''"
            message="Everything recorded about this version of the file."
            :details="details"
            :cancel-label="null"
            confirm-label="Close"
            @close="detailed = null"
            @confirm="detailed = null" />
    </div>
`,
});
