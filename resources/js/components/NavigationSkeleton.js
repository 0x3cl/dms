import { router } from '@inertiajs/vue3';
import { defineComponent, onBeforeUnmount, ref } from 'vue';
import { navigationState } from '../navigationState';

const SHOW_AFTER_MS = 150;

/**
 * Placeholder layout shown over the page area while Inertia loads the next
 * page, so a slow navigation reads as loading instead of lag or a blank
 * screen. Filters, search and pagination (partial, state-preserving or
 * scroll-preserving visits) keep the current page and instead turn its
 * table rows into skeleton bars (see the data-fetching rule in app.css).
 */
export default defineComponent({
    name: 'NavigationSkeleton',
    setup() {
        const visible = ref(false);
        let timer = null;

        const stop = () => { clearTimeout(timer); visible.value = false; navigationState.loading = false; delete document.documentElement.dataset.fetching; };
        const offStart = router.on('start', (event) => {
            const visit = event.detail.visit;
            if (visit.method !== 'get' || visit.prefetch) {
                return;
            }
            clearTimeout(timer);
            // Search, filters and pagination keep the page and skeleton its tables instead.
            const keepsPage = visit.preserveState || visit.preserveScroll || visit.only?.length;
            timer = setTimeout(() => {
                if (keepsPage) {
                    document.documentElement.dataset.fetching = '';
                } else {
                    // Admin pages skeleton their own content panel (AdminShell); everything else gets the full overlay.
                    navigationState.loading = true;
                    visible.value = document.querySelector('[data-admin-content]') === null;
                }
            }, SHOW_AFTER_MS);
        });
        const offFinish = router.on('finish', stop);
        onBeforeUnmount(() => { offStart(); offFinish(); stop(); });

        return { visible };
    },
    template: `
    <div v-if="visible" class="absolute inset-0 z-30 overflow-hidden bg-slate-50" role="status" aria-label="Loading page">
        <div class="mx-auto w-full max-w-[1800px] space-y-6 px-4 pt-8 sm:px-6 lg:px-10">
            <div class="animate-pulse space-y-6"><div class="border border-slate-200 bg-white p-5 sm:p-7">
                <div class="h-3 w-32 rounded bg-slate-200"></div>
                <div class="mt-3 h-7 w-2/3 max-w-md rounded bg-slate-200"></div>
                <div class="mt-3 h-4 w-full max-w-lg rounded bg-slate-100"></div>
            </div>
            <div class="border border-slate-200 bg-white p-5 sm:p-7">
                <div class="h-10 w-full rounded-lg bg-slate-100"></div>
                <div class="mt-6 space-y-4">
                    <div v-for="row in 6" :key="row" class="flex items-center gap-4">
                        <div class="h-4 w-1/4 rounded bg-slate-200"></div>
                        <div class="h-4 flex-1 rounded bg-slate-100"></div>
                        <div class="hidden h-4 w-1/6 rounded bg-slate-200 sm:block"></div>
                    </div>
                </div>
            </div>
            </div>
        </div>
    </div>
    `,
});
