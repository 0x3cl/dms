import { defineComponent, onBeforeUnmount, onMounted, ref } from 'vue';
import { Html5Qrcode } from 'html5-qrcode';
import { pushToast } from './ToastStack';

/**
 * QR reader — from the camera, or from an image of a label.
 *
 * html5-qrcode is an npm dependency here, not the unpinned `unpkg.com` tag the
 * Blade page used — which was also loaded twice, once in the layout and again
 * in the page, and never released the camera on navigation. Stopping the
 * stream in onBeforeUnmount is the whole reason this is a component: an
 * Inertia visit unmounts the page without reloading the document, so nothing
 * else would ever turn the camera off.
 *
 * **`mode="file"` reads a photo or a screenshot instead of a live stream**,
 * through the library's own `scanFile`. It is the same component rather than a
 * sibling for two reasons: html5-qrcode should be known to exactly one file,
 * and the camera must *not* be started in that mode — a page that opens the
 * camera to read a PNG is asking for a permission prompt it has no use for.
 * Which also means `onMounted` has to branch, because an unconditional
 * `start()` would do exactly that.
 *
 * Emits `scanned` with the decoded text. The parent decides what that means —
 * a scan may carry a full tracking URL or a bare code.
 */
export default defineComponent({
    name: 'QrScanner',
    emits: ['scanned', 'error'],
    props: {
        /** 'camera' streams; 'file' reads an image the person chooses. */
        mode: { type: String, default: 'camera' },
    },
    setup(props, { emit }) {
        const regionId = 'qr-scanner-region';
        const starting = ref(props.mode === 'camera');
        const failure = ref('');
        const reading = ref(false);
        let scanner = null;
        let stopped = false;

        const stop = async () => {
            if (!scanner || stopped) {
                return;
            }

            stopped = true;

            try {
                await scanner.stop();
                scanner.clear();
            } catch {
                /* Already stopped, or the page is being torn down — nothing to do. */
            }
        };

        /**
         * Read a chosen image.
         *
         * A failure here is ordinary and worth saying plainly: people
         * photograph a folder at an angle, or pick the wrong file. The library
         * throws rather than returning null, so the message is ours.
         */
        const readFile = async (event) => {
            const file = event.target.files?.[0];

            // Nothing chosen, or the dialog was cancelled.
            if (!file) return;

            /*
             * Checked here rather than left to `accept="image/*"`.
             *
             * That attribute filters the picker and nothing else: a drag, a
             * paste or a file manager that ignores it all deliver whatever
             * was chosen, and the library's failure for a PDF reads as "no QR
             * code could be read" — which sends somebody looking for a better
             * photo of a file that was never a photo.
             */
            const reject = (message) => {
                /*
                 * A toast, not a line under the picker.
                 *
                 * The split here is between a *condition* and an *attempt*.
                 * A camera that will not start is a condition: it is still
                 * true in ten seconds, so it states itself in place, below.
                 * A file that could not be read is an attempt, over the
                 * moment it is reported — and this picker is the only
                 * control on its form, so a message beneath it says nothing
                 * about where that the control itself does not.
                 */
                pushToast({ tone: 'error', message });
                emit('error', new Error(message));
                event.target.value = '';
            };

            if (!file.type.startsWith('image/')) {
                reject('That is not an image. Choose a photo or a screenshot of the label.');

                return;
            }

            if (file.size === 0) {
                reject('That image is empty. Choose another photo of the label.');

                return;
            }

            reading.value = true;
            failure.value = '';

            try {
                // `false` — do not render the chosen image into the region;
                // the page has its own layout for it.
                const decoded = await scanner.scanFile(file, false);
                emit('scanned', decoded);
            } catch (error) {
                pushToast({
                    tone: 'error',
                    message: 'No QR code could be read from that image. Try a clearer photo of the label, or type the code instead.',
                });
                emit('error', error);
            } finally {
                reading.value = false;
                // Let the same file be chosen again after a failure.
                event.target.value = '';
            }
        };

        onMounted(async () => {
            scanner = new Html5Qrcode(regionId, { verbose: false });

            // In file mode there is nothing to start, and starting it would
            // ask for camera permission to read a PNG.
            if (props.mode !== 'camera') {
                return;
            }

            try {
                await scanner.start(
                    { facingMode: 'environment' },
                    { fps: 10, qrbox: { width: 260, height: 260 } },
                    (decodedText) => {
                        // Stop before handing the result up: a second frame can
                        // decode the same code and navigate twice.
                        stop().finally(() => emit('scanned', decodedText));
                    },
                    () => {
                        /* Per-frame decode misses are normal and not worth surfacing. */
                    },
                );
            } catch (error) {
                failure.value = 'The camera could not be started. Check that this site is allowed to use it, then reload.';
                emit('error', error);
            } finally {
                starting.value = false;
            }
        });

        onBeforeUnmount(stop);

        return { failure, readFile, reading, regionId, starting };
    },
    template: `
    <div>
        <div v-if="mode === 'camera' && starting" class="flex items-center justify-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-12 text-sm font-semibold text-slate-500">
            <i class="fa-solid fa-camera" aria-hidden="true"></i>Starting the camera…
        </div>

        <label v-if="mode === 'file'" class="block">
            <span class="sr-only">Choose an image of the QR Tracker</span>
            <input
                type="file"
                accept="image/*"
                :disabled="reading"
                class="field file:mr-3 file:rounded-lg file:border-0 file:bg-sky-100 file:px-3 file:py-1.5 file:text-sm file:font-bold file:text-[#07559e]"
                @change="readFile" />
            <span class="mt-2 block text-xs text-slate-500">
                <template v-if="reading">Reading the image…</template>
                <template v-else>A photo or a screenshot of the label works, as long as the whole code is in frame.</template>
            </span>
        </label>

        <p v-if="failure" class="mt-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ failure }}</p>

        <!-- The library needs this element to exist in both modes; in file mode
             it stays empty, and while the camera starts it is hidden. -->
        <div :id="regionId" class="overflow-hidden rounded-xl" :class="{ 'sr-only': mode === 'file' || starting || failure }"></div>
    </div>
`,
});
