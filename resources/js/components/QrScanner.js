import { defineComponent, onBeforeUnmount, onMounted, ref } from 'vue';
import { Html5Qrcode } from 'html5-qrcode';

/**
 * Camera QR reader for the Scan page.
 *
 * html5-qrcode is an npm dependency here, not the unpinned `unpkg.com` tag the
 * Blade page used — which was also loaded twice, once in the layout and again
 * in the page, and never released the camera on navigation. Stopping the
 * stream in onBeforeUnmount is the whole reason this is a component: an
 * Inertia visit unmounts the page without reloading the document, so nothing
 * else would ever turn the camera off.
 *
 * Emits `scanned` with the decoded text. The parent decides what that means —
 * a scan may carry a full tracking URL or a bare code.
 */
export default defineComponent({
    name: 'QrScanner',
    emits: ['scanned', 'error'],
    setup(_props, { emit }) {
        const regionId = 'qr-scanner-region';
        const starting = ref(true);
        const failure = ref('');
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

        onMounted(async () => {
            scanner = new Html5Qrcode(regionId, { verbose: false });

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

        return { failure, regionId, starting };
    },
    template: `
    <div>
        <div v-if="starting" class="flex items-center justify-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-12 text-sm font-semibold text-slate-500">
            <i class="fa-solid fa-camera" aria-hidden="true"></i>Starting the camera…
        </div>
        <p v-if="failure" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ failure }}</p>
        <div :id="regionId" class="overflow-hidden rounded-xl" :class="{ 'sr-only': starting || failure }"></div>
    </div>
`,
});
