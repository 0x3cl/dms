import { router } from '@inertiajs/vue3';
import { computed, defineComponent, ref, watch } from 'vue';
import QrScanner from './QrScanner';
import { pushToast } from './ToastStack';

/**
 * The three ways into a document: type the code, scan the label, or upload a
 * picture of it.
 *
 * **One component, used by both the Track page and the modal on the Documents
 * register**, because they were two designs for one question — the page had a
 * single text input and a link away to a separate camera page, while the modal
 * had its own two-method chooser. Two implementations of "find a document"
 * meant the third method would have had to be built twice, and the office
 * would meet whichever one they happened to open.
 *
 * The third method needs no new dependency: `html5-qrcode` already ships
 * `scanFile`, and `QrScanner` now exposes it through `mode="file"`. That
 * matters for the real case it serves — somebody is sent a photo of a folder,
 * or has a screenshot of a label, and has no camera pointed at anything.
 *
 * **The deliberate three-second floor** is the other thing worth
 * understanding. A tracking lookup is one indexed query, so it usually returns
 * before the page has finished painting the spinner — which reads as nothing
 * having happened, and people press the button again. The floor is applied as
 * `Promise.all([visit, timer])`: the result is shown on the *later* of the
 * two, so a slow lookup is never cut short and a fast one still shows that it
 * was working. A blind `setTimeout` before starting the visit would instead
 * make every lookup three seconds slower than it needs to be.
 */

/** How long the searching state stays up, at minimum. */
const MINIMUM_FEEDBACK_MS = 3000;

