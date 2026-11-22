import { computed, defineComponent, onBeforeUnmount, ref, shallowRef, watch } from 'vue';

/**
 * Word and Excel, drawn in this browser from our own stream.
 *
 * These used to go to Microsoft's hosted viewer, which fetches the file **from
 * our server**: three serial round trips, and — because `app.url` has to be
 * reachable from the public internet for that fetch to happen — nothing at all
 * on a laptop serving localhost or inside an office network with no inbound
 * route. The practical result was that document preview never worked on a
 * developer machine and, on a LAN deployment, never worked at all.
 *
 * Rendering here removes the outside service entirely: the bytes come from
 * `files.stream`, which this viewer already has the right to fetch, so nothing
 * leaves the building and a **confidential** document can be previewed for the
 * first time.
 *
 * ## Why this component is mostly about size
 *
 * Rendering in the browser is the right trade and it moves the whole cost of
 * the render onto the reader's machine, which the first implementation spent
 * without bound: it downloaded the whole file with no sign of progress, built
 * every sheet of a workbook at once, and then pushed the result into the page
 * in one synchronous `innerHTML`. Measured against a 11 MB / 1.08M-cell
 * workbook and a 30,000-paragraph Word document, four rules now hold it.
 *
 * **Work is bounded, not merely awaited.** A spreadsheet is drawn one sheet at
 * a time and one window of rows at a time. A hundred thousand cells is a
 * hundred thousand DOM nodes, and `table-layout: auto` measures every one of
 * them before it can place a single column — so the cost is in the *node
 * count*, which no amount of patience fixes. Windowing is what keeps it flat:
 * that workbook draws 300 rows and 4,246 nodes instead of a million cells.
 *
 * **Above a threshold it refuses and says why.** A 60 MB file has no
 * in-browser rendering that is better than a download, and starting one anyway
 * is how a tab is lost — the measured attempt took the renderer process with
 * it. The gate states the size and offers both doors, so the person decides
 * rather than discovering.
 *
 * **Nothing blocks a paint.** The download reports real progress from
 * `content-length`, and the thread is yielded before each heavy step so the
 * spinner and the percentage actually move. A progress bar is not decoration
 * here: it is the difference between "slow" and "broken", which is the whole
 * of the reported symptom.
 *
 * **Nothing large is reactive.** The workbook and the row arrays are held in
 * `shallowRef`s and plain variables. Vue's deep reactivity over a parsed
 * workbook would walk every cell of every sheet to install proxies — a second
 * full pass over the data, costing more than the parse it follows.
 *
 * The one cost that windowing does **not** remove is the parse: `xlsx.read`
 * over a million cells is a single synchronous block, measured at four
 * seconds, and it is why the honest next step is a Worker rather than another
 * refinement here. See `documentation/modules/files.md` → Preview.
 *
 * Three older details still hold.
 *
 * **The libraries are loaded on demand.** `docx-preview` and SheetJS are large,
 * and most previews are a PDF or an image. A static import would put both in
 * the main bundle for every page in the app; a dynamic one fetches them the
 * first time somebody opens a Word or Excel file.
 *
 * **A render is cancelled when the file changes.** The modal has next/previous
 * arrows, so a slow render can still be in flight when the person has already
 * moved on. Each run takes a ticket and throws its result away if a newer one
 * started — otherwise page three renders over page four. The ticket is checked
 * inside the download loop too, so moving on also stops the transfer.
 *
 * **A spreadsheet's cells are never inserted as markup.** They used to be:
 * SheetJS emitted a table, a sanitiser parsed it into an inert document and
 * scrubbed every attribute of every node, and the result went in through
 * `innerHTML`. That was both the single most expensive step in the whole
 * render — a second full DOM build, then a walk over every node and attribute
 * — and a defence that had to be *correct* to be safe. The values are read as
 * data now and rendered by Vue's own text interpolation, so there is no markup
 * to scrub: escaping is a property of the mechanism rather than a pass over
 * the output. Only `docx-preview` writes into a host element, and what it
 * writes it built itself from the OOXML.
 */

/** Above this, a Word document is downloaded rather than drawn. */
const DOCX_LIMIT = 25 * 1024 * 1024;

/** Above this, a workbook is downloaded rather than parsed. */
const SHEET_LIMIT = 12 * 1024 * 1024;

/**
 * Above this, `docx-preview`'s experimental features are switched off.
 *
 * They are what compute tab stops, and doing that means measuring laid-out
 * text for every tab in the document — the one part of a Word render whose
 * cost grows with the document rather than with the page being looked at. A
 * long report loses its tab leaders and gains a render that finishes.
 */
const DOCX_EXPERIMENTAL_LIMIT = 4 * 1024 * 1024;

/** How many rows of a sheet are drawn at a time. */
const ROW_WINDOW = 300;

/**
 * How many columns are drawn at all.
 *
 * A sheet is as wide as its widest row, and a stray value in column DZ makes
 * every row that wide. Past this nobody is reading a table, so the width is
 * capped and the cap is stated.
 */
const COLUMN_CAP = 80;

/** Let the browser paint before the next expensive step. */
const nextFrame = () => new Promise((resolve) => requestAnimationFrame(() => resolve()));

const readableSize = (bytes) => {
    if (! bytes) return '';

    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${value >= 10 || unit === 0 ? Math.round(value) : value.toFixed(1)} ${units[unit]}`;
};

export default defineComponent({
    name: 'OfficeRenderer',
    props: {
        /** `files.stream?disposition=inline` for this file. */
        src: { type: String, required: true },
        /** 'docx' or 'xlsx', decided by the server — never by this component. */
        renderer: { type: String, required: true },
        name: { type: String, default: '' },
        /**
         * The descriptor's `size`, in bytes.
         *
         * The gate is decided from this rather than from `content-length`,
         * because the point of the gate is to refuse *before* the transfer:
         * reading the header means the download has already started.
         */
        size: { type: Number, default: 0 },
        /** The descriptor's `download_url`, so the gate can offer the real door. */
        downloadUrl: { type: String, default: null },
    },
    setup(props) {
        const host = ref(null);
        const loading = ref(false);
        const failed = ref('');
        const progress = ref(null);
        const stage = ref('');

        /** True while the person has been told the file is large and has not chosen. */
        const gated = ref(false);

        /*
         * Sheet state. `rows` is a shallowRef and the workbook is a plain
         * variable: both hold more values than it is ever worth proxying.
         */
        const sheets = shallowRef([]);
        const rows = shallowRef([]);
        const active = ref(0);
        const shown = ref(ROW_WINDOW);
        let book = null;
        let xlsxLib = null;

        let ticket = 0;

        const limit = computed(() => (props.renderer === 'docx' ? DOCX_LIMIT : SHEET_LIMIT));
        const oversized = computed(() => props.size > 0 && props.size > limit.value);
        const sizeLabel = computed(() => readableSize(props.size));

        const columns = computed(() => {
            let widest = 0;

            for (const row of rows.value) {
                if (row.length > widest) widest = row.length;
                if (widest >= COLUMN_CAP) return COLUMN_CAP;
            }

            return widest;
        });

        const clipped = computed(() => rows.value.some((row) => row.length > COLUMN_CAP));
        const visible = computed(() => rows.value.slice(0, shown.value));
        const more = computed(() => Math.max(0, rows.value.length - shown.value));

        const reset = () => {
            failed.value = '';
            progress.value = null;
            stage.value = '';
            sheets.value = [];
            rows.value = [];
            active.value = 0;
            shown.value = ROW_WINDOW;
            book = null;

            if (host.value) host.value.innerHTML = '';
        };

        /**
         * Fetch the bytes, reporting progress.
         *
         * Streamed and reassembled rather than taken as one `arrayBuffer()`,
         * for the percentage alone — the transfer is the longer half of a big
         * preview, and a spinner cannot say whether it is moving. The ticket is
         * checked every chunk, so navigating away cancels the transfer instead
         * of finishing it into a host nobody is looking at.
         */
        const download = async (mine) => {
            stage.value = 'Downloading';

            const response = await fetch(props.src, { credentials: 'same-origin' });

            if (! response.ok) throw new Error(String(response.status));

            const total = Number(response.headers.get('content-length')) || props.size || 0;

            if (! response.body || ! total) return new Uint8Array(await response.arrayBuffer());

            const reader = response.body.getReader();
            const chunks = [];
            let received = 0;
            let painted = 0;

            for (;;) {
                const { done, value } = await reader.read();

                if (done) break;

                if (mine !== ticket) {
                    reader.cancel().catch(() => {});

                    return null;
                }

                chunks.push(value);
                received += value.length;

                const percent = Math.min(99, Math.round((received / total) * 100));

                /*
                 * One update per whole percent, not per chunk: a 40 MB file
                 * arrives in thousands of chunks and re-rendering on each one
                 * is its own source of jank. No frame is awaited here — the
                 * next `reader.read()` yields the thread by itself, so the
                 * browser paints without the loop stalling for it.
                 */
                if (percent !== painted) {
                    painted = percent;
                    progress.value = percent;
                }
            }

            const bytes = new Uint8Array(received);
            let at = 0;

            for (const chunk of chunks) {
                bytes.set(chunk, at);
                at += chunk.length;
            }

            return bytes;
        };

        /** Build one sheet's rows. Called on open and on every tab change. */
        const openSheet = async (index) => {
            const mine = ticket;

            active.value = index;
            shown.value = ROW_WINDOW;
            rows.value = [];
            stage.value = 'Laying out';
            loading.value = true;

            await nextFrame();

            try {
                const sheet = book.Sheets[sheets.value[index]];
                const built = xlsxLib.utils.sheet_to_json(sheet, {
                    header: 1,
                    // Formatted values: a date must read as a date, not as the
                    // serial number underneath it.
                    raw: false,
                    defval: '',
                    blankrows: false,
                });

                if (mine !== ticket) return;

                rows.value = built;
            } catch (error) {
                if (mine !== ticket) return;

                failed.value = 'This sheet could not be laid out. Download the file to open it in Excel.';
            } finally {
                if (mine === ticket) loading.value = false;
            }
        };

        const render = async () => {
            const mine = ++ticket;

            reset();

            loading.value = true;

            try {
                const bytes = await download(mine);

                if (bytes === null || mine !== ticket) return;

                stage.value = 'Rendering';
                progress.value = null;

                // The parse and the DOM build are both synchronous and both
                // long. Yielding here is what lets "Rendering…" appear at all.
                await nextFrame();

                if (props.renderer === 'docx') {
                    if (! host.value) return;

                    const docx = await import('docx-preview');

                    if (mine !== ticket || ! host.value) return;

                    await docx.renderAsync(bytes, host.value, undefined, {
                        className: 'docx',
                        inWrapper: true,
                        ignoreWidth: false,
                        experimental: props.size > 0 && props.size <= DOCX_EXPERIMENTAL_LIMIT,
                    });

                    if (mine === ticket) loading.value = false;

                    return;
                }

                xlsxLib = await import('xlsx');

                if (mine !== ticket) return;

                /*
                 * Nothing but the values is wanted, so nothing but the values
                 * is parsed: styles, generated HTML and blank stubs are all
                 * work whose output this renderer throws away.
                 */
                book = xlsxLib.read(bytes, {
                    type: 'array',
                    cellStyles: false,
                    cellHTML: false,
                    sheetStubs: false,
                    cellDates: true,
                });

                if (mine !== ticket) return;

                sheets.value = book.SheetNames;

                if (sheets.value.length === 0) {
                    failed.value = 'This workbook has no sheets to show.';
                    loading.value = false;

                    return;
                }

                await openSheet(0);
            } catch (error) {
                if (mine !== ticket) return;

                failed.value = 'This file could not be rendered here. Download it to open it in Word or Excel.';
                loading.value = false;
            }
        };

        /** The gate's "preview anyway". */
        const proceed = () => {
            gated.value = false;
            render();
        };

        const start = () => {
            if (oversized.value) {
                ++ticket;
                reset();
                gated.value = true;
                loading.value = false;

                return;
            }

            gated.value = false;
            render();
        };

        watch(() => [props.src, props.renderer], start, { immediate: true });

        // A render in flight must not write into an unmounted host, and a
        // transfer in flight must not keep running for a closed modal.
        onBeforeUnmount(() => { ticket += 1; });

        return {
            active,
            clipped,
            columns,
            failed,
            gated,
            host,
            loading,
            more,
            openSheet,
            proceed,
            progress,
            rows,
            sheets,
            showMore: () => { shown.value += ROW_WINDOW; },
            sizeLabel,
            stage,
            visible,
        };
    },
    template: `
    <!--
        The two transient states are centred in the frame; the document is
        not. They were laid out in the normal flow with top padding, so in a
        70vh panel "Rendering…" sat near the top of a tall empty box and
        read as a stalled page rather than as work in progress.

        Centred by overlaying rather than by making the scroll container a
        flex box: items-center on a scroll container pins overflowing
        content so the top of a long document becomes unreachable, which is
        a classic way to make the thing you were centring unscrollable.
    -->
    <div class="relative flex min-h-0 flex-1 flex-col bg-[#ffffff]">
        <!-- The large-file gate. Stated before the cost is paid rather than
             discovered after it, which is the same reason the delete dialog
             counts a folder before erasing it. -->
        <div v-if="gated" class="flex min-h-0 flex-1 flex-col items-center justify-center gap-4 p-8 text-center">
            <span class="inline-flex h-16 w-16 items-center justify-center rounded-full bg-amber-100 text-2xl text-amber-600" aria-hidden="true">
                <i class="fa-solid fa-weight-hanging"></i>
            </span>
            <div>
                <h2 class="text-lg font-bold uppercase tracking-wide text-slate-900">Large file</h2>
                <p class="mt-2 max-w-md text-sm text-slate-600">
                    {{ name }} is {{ sizeLabel }}. Drawing it in the browser would take a while and may make this page unresponsive. Opening it in Word or Excel will be faster.
                </p>
            </div>
            <div class="flex flex-wrap items-center justify-center gap-3">
                <a v-if="downloadUrl" :href="downloadUrl" class="inline-flex items-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8]">
                    <i class="fa-solid fa-download" aria-hidden="true"></i>Download
                </a>
                <button type="button" @click="proceed" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                    <i class="fa-solid fa-eye" aria-hidden="true"></i>Preview anyway
                </button>
            </div>
        </div>

        <template v-else>
            <!-- A real percentage while the bytes are arriving, and the stage
                 name after. A spinner alone cannot distinguish slow from
                 broken, which is the whole of what a big preview feels like. -->
            <div v-if="loading" class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 bg-[#ffffff] px-6 text-center" aria-busy="true">
                <p class="flex items-center gap-3 text-sm font-semibold text-slate-500">
                    <i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i>{{ stage || 'Rendering' }} {{ name }}…
                </p>
                <div v-if="progress !== null" class="h-1.5 w-56 overflow-hidden rounded-full bg-slate-200">
                    <div class="h-full rounded-full bg-[#00aeef] transition-[width] duration-150" :style="{ width: progress + '%' }"></div>
                </div>
                <p v-if="progress !== null" class="text-xs font-semibold tabular-nums text-slate-400">{{ progress }}%</p>
            </div>

            <p v-if="failed" class="absolute inset-0 z-10 flex items-center justify-center bg-[#ffffff] px-6 text-center text-sm font-semibold text-rose-600">{{ failed }}</p>

            <!-- A workbook: one sheet at a time, one window of rows at a
                 time. Every cell is text interpolation, so nothing from the
                 file is ever markup. -->
            <template v-if="renderer !== 'docx' && sheets.length">
                <div v-if="sheets.length > 1" class="flex shrink-0 gap-1 overflow-x-auto border-b border-slate-200 px-3 pt-2">
                    <button
                        v-for="(sheet, index) in sheets"
                        :key="sheet"
                        type="button"
                        @click="openSheet(index)"
                        :class="['shrink-0 rounded-t-lg px-3 py-2 text-xs font-bold uppercase tracking-wide transition', index === active ? 'bg-sky-50 text-[#07559e]' : 'text-slate-500 hover:bg-slate-50']">
                        {{ sheet }}
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-auto px-4 py-6 sm:px-8">
                    <section class="office-sheet">
                        <table>
                            <tbody>
                                <tr v-for="(row, index) in visible" :key="index">
                                    <td v-for="column in columns" :key="column">{{ row[column - 1] }}</td>
                                </tr>
                            </tbody>
                        </table>

                        <div v-if="more || clipped" class="mt-4 flex flex-wrap items-center gap-3">
                            <button v-if="more" type="button" @click="showMore" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold uppercase tracking-wide text-slate-700 transition hover:bg-slate-50">
                                <i class="fa-solid fa-angles-down" aria-hidden="true"></i>Show more rows
                            </button>
                            <p class="text-xs font-semibold text-slate-500">
                                <span v-if="more">{{ visible.length }} of {{ rows.length }} rows shown.</span>
                                <span v-if="clipped"> Wide sheet — the first {{ columns }} columns are shown. Download it to read the rest.</span>
                            </p>
                        </div>
                    </section>
                </div>
            </template>

            <!-- A Word document, written by docx-preview into its own host. -->
            <div v-else class="min-h-0 flex-1 overflow-auto">
                <div v-show="!loading && !failed" ref="host" class="office-render px-4 py-6 sm:px-8"></div>
            </div>
        </template>
    </div>
`,
});