export default defineComponent({
    name: 'TrackDocumentCard',
    components: { QrScanner },
    emits: ['found'],
    props: {
        /** A code already attempted, echoed back by the controller. */
        code: { type: String, default: '' },
        notFound: { type: Boolean, default: false },
        /**
         * The server's other miss: the input could not be a tracking code at
         * all. Kept apart from `notFound` because the two send a person to
         * different places — to what they typed, or to the printed label.
         */
        malformed: { type: Boolean, default: false },
        /** The modal renders its own heading, so it turns this one off. */
        heading: { type: Boolean, default: true },
        /**
         * Where a code is looked up.
         *
         * Two doors, one card: `/documents/track` is the authenticated lookup
         * and `/track` the public one. The *card* is the same question in both
         * places — type it, scan it, or upload a picture of it — so forking it
         * into a second component would mean the third method is built twice
         * again, which is the thing this component exists to have stopped.
         */
        target: { type: String, default: '/documents/track' },
        /** Which method opens first, so a scanned label lands on the camera. */
        initialMethod: { type: String, default: 'code' },
    },
    setup(props, { emit }) {
        const method = ref(['code', 'scan', 'upload'].includes(props.initialMethod) ? props.initialMethod : 'code');
        const code = ref(props.code);
        const searching = ref(false);
        /**
         * The field is marked, and the words are a toast.
         *
         * This form has one control, so a line of text beneath it says
         * nothing about *where* that the input's own red ring does not
         * already say, and it costs a reserved line of height that is empty
         * almost all of the time. The ring says which control; the toast
         * says what is wrong. The rule holds only while there is one
         * control — a form with eight fields keeps its per-field messages,
         * because a toast cannot say which of the eight it means.
         *
         * `invalid` outlives the toast deliberately: the toast expires after
         * a few seconds and the input is still wrong, so the ring is what
         * remains true.
         */
        const invalid = ref(false);

        /**
         * Whether the box has been touched since the server last answered.
         *
         * The two server messages are about a **lookup that was submitted**,
         * so they have to stop being shown the moment the person starts
         * typing a different one. Without this they outlived their subject:
         * the banner read the live input — `code` is a local ref shadowing
         * the prop of the same name — so clearing the field left
         * *"“” is not a tracking code"* on screen, a complaint about a value
         * nobody had searched for.
         *
         * Hence both halves of the fix. `attempted` is the value the server
         * actually answered about, quoted in the message instead of whatever
         * is in the box now; `edited` hides the message as soon as the box
         * stops being that value.
         */
        const edited = ref(false);
        const attempted = ref(props.code);

        /*
         * A new answer arrives as new props on the same mounted component —
         * the lookup visits with `preserveState: true` — so neither of those
         * resets itself. This is what makes a second miss show its own
         * message rather than the first one's.
         */
        watch(() => [props.code, props.notFound, props.malformed], () => {
            attempted.value = props.code;
            edited.value = false;
        });

        /** A server answer is shown only while it is still about the input. */
        const missShown = computed(() => !searching.value && !edited.value && attempted.value !== '');
        /**
         * Bumped to remount QrScanner.
         *
         * A camera scan stops the stream before handing the result up, so
         * after a symbol that turns out not to be a label the region is dead
         * and there is nothing to point at the folder again. Remounting is
         * the restart: `onBeforeUnmount` releases the old stream and the new
         * instance starts its own, which is the one teardown path that is
         * already tested rather than a second way to stop a camera.
         */
        const scannerKey = ref(0);

        const methods = [
            { key: 'code', label: 'Tracking code', icon: 'fa-solid fa-keyboard', hint: 'Type the code printed on the label' },
            { key: 'scan', label: 'Scan QR', icon: 'fa-solid fa-camera', hint: 'Point your camera at the QR Tracker' },
            { key: 'upload', label: 'Upload QR image', icon: 'fa-solid fa-image', hint: 'Read a photo or screenshot of the label' },
        ];

        const choose = (key) => {
            invalid.value = false;
            // Switching method is starting a different attempt, so the last
            // answer stops being about what is on screen.
            edited.value = true;
            method.value = key;
        };

        /**
         * The longest thing that could be a tracking code.
         *
         * `ABBR-YYYYMMDD-NNNN` with a 16-character type code is thirty, and
         * the cap is slack over that. It matches the server's, and it is a
         * bound on the *input* rather than a statement of the format — the
         * format lives in `TrackingCodeService::MASK`, once, and the server
         * is what applies it.
         */
        const MAX_CODE_LENGTH = 64;

        /**
         * What this component can know is wrong, without restating the format.
         *
         * Deliberately only two rules, and both are about the *input* rather
         * than about what a code looks like: it is empty, or it is plainly
         * not one value — a paragraph, a vCard, a URL with spaces in it. Any
         * other judgement is the server's, because a second copy of the mask
         * here is a second thing to update when a type code changes shape,
         * and the copy that drifts is always the one that refuses a code
         * that is in fact valid.
         */
        const complaint = (value, source) => {
            if (value === '') {
                return source === 'code'
                    ? 'Enter the tracking code printed on the label.'
                    : 'Nothing could be read from that label. Type the code printed beside it instead.';
            }

            if (value.length > MAX_CODE_LENGTH || /\s/.test(value)) {
                return source === 'code'
                    ? 'A tracking code has no spaces and is far shorter than that — check it against the printed label.'
                    : 'That QR code does not carry a tracking code. It may be a label from another system.';
            }

            return '';
        };

        /**
         * Refuse: say it in a toast, mark the field, and put the camera back
         * if that is where this came from.
         *
         * The input is only marked for a complaint about the input — a
         * scanned symbol that is not one of our labels is not a statement
         * about what is typed in the box, and reddening it there would point
         * at the wrong control.
         */
        const refuse = (message, { rescan = false, field = false } = {}) => {
            pushToast({ tone: 'error', message });

            if (field) invalid.value = true;
            if (rescan) scannerKey.value += 1;
        };

        /**
         * Go, with a floor on how briefly the searching state can flash.
         *
         * `Promise.all` and not a delay before the visit: the request and the
         * timer start together and the result lands on the later of the two.
         */
        const go = (visit) => {
            searching.value = true;

            const settled = new Promise((resolve) => {
                visit(resolve);
            });
            const floor = new Promise((resolve) => setTimeout(resolve, MINIMUM_FEEDBACK_MS));

            Promise.all([settled, floor]).finally(() => {
                searching.value = false;
            });
        };

        const track = (value, source = 'code') => {
            if (searching.value) return;

            const trimmed = (value ?? '').trim();
            const wrong = complaint(trimmed, source);

            if (wrong) {
                refuse(wrong, { rescan: source === 'scan', field: source === 'code' });

                return;
            }

            invalid.value = false;

            /*
             * Marked as attempted here rather than left to the watcher. The
             * same wrong code submitted twice produces identical props, so
             * nothing would fire and the message — hidden the moment the box
             * was edited — would never come back. A submission is the event
             * that makes an answer current again, so it says so itself.
             */
            attempted.value = trimmed;
            edited.value = false;

            emit('found', trimmed);

            go((done) => router.get(props.target, { code: trimmed }, {
                preserveState: true,
                onFinish: done,
            }));
        };

        const submit = () => track(code.value);

        /**
         * A scan may carry a full tracking URL — our own labels do — or a bare
         * code, which is what a generic QR generator produces. Both normalise
         * to the same visit.
         */
        const scanned = (text, source = 'scan') => {

            try {
                const url = new URL(text);

                if (url.origin === window.location.origin) {
                    emit('found', text);
                    go((done) => router.visit(url.pathname + url.search, { onFinish: done }));

                    return;
                }

                /*
                 * A URL, and not ours. Said plainly rather than sent to the
                 * register as a code: somebody has scanned a poster, a wifi
                 * label or a delivery slip, and "no document carries that
                 * code" would have them checking a code they never typed.
                 */
                refuse('That QR code points to another site, so it is not a DocTrack label.', { rescan: source === 'scan' });

                return;
            } catch {
                /* Not a URL, so treat it as a bare code. */
            }

            code.value = text.trim().slice(0, MAX_CODE_LENGTH);
            track(text, source);
        };

        return { attempted, choose, code, edited, invalid, method, methods, missShown, scanned, scannerKey, searching, submit };
    },
    template: `
    <div>
        <div v-if="heading">
            <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Documents</p>
            <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">Track a document</h2>
            <p class="mt-1 text-slate-600">Three ways in. Use whichever you have to hand.</p>
        </div>

        <!-- The methods as cards rather than a select: all three are visible,
             so nobody has to discover that uploading a label is possible. -->
        <div class="mt-5 grid gap-3 sm:grid-cols-3">
            <button
                v-for="option in methods"
                :key="option.key"
                type="button"
                class="rounded-xl border px-4 py-3 text-left transition"
                :class="method === option.key ? 'border-[#00aeef] bg-sky-50' : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50'"
                :aria-pressed="method === option.key"
                @click="choose(option.key)">
                <span class="flex items-center gap-2 text-sm font-bold" :class="method === option.key ? 'text-[#07559e]' : 'text-slate-700'">
                    <i :class="option.icon" aria-hidden="true"></i>{{ option.label }}
                </span>
                <span class="mt-1 block text-xs text-slate-500">{{ option.hint }}</span>
            </button>
        </div>

        <!-- Two misses, two messages. "Not a tracking code" sends somebody
             back to what they typed; "no document carries that code" sends
             them back to the label. One message for both would send half of
             them to the wrong place. -->
        <p v-if="malformed && missShown" class="mt-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
            “{{ attempted }}” is not a tracking code. They read like <span class="font-mono">MEMO-20260101-0001</span> — the type, the date, then the number.
        </p>

        <p v-else-if="notFound && missShown" class="mt-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
            No document carries the code “{{ attempted }}”. Check it against the printed label.
        </p>

        <div v-if="searching" class="mt-6 flex flex-col items-center justify-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-10 text-sm font-semibold text-slate-600">
            <i class="fa-solid fa-circle-notch fa-spin text-2xl text-[#00aeef]" aria-hidden="true"></i>
            Looking for that document…
        </div>

        <template v-else>
            <form v-if="method === 'code'" class="mt-6" @submit.prevent="submit">
                <label class="block">
                    <span class="required-label text-sm font-semibold text-slate-700">Tracking code</span>
                    <!-- border-rose-500 is the marker app.js scrolls to on a
                         validation failure, so an invalid code here looks the
                         same as an invalid field anywhere else in the app. -->
                    <input
                        data-tour="track-code"
                        v-model="code"
                        class="field"
                        :class="{ 'border-rose-500': ((notFound || malformed) && missShown) || invalid }"
                        maxlength="64"
                        placeholder="e.g. MEMO-20260101-0001"
                        @input="invalid = false; edited = true" />
                    <span class="mt-1 block text-xs text-slate-500">Printed beside the QR Tracker, in the form MEMO-20260101-0001.</span>
                </label>
                <button type="submit" class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#00aeef] px-5 py-3 text-sm font-bold uppercase tracking-wide text-white transition hover:bg-[#008dcc]">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>Track document
                </button>
            </form>

            <!-- The :key is the restart. A camera scan stops the stream before
                 handing the result up, so after a symbol that turns out not to
                 be one of our labels the region is dead; remounting releases
                 the old stream through the teardown that is already tested and
                 starts a fresh one. QrScanner reports a camera that will not
                 start itself, so there is no second message for that here. -->
            <div v-else-if="method === 'scan'" class="mt-6">
                <QrScanner :key="scannerKey" mode="camera" @scanned="scanned($event, 'scan')" />
            </div>

            <div v-else class="mt-6">
                <QrScanner mode="file" @scanned="scanned($event, 'upload')" />
            </div>
        </template>

    </div>
`,
});
